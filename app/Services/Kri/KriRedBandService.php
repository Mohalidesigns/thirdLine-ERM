<?php

namespace App\Services\Kri;

use App\Exceptions\KriCycleException;
use App\Models\BusinessUnit;
use App\Models\GraphObject;
use App\Models\Issue;
use App\Models\KeyRiskIndicator;
use App\Models\KriReturn;
use App\Models\MeasureBreach;
use App\Models\MeasureValue;
use App\Models\ObjectRelationship;
use App\Models\Period;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Services\KriMeasureBridge;
use App\Services\MeasureService;
use App\Services\PeriodService;
use App\Services\ReferenceCodeService;
use App\Support\Kri\KriBandRule;
use App\Support\Kri\KriWorkingCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * THE ONE WRITER of a red-band explanation (ADR 0026 section 6).
 *
 * A red reading must be explained, and the explanation becomes a root cause on
 * the breach plus an Issue the organisation can chase. Storage, exactly:
 *
 *   root cause      measure_breaches.root_cause (the red breach) and issues.observation
 *   action point    issues.action_plan
 *   timeline        issues.remediation_due_date  (CheckOverdueIssues reads it unchanged)
 *   issue <-> breach  measure_breaches.linked_issue_id  (set when null, NEVER overwritten)
 *   issue <-> KRI   a graph edge, Issue derives_from KeyRiskIndicator
 *
 * REUSE, NEVER DUPLICATE: a KRI has at most one open KRI Issue, found by the
 * graph edge. A reused Issue has its observation, action plan and due date
 * updated; its status, priority and escalation level are untouched.
 *
 * THE EDGE IS FATAL IF IT CANNOT BE WRITTEN. relate() fails soft and returns
 * null; a missing edge would make next month's explanation a second Issue, so
 * this service throws and the caller's whole transaction rolls back.
 *
 * SERIALISATION. Every writer locks the KRI row before openIssueFor(). The
 * champion submit locks the return first and then KRIs in ascending id order -
 * one lock order, no deadlock cycle. The caller OWNS the transaction; every
 * public write asserts there is one.
 */
class KriRedBandService
{
    public const ISSUE_SOURCE = 'kri_breach';

    public const ISSUE_CATEGORY = 'KRI breach';

    public const ESCALATION_PATH = 'kri_department_contacts';

