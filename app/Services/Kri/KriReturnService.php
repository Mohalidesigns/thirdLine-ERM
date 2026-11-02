<?php

namespace App\Services\Kri;

use App\Enums\KriReturnStatus;
use App\Exceptions\KriCycleException;
use App\Models\KeyRiskIndicator;
use App\Models\KriReturn;
use App\Models\KriReturnLine;
use App\Models\MeasureValue;
use App\Models\Period;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Services\KriMeasureBridge;
use App\Services\MeasureService;
use App\Support\Kri\KriBandRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * What a champion does to a return: save a draft, submit, reopen (ADR 0026
 * sections 4, 6.4 and 10).
 *
 * A DRAFT WRITES ONLY TO kri_return_lines. It never reaches the measure engine,
 * so it can never raise a breach or fire KriBreachDetected, and nothing that
 * reads measure_values (kri:check-breaches, the dashboards) ever sees it.
 *
 * SUBMIT IS ONE TRANSACTION: every reading through
 * KriMeasureBridge::recordMeasurement(), every breach root cause, every Issue
 * and graph edge, every line pointer and the return's status. If one KRI fails,
 * nothing is written. MeasureService::announceBreach() defers KriBreachDetected to DB::afterCommit, so a
 * rolled-back submit announces nothing.
 *
 * LOCK ORDER (one, so there is no deadlock cycle): the kri_returns row, then
 * the KRIs in ascending id order. KriRedBandService re-locks each KRI, which is
 * a no-op in the same transaction.
 *
 * `band` on a line is for display and is recomputed on every write; the
 * authoritative band is the engine's.
 */
