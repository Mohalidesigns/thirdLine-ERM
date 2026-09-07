<?php

namespace App\Services\Rcsa;

use App\Models\Rcsa\RcsaImportBatch;
use App\Models\Rcsa\RcsaImportRow;
use App\Models\Rcsa\RcsaRegisterRisk;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only class in the import pipeline that writes to the universe.
 *
 * ONE TRANSACTION. A publish that half-succeeded would leave the user with a
 * universe containing some of their file and a batch that says "published",
 * and no way to tell which rows made it — so they would upload the file again
 * and duplicate whatever landed the first time. All or nothing is the only
 * behaviour that lets someone retry safely.
 *
 * ROWS ARE MERGED BY RISK, NOT WRITTEN ONE PER LINE. The template asks for one
 * row per CONTROL, so a risk with three controls is three rows repeating the
 * same risk text. Writing them as three risks would triple the register on the
 * first upload — this is the single most consequential thing in the file
 * format, and it is why the merge key is the row hash rather than the line
 * number.
 */
class RcsaImportPublisher
{
    /** Create new rows and skip anything matching a published risk. */
    public const MODE_CREATE = 'create';

    /** Update the matched published risk instead of skipping it. */
    public const MODE_UPDATE = 'update';

    /** Create what is new and update what matches. */
    public const MODE_CREATE_UPDATE = 'create_update';

    public function __construct(private readonly RcsaUniverseService $universe) {}

    /**
     * Write a validated batch into the universe.
     *
     * @param  bool  $validOnly  Publish the good rows and leave the errors behind.
     * @return array{created: int, updated: int, skipped: int}
     */
    public function publish(RcsaImportBatch $batch, User $actor, string $mode = self::MODE_CREATE, bool $validOnly = false): array
    {
        if (! $batch->isPublishable()) {
            throw new RuntimeException('This batch has already been published or discarded.');
        }

        if ($batch->hasErrors() && ! $validOnly) {
            throw new RuntimeException(
                'This file still has rows with errors. Correct them, or choose to publish the valid rows only.'
            );
        }

        $result = DB::transaction(function () use ($batch, $actor, $mode) {
            $created = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($this->groupedRows($batch) as $group) {
                $outcome = $this->publishGroup($group, $actor, $mode);

                match ($outcome) {
                    'created' => $created++,
                    'updated' => $updated++,
                    default => $skipped++,
                };
            }

            return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
        });

        $batch->update([
            'status' => RcsaImportBatch::PUBLISHED,
            'created_count' => $result['created'],
            'updated_count' => $result['updated'],
            'skipped_count' => $result['skipped'],
            'published_by' => $actor->id,
            'published_at' => now(),
        ]);

        return $result;
    }

    /**
     * The batch's rows, grouped into the risks they describe.
     *
     * Rows carrying the same hash are one risk with several controls. A row
     * with no hash — because its unit or statement could not be resolved — is
     * its own group and will be skipped; it is never merged into another risk,
     * which would attach a control to a statement the user did not write beside
     * it.
     *
     * @return list<list<RcsaImportRow>>
     */
    private function groupedRows(RcsaImportBatch $batch): array
    {
        $groups = [];
        $ungrouped = [];

        foreach ($batch->rows()->orderBy('row_number')->get() as $row) {
            if ($row->row_hash === null) {
                $ungrouped[] = [$row];

                continue;
            }

            $groups[$row->row_hash][] = $row;
        }

        return array_merge(array_values($groups), $ungrouped);
    }

