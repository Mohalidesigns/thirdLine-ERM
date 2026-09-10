<?php

namespace App\Services\Rcsa;

use App\Models\BusinessUnit;
use App\Models\Rcsa\RcsaRegisterControl;
use App\Models\Rcsa\RcsaRegisterRisk;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The write side of the RCSA Universe: creating, duplicating, publishing and
 * bulk-editing master data.
 *
 * The controller stays thin and this holds the three rules that are easy to get
 * subtly wrong — risk numbering, the risk-and-its-controls transaction, and
 * what publishing actually means.
 */
class RcsaUniverseService
{
    /**
     * How many times to retry a create whose generated risk number lost a race.
     *
     * Two risk champions adding to the same unit at the same second both read
     * the same "highest so far" and both generate -R21. The unique index
     * catches the second, and it retries with -R22 rather than showing the user
     * a database error for something they did not do wrong. Three is enough for
     * any plausible amount of concurrency on one business unit; beyond that
     * something else is wrong and the exception should surface.
     */
    private const RISK_NO_ATTEMPTS = 3;

    /**
     * The next risk number for a unit — `{BU_CODE}-R{seq}`.
     *
     * Defect D4: the workbook's Risk No. is free text with no uniqueness, so
     * two branches both file an "R1" and neither can be referenced in a board
     * paper without naming the unit as well. The number is generated, editable,
     * and unique per unit.
     *
     * THE SEQUENCE IS THE HIGHEST EXISTING PLUS ONE, NOT THE ROW COUNT. A unit
     * with risks R1, R2 and R3 that deletes R2 has two rows and a highest of 3;
     * counting would generate R3 and collide. It reads SOFT-DELETED rows too,
     * because the unique index does not ignore them — a deleted R3 still owns
     * that number, and reissuing it would also make an audit trail ambiguous
     * about which R3 a historical entry meant.
     */
    public function nextRiskNo(BusinessUnit $unit): string
    {
        $prefix = Str::upper(trim((string) $unit->code)) ?: 'BU'.$unit->id;

        $highest = RcsaRegisterRisk::withTrashed()
            ->where('business_unit_id', $unit->id)
            ->pluck('risk_no')
            ->map(function (string $riskNo) use ($prefix): int {
                return preg_match('/^'.preg_quote($prefix, '/').'-R(\d+)$/i', $riskNo, $m)
                    ? (int) $m[1]
                    : 0;
            })
            ->max() ?? 0;

        return $prefix.'-R'.($highest + 1);
    }

