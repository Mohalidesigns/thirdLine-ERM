<?php

namespace App\Services\Sm;

use App\Models\Sm\Scenario;
use App\Models\Sm\ScenarioCause;
use App\Services\AuditTrailService;
use Illuminate\Support\Collection;

/**
 * The cause checklist's sparse rows (ADR 0027 §A1.4).
 *
 * ONE ROW PER TICKED CAUSE, not 23 pre-materialised rows. The checklist is a const
 * on `ScenarioCause`, so the ABSENCE of a row means "not selected" and unticking
 * DELETES the row — `AuditTrailService` records it, which is the trail that
 * matters. Full materialisation was refused along with the sync-on-save machinery
 * it needs: unlike `kri_return_lines`, nothing here is derived from a due-set that
 * changes under the user.
 *
 * `side` AND `cause_group` ARE DERIVED FROM `code` HERE AND NEVER ACCEPTED FROM A
 * REQUEST. A client posting them is how a row arrives `side = external` with
 * `code = people_other` — a qualitative report that contradicts itself in two
 * columns, on a screen a validator reads to decide what the workshop concluded.
 *
 * There is deliberately no `label` written either: `ScenarioCause::CHECKLIST` is
 * the authority, and a stored copy means a Phase 5 rename updates some rows and not
 * others, so the Excel round-trip exports two spellings of one cause.
 */
class CauseService
{
    /**
     * Replace a scenario's cause rows with the posted selection.
     *
     * @param  array<string, array{note?: string|null, selected?: bool}>  $causes
     *                                                                             keyed by frozen cause code; an absent code is deselected
     */
    public function sync(Scenario $scenario, array $causes): void
    {
        $before = self::selectedCodes($scenario->causes);

        $order = array_keys(ScenarioCause::CHECKLIST);
        $existing = $scenario->causes()->get()->keyBy('code');
        $wanted = [];

        foreach ($causes as $code => $entry) {
            $entry = is_array($entry) ? $entry : ['note' => $entry];

            if (($entry['selected'] ?? true) === false) {
                continue;
            }

            $definition = ScenarioCause::CHECKLIST[$code] ?? null;

            // A code outside the frozen checklist cannot be stored: §A1.8 makes the
            // const the checklist, and the Form Request has already answered 422.
            // Guarded again here because this service is also the write path a
            // Phase 5 importer will call, and an importer that bypasses a Form
            // Request must not be able to invent a cause.
            if ($definition === null) {
                continue;
            }

            $note = isset($entry['note']) ? (string) $entry['note'] : null;
            $note = ($note === null || trim($note) === '') ? null : trim($note);
            $sortOrder = (int) array_search($code, $order, true);

            $wanted[$code] = true;

            $attributes = [
                'side' => $definition[0],
                'cause_group' => $definition[1],
                'note' => $note,
                'is_selected' => true,
                'sort_order' => $sortOrder,
            ];

            if ($existing->has($code)) {
                // fill() + save(): Eloquent issues an UPDATE only when an
                // attribute actually changed, so an untouched row keeps its
                // `updated_at` and the trail stays readable.
                $existing->get($code)->fill($attributes)->save();

                continue;
            }

            ScenarioCause::create(array_merge($attributes, [
                'organization_id' => $scenario->organization_id,
                'sm_scenario_id' => $scenario->id,
                'code' => $code,
            ]));
        }

        $removed = $existing->keys()->diff(array_keys($wanted));

        if ($removed->isNotEmpty()) {
            ScenarioCause::query()
                ->where('sm_scenario_id', $scenario->id)
                ->whereIn('code', $removed->all())
                ->delete();
        }

        $scenario->unsetRelation('causes');

        $after = self::selectedCodes($scenario->causes);

        // One audit row for the selection as a set. Per-row `create`/`delete`
        // events for a checklist a workshop re-ticks in one Save would bury the
        // change that matters — what the workshop concluded — under twenty rows.
        if ($before !== $after) {
            AuditTrailService::record($scenario, 'update', 'causes', $before, $after);
        }
    }

    /**
     * The selected cause codes, in frozen checklist order.
     *
     * `is_selected` is filtered on even though Phase 1 only ever writes `true`:
     * the column exists so "considered and rejected, see note" is expressible
     * (§A1.4), and a reader that counted those rows as selected would report a
     * cause the workshop explicitly ruled out.
     *
     * @param  Collection<int, ScenarioCause>|array<int, ScenarioCause>  $causes
     * @return list<string>
     */
    public static function selectedCodes(Collection|array $causes): array
    {
        $causes = $causes instanceof Collection ? $causes : collect($causes);

        return $causes
            ->filter(fn (ScenarioCause $cause) => (bool) $cause->is_selected)
            ->sortBy(fn (ScenarioCause $cause) => $cause->sort_order)
            ->pluck('code')
            ->values()
            ->all();
    }
}