    /**
     * @param  list<RcsaImportRow>  $group
     * @return 'created'|'updated'|'skipped'
     */
    private function publishGroup(array $group, User $actor, string $mode): string
    {
        $lead = $group[0];

        if ($lead->status === RcsaImportRow::ERROR) {
            return 'skipped';
        }

        $values = $lead->normalised ?? [];
        $controls = $this->controlsFrom($group);

        /* --- An existing published risk -------------------------------- */

        if ($lead->target_id !== null) {
            if ($mode === self::MODE_CREATE) {
                return 'skipped';
            }

            $existing = RcsaRegisterRisk::find($lead->target_id);

            if ($existing === null) {
                // Published between the preview and the publish. Falling
                // through to create is right: the row the user reviewed no
                // longer exists, and losing their line silently would be worse.
                return $this->createFrom($values, $controls, $lead, $actor);
            }

            $this->universe->update(
                risk: $existing,
                attributes: $this->attributesFrom($values, keepRiskNo: $existing->risk_no),
                // Controls are ADDED to what the risk already has, not
                // replaced: an update from a partial file would otherwise
                // delete controls the file simply did not mention.
                controls: $this->mergedControls($existing, $controls),
                actor: $actor,
            );

            $existing->forceFill(['source_batch_id' => $lead->batch_id])->save();

            return 'updated';
        }

        if ($mode === self::MODE_UPDATE) {
            // Update-only mode writes nothing new: the user asked to refresh
            // what is already there, and quietly adding rows they did not ask
            // for is not that.
            return 'skipped';
        }

        return $this->createFrom($values, $controls, $lead, $actor);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<array<string, mixed>>  $controls
     * @return 'created'
     */
    private function createFrom(array $values, array $controls, RcsaImportRow $lead, User $actor): string
    {
        $risk = $this->universe->create(
            attributes: $this->attributesFrom($values),
            controls: $controls,
            actor: $actor,
        );

        $risk->forceFill(['source_batch_id' => $lead->batch_id])->save();

        return 'created';
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function attributesFrom(array $values, ?string $keepRiskNo = null): array
    {
        return [
            'business_unit_id' => $values['business_unit_id'],
            'process_id' => $values['process_id'] ?? null,
            'sub_process_id' => $values['sub_process_id'] ?? null,
            'system_ids' => $values['system_ids'] ?? [],
            // On an update the existing number is kept: it may already appear
            // in a board paper, and renumbering a risk because someone
            // re-uploaded a file would break every reference to it.
            'risk_no' => $keepRiskNo ?? ($values['risk_no'] ?: null),
            'potential_risk' => $values['potential_risk'],
            'risk_driver' => $values['risk_driver'] ?? null,
            'risk_category' => $values['risk_category'] ?? null,
            'secondary_categories' => $values['secondary_categories'] ?? [],
        ];
    }

    /**
     * The controls named across a group's rows.
     *
     * @param  list<RcsaImportRow>  $group
     * @return list<array<string, mixed>>
     */
    private function controlsFrom(array $group): array
    {
        $controls = [];

        foreach ($group as $row) {
            $values = $row->normalised ?? [];
            $description = $values['existing_control'] ?? null;

            if (blank($description)) {
                continue;
            }

            $controls[] = [
                'description' => $description,
                'control_type' => $values['control_type'] ?? null,
                'frequency' => $values['control_frequency'] ?? null,
                'control_owner_id' => $values['control_owner_id'] ?? null,
                'is_key' => false,
            ];
        }

        return $controls;
    }

    /**
     * The risk's existing controls plus any the file adds.
     *
     * Matched on the description, case-insensitively, so re-uploading the same
     * file does not double every control.
     *
     * @param  list<array<string, mixed>>  $incoming
     * @return list<array<string, mixed>>
     */
    private function mergedControls(RcsaRegisterRisk $risk, array $incoming): array
    {
        $existing = $risk->controls->map(fn ($control) => [
            'id' => $control->id,
            'description' => $control->description,
            'control_type' => $control->control_type,
            'frequency' => $control->frequency,
            'control_owner_id' => $control->control_owner_id,
            'is_key' => (bool) $control->is_key,
        ])->all();

        $seen = array_map(
            fn (array $control) => \Illuminate\Support\Str::lower(trim((string) $control['description'])),
            $existing
        );

        foreach ($incoming as $control) {
            $key = \Illuminate\Support\Str::lower(trim((string) $control['description']));

            if (in_array($key, $seen, true)) {
                continue;
            }

            $existing[] = $control;
            $seen[] = $key;
        }

        return $existing;
    }
}