    public function __construct(
        private readonly KriBandRule $bands,
        private readonly KriMeasureBridge $bridge,
        private readonly MeasureService $measures,
        private readonly PeriodService $periods,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Writers */
    /* ------------------------------------------------------------------ */

    /**
     * A red READING (champion submit, or the record-measurement form).
     */
    public function applyToReading(
        KeyRiskIndicator $kri,
        Period $period,
        MeasureValue $value,
        RedBandExplanation $e,
        User $actor,
        ?KriReturn $return = null,
    ): Issue {
        $this->assertInTransaction();
        $this->lock($kri);

        $breach = $this->redBreachForReading($kri, $period, (int) $value->object_id);

        if ($breach !== null) {
            $this->writeRootCause($breach, $e->rootCause);
        }

        $rule = $this->bands->forReading($kri, $period);
        $department = $this->departmentName($kri);

        $issue = $this->upsertIssue(
            kri: $kri,
            e: $e,
            actor: $actor,
            // Champion path: the champion. Risk Officer path (Record): the KRI's owner (A1.2).
            defaultOwnerId: $return !== null ? (int) $actor->id : (int) ($kri->kri_owner_id ?? $kri->owner_id ?? $actor->id),
            title: "KRI {$kri->kri_code} in red — {$department} — {$period->name}",
            description: $this->describeReading($kri, $value, $period, $rule, $return),
        );

        if ($breach !== null && $breach->linked_issue_id === null) {
            $breach->forceFill(['linked_issue_id' => $issue->id])->save();
        }

        return $issue;
    }

    /**
     * A red TARGET on the Edit form. A target is not a reading: no breach is
     * created and no value recorded. The root cause goes onto the KRI's most
     * recent open red breach in any period if there is one; with none, the Issue
     * (and its edge) is the whole attachment.
     */
    public function applyToTarget(KeyRiskIndicator $kri, RedBandExplanation $e, User $actor): Issue
    {
        $this->assertInTransaction();
        $this->lock($kri);

        $breach = $this->latestOpenRedBreach($kri);

        if ($breach !== null) {
            $this->writeRootCause($breach, $e->rootCause);
        }

        $month = $this->periods->resolve(KriWorkingCalendar::today(), 'month', (int) $kri->organization_id);
        $department = $this->departmentName($kri);

        $issue = $this->upsertIssue(
            kri: $kri,
            e: $e,
            actor: $actor,
            defaultOwnerId: (int) ($kri->kri_owner_id ?? $kri->owner_id ?? $actor->id),
            title: "KRI {$kri->kri_code} in red — {$department} — {$month->name}",
            description: sprintf(
                'The target value %s set on KRI %s %s is in the red band. KRI: %s',
                $this->number($kri->target_value),
                $kri->kri_code,
                $kri->name,
                route('risk.kri.show', $kri),
            ),
        );

        if ($breach !== null && $breach->linked_issue_id === null) {
            $breach->forceFill(['linked_issue_id' => $issue->id])->save();
        }

        return $issue;
    }

    /**
     * "The KRI's open KRI Issue", defined here once: the oldest Issue with a
     * `derives_from` edge from its object to the KRI's object, source
     * `kri_breach`, status not CLOSED or CANCELLED, and not deleted.
     * PENDING_CLOSURE and OVERDUE count as open. Plain subqueries, no JSON.
     */
    public function openIssueFor(KeyRiskIndicator $kri): ?Issue
    {
        $kriObjectId = $this->measures->objectIdFor($kri);

        if ($kriObjectId === null) {
            return null;
        }

        $issueObjectIds = ObjectRelationship::query()
            ->ofType('derives_from')
            ->where('to_object_id', $kriObjectId)
            ->select('from_object_id');

        $issueIds = GraphObject::query()
            ->where('source_model_type', 'issue')
            ->whereIn('id', $issueObjectIds)
            ->select('source_model_id');

        return Issue::query()
            ->where('organization_id', $kri->organization_id)
            ->where('issue_source', self::ISSUE_SOURCE)
            ->whereNotIn('issue_status', ['CLOSED', 'CANCELLED'])
            ->whereIn('id', $issueIds)
            ->orderBy('id')
            ->first();
    }

    /**
     * Where a "Raised from KRI breach" panel comes from (ADR 0026 section 8.3):
     * the graph edge, measure_breaches.linked_issue_id and
     * kri_return_lines.issue_id. Null when the Issue has no edge to a KRI.
     *
     * @return array<string, mixed>|null
     */
    public function originFor(Issue $issue): ?array
    {
        $issueObjectId = GraphObject::query()
            ->where('source_model_type', 'issue')
            ->where('source_model_id', $issue->id)
            ->value('id');

        if ($issueObjectId === null) {
            return null;
        }

        $kriObjectIds = ObjectRelationship::query()
            ->ofType('derives_from')
            ->where('from_object_id', $issueObjectId)
            ->pluck('to_object_id');

        $kriId = GraphObject::query()
            ->where('source_model_type', 'key_risk_indicator')
            ->whereIn('id', $kriObjectIds)
            ->value('source_model_id');

        $kri = $kriId === null ? null : KeyRiskIndicator::query()->find($kriId);

        if ($kri === null) {
            return null;
        }

        $breach = MeasureBreach::query()->where('linked_issue_id', $issue->id)->with('period')->orderByDesc('id')->first();
        $line = \App\Models\KriReturnLine::query()->where('issue_id', $issue->id)->with('kriReturn.businessUnit', 'kriReturn.period')->orderByDesc('id')->first();

        return [
            'kri' => [
                'id' => (int) $kri->id,
                'code' => $kri->kri_code,
                'name' => $kri->name,
                'unit' => $kri->unit_of_measure,
                // The read spelling (A1.7): higher_worse / lower_worse.
                'direction' => $kri->threshold_direction === 'lower_worse' ? 'lower_worse' : 'higher_worse',
                'url' => route('risk.kri.show', $kri),
            ],
            'breach' => $breach === null ? null : [
                'id' => (int) $breach->id,
                'period' => $breach->period?->name,
                'value' => $breach->value === null ? null : (float) $breach->value,
                // The threshold in force when it breached; the KRI's red boundary if none was recorded.
                'red_threshold' => $breach->threshold_value !== null ? (float) $breach->threshold_value : $kri->red_threshold,
                'root_cause' => $breach->root_cause,
                'url' => route('risk.kri.breaches'),
            ],
            // With no breach (the Edit-form case) the root cause lives only in the Issue's observation.
            'root_cause' => $breach?->root_cause ?? $issue->observation,
            'return' => $line?->kriReturn === null ? null : [
                'id' => (int) $line->kriReturn->id,
                'department' => $line->kriReturn->businessUnit?->name,
                'period' => $line->kriReturn->period?->name,
                'url' => route('risk.kri.returns.show', $line->kriReturn),
            ],
        ];
    }

    /** The red breach (open or acknowledged) for this reading's measure, object and period. */
    public function redBreachForReading(KeyRiskIndicator $kri, Period $period, int $objectId): ?MeasureBreach
    {
        $measure = $this->bridge->measureFor($kri);

        if ($measure === null) {
            return null;
        }

        return MeasureBreach::query()
            ->where('measure_id', $measure->id)
            ->where('object_id', $objectId)
            ->where('period_id', $period->id)
            ->where('band_to', 'red')
            ->whereIn('status', ['open', 'acknowledged'])
            ->orderByDesc('breached_at')
            ->first();
    }

    /* ------------------------------------------------------------------ */
    /*  Validation helpers (the requests call these) */
    /* ------------------------------------------------------------------ */

    /**
     * Why a timeline is unacceptable, or null. It must not be in the past
     * (>= Lagos today) UNLESS it equals the reused Issue's current due date: a
     * champion may confirm an overdue action still applies without being forced
     * to move its date.
     */
    public function timelineProblem(KeyRiskIndicator $kri, CarbonImmutable $timeline): ?string
    {
        if ($timeline->toDateString() >= KriWorkingCalendar::today()->toDateString()) {
            return null;
        }

        $issue = $this->openIssueFor($kri);

        if ($issue?->remediation_due_date?->toDateString() === $timeline->toDateString()) {
            return null;
        }

        return 'The timeline cannot be in the past.';
    }

    /** Whether moving the reused Issue's due date to $timeline needs a stated reason. */
    public function changeReasonRequired(KeyRiskIndicator $kri, CarbonImmutable $timeline): bool
    {
        $issue = $this->openIssueFor($kri);

        return $issue !== null
            && $issue->remediation_due_date !== null
            && $issue->remediation_due_date->toDateString() !== $timeline->toDateString();
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    private function upsertIssue(
        KeyRiskIndicator $kri,
        RedBandExplanation $e,
        User $actor,
        int $defaultOwnerId,
        string $title,
        string $description,
    ): Issue {
        $existing = $this->openIssueFor($kri);

        if ($existing !== null) {
            return $this->reuse($existing, $e);
        }

        $unit = $kri->business_unit_id === null ? null : BusinessUnit::query()->find($kri->business_unit_id);

        $issue = Issue::query()->create([
            'organization_id' => $kri->organization_id,
            'issue_reference' => ReferenceCodeService::generate('issues', 'issue_reference', 'ISS', 4, (int) $kri->organization_id),
            'title' => mb_substr($title, 0, 255),
            'description' => $description,
            'observation' => $e->rootCause,
            'action_plan' => $e->actionPoint,
            'remediation_due_date' => $e->timeline->toDateString(),
            'responsible_owner_id' => $e->ownerId ?? $defaultOwnerId,
            'business_unit_id' => $unit?->id,
            'department' => $unit?->name,
            'issue_source' => self::ISSUE_SOURCE,
            'issue_category' => self::ISSUE_CATEGORY,
            // Always high, never re-derived when the Issue is reused: nothing
            // produces a "how far into red" number except a developer typing one.
            'priority' => 'high',
            'issue_status' => 'OPEN',
            'escalation_path' => $unit === null ? null : self::ESCALATION_PATH,
            'entity_id' => $kri->entity_id,
            'created_by' => $actor->id,
        ]);

        $edge = $issue->relate('derives_from', $kri, ['relationship_type' => 'kri_breach']);

        if ($edge === null) {
            throw new RuntimeException(
                "Could not link Issue {$issue->issue_reference} to KRI {$kri->kri_code} in the graph; "
                .'refusing to continue because the next explanation would raise a duplicate Issue.'
            );
        }

        AuditTrailService::record($issue, 'create');

        return $issue;
    }

    private function reuse(Issue $issue, RedBandExplanation $e): Issue
    {
        $original = $issue->getAttributes();
        $newDate = $e->timeline->toDateString();
        $dateChanged = $issue->remediation_due_date?->toDateString() !== $newDate;

        if ($dateChanged && $issue->remediation_due_date !== null && $e->timelineChangeReason === null) {
            throw new KriCycleException('A reason is required to change the timeline of the open KRI Issue.');
        }

        $issue->fill([
            'observation' => $e->rootCause,
            'action_plan' => $e->actionPoint,
            'remediation_due_date' => $newDate,
        ]);

        // Only when posted explicitly; otherwise the Issue keeps its owner.
        if ($e->ownerId !== null) {
            $issue->responsible_owner_id = $e->ownerId;
        }

        if ($issue->isDirty()) {
            $issue->save();
            AuditTrailService::recordChanges($issue, $original, $dateChanged ? $e->timelineChangeReason : null);
        }

        return $issue;
    }

    private function latestOpenRedBreach(KeyRiskIndicator $kri): ?MeasureBreach
    {
        $measure = $this->bridge->measureFor($kri);

        if ($measure === null) {
            return null;
        }

        return MeasureBreach::query()
            ->where('measure_id', $measure->id)
            ->where('object_id', $this->measures->objectIdFor($kri))
            ->where('band_to', 'red')
            ->whereIn('status', ['open', 'acknowledged'])
            ->orderByDesc('breached_at')
            ->orderByDesc('id')
            ->first();
    }

    private function writeRootCause(MeasureBreach $breach, string $rootCause): void
    {
        $old = $breach->root_cause;

        if ($old === $rootCause) {
            return;
        }

        $breach->forceFill(['root_cause' => $rootCause])->save();

        AuditTrailService::record($breach, 'update', 'root_cause', $old, $rootCause);
    }

    private function lock(KeyRiskIndicator $kri): void
    {
        KeyRiskIndicator::query()->whereKey($kri->id)->lockForUpdate()->first();
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('KriRedBandService writes must run inside the caller\'s transaction.');
        }
    }

    private function departmentName(KeyRiskIndicator $kri): string
    {
        if ($kri->business_unit_id === null) {
            return 'Unassigned';
        }

        return (string) (BusinessUnit::query()->whereKey($kri->business_unit_id)->value('name') ?? 'Unassigned');
    }

    /**
     * @param  array{direction: string, bands: list<array<string, mixed>>}  $rule
     */
    private function describeReading(KeyRiskIndicator $kri, MeasureValue $value, Period $period, array $rule, ?KriReturn $return): string
    {
        $red = collect($rule['bands'])->firstWhere('code', 'red') ?? [];
        $higher = $rule['direction'] !== 'lower_worse';
        $boundary = $higher ? ($red['min'] ?? null) : ($red['max'] ?? null);

        $parts = [
            sprintf(
                'KRI %s %s was reported at %s for %s, which is in the red band.',
                $kri->kri_code,
                $kri->name,
                $this->number($value->value),
                $period->name,
            ),
            $boundary === null
                ? 'No red boundary is recorded.'
                : sprintf(
                    'Red starts at %s (%s).',
                    $this->number($boundary),
                    $higher ? 'a higher value is worse' : 'a lower value is worse',
                ),
            'KRI: '.route('risk.kri.show', $kri),
        ];

        if ($return !== null) {
            $parts[] = 'Return: '.route('risk.kri.returns.show', $return);
        }

        return implode("\n", $parts);
    }

    private function number(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'not set';
        }

        return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
    }
}