    /**
     * Create a universe risk and its controls in one transaction.
     *
     * All or nothing, because a risk saved without the controls the user typed
     * beside it is a silently incomplete universe row: it looks finished on the
     * index, and the assessment it seeds shows an empty Existing Control column
     * that nobody can explain three months later.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $controls
     */
    public function create(array $attributes, array $controls, User $actor): RcsaRegisterRisk
    {
        $unit = BusinessUnit::findOrFail($attributes['business_unit_id']);
        $generate = blank($attributes['risk_no'] ?? null);

        for ($attempt = 1; ; $attempt++) {
            if ($generate) {
                $attributes['risk_no'] = $this->nextRiskNo($unit);
            }

            try {
                return DB::transaction(function () use ($attributes, $controls, $actor) {
                    $risk = RcsaRegisterRisk::create($attributes + [
                        'organization_id' => $actor->organization_id,
                        'status' => RcsaRegisterRisk::DRAFT,
                        'created_by' => $actor->id,
                        'updated_by' => $actor->id,
                    ]);

                    $this->syncControls($risk, $controls);

                    return $risk->load('controls');
                });
            } catch (QueryException $e) {
                // Only a generated number is worth retrying. A number the user
                // typed that collides is their input, and they must be told.
                if (! $generate || $attempt >= self::RISK_NO_ATTEMPTS || ! $this->isUniqueViolation($e)) {
                    throw $e;
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>|null  $controls  Null leaves the controls untouched.
     */
    public function update(RcsaRegisterRisk $risk, array $attributes, ?array $controls, User $actor): RcsaRegisterRisk
    {
        return DB::transaction(function () use ($risk, $attributes, $controls, $actor) {
            $risk->fill($attributes);
            $risk->updated_by = $actor->id;

            // An edit to a published row bumps the version, so an assessment
            // line that snapshotted version 2 can say which wording it holds.
            // Editing a draft is not a new version of anything.
            if ($risk->isPublished() && $risk->isDirty($this->materialFields())) {
                $risk->version = (int) $risk->version + 1;
            }

            $risk->save();

            if ($controls !== null) {
                $this->syncControls($risk, $controls);
            }

            return $risk->load('controls');
        });
    }

    /**
     * Copy a risk into another unit or process.
     *
     * The copy is always a DRAFT and always gets a fresh risk number for its
     * destination unit. A duplicate that arrived published would enter the next
     * cycle without anyone approving it, which is the one thing the publish
     * gate exists to prevent.
     */
    public function duplicate(RcsaRegisterRisk $risk, array $destination, User $actor): RcsaRegisterRisk
    {
        $attributes = Arr::only($risk->toArray(), [
            'potential_risk', 'risk_driver', 'risk_category', 'secondary_categories',
            'system_ids', 'default_likelihood', 'default_impact', 'owner_id',
        ]);

        $attributes['business_unit_id'] = $destination['business_unit_id'] ?? $risk->business_unit_id;
        $attributes['process_id'] = $destination['process_id'] ?? null;
        $attributes['sub_process_id'] = $destination['sub_process_id'] ?? null;
        $attributes['risk_no'] = null;

        $controls = $risk->controls->map(fn (RcsaRegisterControl $control) => [
            'description' => $control->description,
            'control_type' => $control->control_type,
            'frequency' => $control->frequency,
            'control_owner_id' => $control->control_owner_id,
            'is_key' => $control->is_key,
        ])->all();

        return $this->create($attributes, $controls, $actor);
    }

    /**
     * Publish rows so that cycles may provision them.
     *
     * A ROW WITH NO CONTROLS IS STILL PUBLISHABLE. It is tempting to block it —
     * a risk with no control is an alarming universe row — but the assessment
     * is exactly where that gap is meant to be found and rated Not Achieved.
     * Blocking here would push users to invent a placeholder control to get
     * past the gate, which is worse than an honest blank.
     *
     * @param  list<int>  $ids
     * @return int The number of rows published.
     */
    public function publish(array $ids, User $actor): int
    {
        return DB::transaction(function () use ($ids, $actor) {
            $risks = RcsaRegisterRisk::whereIn('id', $ids)
                ->whereIn('status', [RcsaRegisterRisk::DRAFT, RcsaRegisterRisk::RETIRED])
                ->get();

            foreach ($risks as $risk) {
                $risk->forceFill([
                    'status' => RcsaRegisterRisk::PUBLISHED,
                    'published_at' => now(),
                    'published_by' => $actor->id,
                    'updated_by' => $actor->id,
                ])->save();
            }

            return $risks->count();
        });
    }

    /**
     * Retire rows: no longer assessed, history preserved.
     *
     * NOT a delete. Assessment lines point back at the register row, and a
     * closed cycle must still be able to say where its lines came from.
     *
     * @param  list<int>  $ids
     */
    public function retire(array $ids, User $actor): int
    {
        return DB::transaction(function () use ($ids, $actor) {
            $risks = RcsaRegisterRisk::whereIn('id', $ids)
                ->where('status', '!=', RcsaRegisterRisk::RETIRED)
                ->get();

            foreach ($risks as $risk) {
                $risk->forceFill([
                    'status' => RcsaRegisterRisk::RETIRED,
                    'updated_by' => $actor->id,
                ])->save();
            }

            return $risks->count();
        });
    }

    /**
     * Set owner, category or status across a selection.
     *
     * Only the three fields the plan names. A general "update these columns on
     * these rows" endpoint is how a bulk action ends up rewriting risk
     * statements by accident; the allow-list is the point, not the convenience.
     *
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $attributes
     */
    public function bulkUpdate(array $ids, array $attributes, User $actor): int
    {
        $attributes = Arr::only($attributes, ['owner_id', 'risk_category', 'status']);

        if ($attributes === []) {
            return 0;
        }

        // Publishing carries a timestamp and an actor, so it goes through the
        // action that records them rather than being a bare column write.
        if (($attributes['status'] ?? null) === RcsaRegisterRisk::PUBLISHED) {
            $published = $this->publish($ids, $actor);
            unset($attributes['status']);

            if ($attributes === []) {
                return $published;
            }
        }

        return DB::transaction(function () use ($ids, $attributes, $actor) {
            $risks = RcsaRegisterRisk::whereIn('id', $ids)->get();

            foreach ($risks as $risk) {
                // fill(), not update(), so the saving hook recomputes row_hash
                // and the model's casts apply.
                $risk->fill($attributes);
                $risk->updated_by = $actor->id;
                $risk->save();
            }

            return $risks->count();
        });
    }

    /**
     * Replace a risk's controls with the submitted set.
     *
     * Rows carrying an id are updated, rows without one are created, and rows
     * the user removed from the repeater are deleted. Soft-deleted, so a
     * control that was mitigating a risk when an assessment snapshotted it can
     * still be resolved.
     *
     * @param  list<array<string, mixed>>  $controls
     */
    private function syncControls(RcsaRegisterRisk $risk, array $controls): void
    {
        $keep = [];

        foreach (array_values($controls) as $index => $row) {
            $attributes = Arr::only($row, [
                'description', 'control_type', 'frequency',
                'control_owner_id', 'is_key', 'control_library_id',
            ]) + ['sort_order' => ($index + 1) * 10];

            $existing = isset($row['id'])
                ? $risk->controls()->whereKey($row['id'])->first()
                : null;

            if ($existing !== null) {
                $existing->fill($attributes)->save();
                $keep[] = $existing->id;

                continue;
            }

            $created = $risk->controls()->create($attributes + [
                'organization_id' => $risk->organization_id,
                'status' => $risk->status,
            ]);

            $keep[] = $created->id;
        }

        $risk->controls()->whereNotIn('id', $keep ?: [0])->get()
            ->each(fn (RcsaRegisterControl $control) => $control->delete());
    }

    /**
     * The fields whose change makes a published row a new version.
     *
     * Everything the assessment snapshots. Changing an owner or a default
     * rating does not rewrite what any historical line says, so it does not
     * bump the version.
     *
     * @return list<string>
     */
    private function materialFields(): array
    {
        return ['potential_risk', 'risk_driver', 'risk_category', 'process_id', 'sub_process_id', 'business_unit_id'];
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // 23000/23505 across MySQL, Postgres and SQLite.
        return in_array((string) $e->getCode(), ['23000', '23505'], true);
    }
}