class KriReturnService
{
    public function __construct(
        private readonly KriCycleService $cycle,
        private readonly KriBandRule $bands,
        private readonly KriMeasureBridge $bridge,
        private readonly KriRedBandService $redBand,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Draft */
    /* ------------------------------------------------------------------ */

    /**
     * @param  list<array<string, mixed>>  $posted  `lines` of the draft body
     */
    public function saveDraft(KriReturn $return, array $posted, User $actor, int $lockVersion): KriReturn
    {
        return DB::transaction(function () use ($return, $posted, $actor, $lockVersion) {
            $return = $this->lockReturn($return);
            $this->assertEditable($return);
            $this->assertVersion($return, $lockVersion);

            $lines = $this->cycle->syncLines($return)->keyBy('id');
            $this->apply($lines, $posted, $actor, enforce: false);
            $this->bump($return);

            return $return;
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Submit */
    /* ------------------------------------------------------------------ */

    /**
     * @param  list<array<string, mixed>>  $posted  `lines` of the submit body
     */
    public function submit(KriReturn $return, array $posted, User $actor, int $lockVersion): KriReturn
    {
        return MeasureService::deferringAnnouncements(fn () => DB::transaction(function () use ($return, $posted, $actor, $lockVersion) {
            $return = $this->lockReturn($return);
            $this->assertEditable($return);
            $this->assertVersion($return, $lockVersion);

            $lines = $this->cycle->syncLines($return)->keyBy('id');

            // The KRIs, locked in ascending id order after the return row.
            $kris = KeyRiskIndicator::query()
                ->whereIn('id', $lines->pluck('key_risk_indicator_id')->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $this->apply($lines, $posted, $actor, enforce: true);

            $ordered = $lines->sortBy('key_risk_indicator_id')->values();

            foreach ($ordered as $line) {
                /** @var KriReturnLine $line */
                $line->refresh();
                $kri = $kris->get($line->key_risk_indicator_id)
                    ?? throw new KriCycleException('A KRI in this return is no longer available.');

                $this->recordLine($return, $line, $kri, $actor);
            }

            $before = $return->status->value;

            $return->forceFill([
                'status' => KriReturnStatus::Submitted->value,
                'submitted_at' => now(),
                'submitted_by' => $actor->id,
                'lock_version' => (int) $return->lock_version + 1,
            ])->save();

            AuditTrailService::record($return, 'return_submitted', 'status', $before, KriReturnStatus::Submitted->value);

            return $return;
        }));
    }

    /* ------------------------------------------------------------------ */
    /*  Reopen */
    /* ------------------------------------------------------------------ */

    /**
     * Allowed while the reporting month's Period and every line's Period are
     * not closed. Risk Officers do not reopen: that would be a second workflow.
     */
    public function reopen(KriReturn $return, User $actor, int $lockVersion, string $reason): KriReturn
    {
        return DB::transaction(function () use ($return, $actor, $lockVersion, $reason) {
            $return = $this->lockReturn($return);
            $this->assertVersion($return, $lockVersion);

            if ($return->status !== KriReturnStatus::Submitted) {
                throw new KriCycleException('Only a submitted return can be reopened.');
            }

            if ($this->periodClosed($return)) {
                throw new KriCycleException('This return cannot be reopened because its reporting period is closed.');
            }

            $return->forceFill([
                'status' => KriReturnStatus::Reopened->value,
                'reopened_at' => now(),
                'reopened_by' => $actor->id,
                'reopen_count' => (int) $return->reopen_count + 1,
                'lock_version' => (int) $return->lock_version + 1,
            ])->save();

            // The reason (10-500 characters) is the audit row's change_reason; no column.
            AuditTrailService::record($return, 'return_reopened', 'status', KriReturnStatus::Submitted->value, KriReturnStatus::Reopened->value, $reason);

            return $return;
        });
    }

    /** True when the reporting month or any line's own period is closed. */
    public function periodClosed(KriReturn $return): bool
    {
        $ids = KriReturnLine::query()->where('kri_return_id', $return->id)->pluck('period_id')->all();
        $ids[] = $return->period_id;

        return Period::query()->whereIn('id', array_unique($ids))->where('is_closed', true)->exists();
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    private function lockReturn(KriReturn $return): KriReturn
    {
        return KriReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Optimistic lock (Amendment 1, A1.8): a 422 on `lock_version`, not a 409 -
     * Inertia treats a 409 as an asset-version reload. Last-write-wins is rejected.
     */
    private function assertVersion(KriReturn $return, int $posted): void
    {
        if ((int) $return->lock_version !== $posted) {
            throw ValidationException::withMessages([
                'lock_version' => 'Someone else saved this return since you opened it. Reload to see their changes; your entries are kept on this page.',
            ]);
        }
    }

    private function bump(KriReturn $return): void
    {
        $return->forceFill(['lock_version' => (int) $return->lock_version + 1])->save();
    }

    private function assertEditable(KriReturn $return): void
    {
        if ($return->isSubmitted()) {
            throw new KriCycleException('This return has already been submitted. Reopen it to make changes.');
        }
    }

    /**
     * Write the posted working values onto the lines, recomputing the display
     * band. Explanation fields are kept only for a red line; posted with any
     * other value they are ignored and not stored.
     *
     * @param  \Illuminate\Support\Collection<int|string, KriReturnLine>  $lines  keyed by line id
     * @param  list<array<string, mixed>>  $posted
     */
    private function apply($lines, array $posted, User $actor, bool $enforce): void
    {
        $kris = KeyRiskIndicator::query()
            ->whereIn('id', $lines->pluck('key_risk_indicator_id')->all())
            ->get()
            ->keyBy('id');

        $periods = Period::query()->whereIn('id', $lines->pluck('period_id')->unique()->all())->get()->keyBy('id');
        $seen = [];

        foreach ($posted as $row) {
            $line = $lines->get((int) ($row['line_id'] ?? 0));

            if ($line === null) {
                throw new KriCycleException('A posted line does not belong to this return.');
            }

            $seen[(int) $line->id] = true;
            $kri = $kris->get($line->key_risk_indicator_id);
            $period = $periods->get($line->period_id);

            $value = $row['value'] ?? null;
            $value = $value === '' ? null : $value;

            $band = $value === null || $kri === null || $period === null
                ? null
                : $this->bands->bandForReading($kri, $period, (float) $value);

            $explanation = $band === 'red' ? ($row['red_band'] ?? []) : [];

            $line->forceFill([
                'value' => $value,
                'comment' => isset($row['comment']) && $row['comment'] !== '' ? $row['comment'] : null,
                'band' => $band,
                'root_cause' => $explanation['root_cause'] ?? null,
                'action_point' => $explanation['action_point'] ?? null,
                'timeline' => $explanation['timeline'] ?? null,
                'action_owner_id' => $explanation['owner_id'] ?? null,
                'timeline_change_reason' => $explanation['timeline_change_reason'] ?? null,
                'saved_by' => $actor->id,
                'saved_at' => now(),
            ])->save();
        }

        if ($enforce) {
            foreach ($lines as $line) {
                if (! isset($seen[(int) $line->id]) && $line->value === null) {
                    throw new KriCycleException('Every KRI due in this return needs a value before it can be submitted.');
                }
            }
        }
    }

    /**
     * One line at submit: the reading through the engine, then the explanation
     * if the ENGINE says red.
     */
    private function recordLine(KriReturn $return, KriReturnLine $line, KeyRiskIndicator $kri, User $actor): void
    {
        if ($line->value === null) {
            throw new KriCycleException("KRI {$kri->kri_code} needs a value before the return can be submitted.");
        }

        $period = Period::query()->findOrFail($line->period_id);

        if ($period->is_closed) {
            throw new KriCycleException("Period {$period->name} is closed, so KRI {$kri->kri_code} cannot be recorded.");
        }

        // A resubmitted value that moved leaves an audit row with old and new.
        $previous = $line->measure_value_id === null
            ? null
            : MeasureValue::query()->whereKey($line->measure_value_id)->value('value');

        $original = $kri->getAttributes();

        try {
            $result = $this->bridge->recordMeasurement(
                $kri,
                CarbonImmutable::parse($period->end_date->toDateString()),
                (float) $line->value,
                ['notes' => $line->comment, 'entered_by' => $actor->id],
            );
        } catch (RuntimeException $e) {
            throw new KriCycleException($e->getMessage(), 0, $e);
        }

        AuditTrailService::recordChanges($kri, $original);

        if ($previous !== null && (float) $previous !== (float) $line->value) {
            AuditTrailService::record($return, 'value_changed', 'kri:'.$kri->kri_code, (string) $previous, (string) $line->value);
        }

        /** @var MeasureValue $measureValue */
        $measureValue = $result['value'];
        $band = $result['band'];

        $updates = [
            'measure_value_id' => $measureValue->id,
            'band' => $band,
        ];

        if ($band === 'red') {
            $explanation = RedBandExplanation::fromPayload([
                'root_cause' => $line->root_cause,
                'action_point' => $line->action_point,
                'timeline' => $line->timeline?->toDateString(),
                'owner_id' => $line->action_owner_id,
                'timeline_change_reason' => $line->timeline_change_reason,
            ]);

            if ($explanation === null) {
                throw new KriCycleException("KRI {$kri->kri_code} is in the red band and needs a root cause, an action point and a timeline.");
            }

            $issue = $this->redBand->applyToReading($kri, $period, $measureValue, $explanation, $actor, $return);
            $breach = $this->redBand->redBreachForReading($kri, $period, (int) $measureValue->object_id);

            $updates['issue_id'] = $issue->id;
            $updates['measure_breach_id'] = $breach?->id;
        } else {
            // Posted with a non-red value, an explanation is ignored and not stored.
            $updates += [
                'root_cause' => null,
                'action_point' => null,
                'timeline' => null,
                'action_owner_id' => null,
                'timeline_change_reason' => null,
            ];
        }

        $line->forceFill($updates)->save();
    }
}
