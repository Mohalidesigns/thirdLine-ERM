<?php

namespace App\Support\Graph;

use App\Models\Control;
use App\Models\KeyRiskIndicator;
use App\Models\LossEvent;
use App\Models\Risk;
use Illuminate\Database\Eloquent\Model;

/**
 * The single authority on which pivot table is which typed edge.
 *
 * Two things write `object_relationships` from the pivots: the one-off
 * backfill (PivotRelationshipMigrator) and the runtime projection that fires
 * whenever a pivot row is written (App\Services\Graph\PivotEdgeProjector, via
 * the ProjectsGraphEdge trait). If those two carried their own copy of "a
 * risk_control_mapping row is a `mitigates` edge from the control to the
 * risk", they would eventually disagree, and the disagreement would look
 * exactly like the bug this map exists to close: a graph that is right for
 * rows written one way and wrong for rows written the other.
 *
 * So the mapping lives here, once, and both consume it.
 *
 * DIRECTION IS PART OF THE MAPPING, not a detail of either consumer. A control
 * mitigates a risk — the control is the actor and therefore the FROM side;
 * 'mitigated_by' is how you read the same edge from the risk.
 *
 * Only the pivots that are a plain (from_id, to_id) pair live here.
 * regulatory_risk_mapping is deliberately absent: its far end is free text
 * that has to be materialised into a Requirement object before anything can
 * point at it, which is the migrator's own job and has no runtime counterpart.
 * near_misses.converted_loss_event_id and treatment_plans.risk_id are columns
 * on a typed table rather than pivots — the models that own them can call
 * relate() directly.
 */
final class PivotEdgeMap
{
    /**
     * table => spec.
     *
     * from/to:      alias      the `objects.source_model_type` of that endpoint
     *               column     the pivot column holding that endpoint's key
     *               model      the Eloquent model, so the runtime hook can load it
     * weight:       the pivot column carrying edge weight, or null for unweighted
     * attributes:   pivot column => cast, copied onto the edge's attributes
     * created_by:   the pivot column naming who asserted the edge, if any
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'risk_control_mapping' => [
                'relationship_code' => 'mitigates',
                'from' => ['alias' => 'control', 'column' => 'control_id', 'model' => Control::class],
                'to' => ['alias' => 'risk', 'column' => 'risk_id', 'model' => Risk::class],
                'weight' => 'control_weight',
                'attributes' => [
                    'is_key_control' => 'bool',
                    'mapping_rationale' => 'string',
                ],
                'created_by' => 'created_by',
            ],

            'risk_related_risks' => [
                'relationship_code' => 'derives_from',
                'from' => ['alias' => 'risk', 'column' => 'risk_id', 'model' => Risk::class],
                'to' => ['alias' => 'risk', 'column' => 'related_risk_id', 'model' => Risk::class],
                'weight' => null,
                'attributes' => [
                    'relationship_type' => 'string',
                    'correlation_strength' => 'string',
                ],
                'created_by' => null,
            ],

            'loss_event_controls' => [
                'relationship_code' => 'failed_control',
                'from' => ['alias' => 'loss_event', 'column' => 'loss_event_id', 'model' => LossEvent::class],
                'to' => ['alias' => 'control', 'column' => 'control_id', 'model' => Control::class],
                'weight' => null,
                'attributes' => [
                    'failure_type' => 'string',
                    'failure_description' => 'string',
                ],
                'created_by' => null,
            ],

            'risk_kri_mapping' => [
                'relationship_code' => 'monitored_by',
                'from' => ['alias' => 'risk', 'column' => 'risk_id', 'model' => Risk::class],
                'to' => ['alias' => 'key_risk_indicator', 'column' => 'kri_id', 'model' => KeyRiskIndicator::class],
                'weight' => null,
                'attributes' => [
                    'correlation_type' => 'string',
                ],
                'created_by' => null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function for(string $table): ?array
    {
        return self::all()[$table] ?? null;
    }

    /**
     * Every relationship code a pivot projects into. Used by anything that has
     * to reason about "edges that came from a pivot" as a set.
     *
     * @return list<string>
     */
    public static function relationshipCodes(): array
    {
        return array_values(array_unique(array_column(self::all(), 'relationship_code')));
    }

    /**
     * The endpoint keys a row asserts, as [from_id, to_id].
     *
     * @param  array<string, mixed>  $spec
     * @return array{0: int|null, 1: int|null}
     */
    public static function endpointKeys(array $spec, object|array $row): array
    {
        $values = self::values($row);

        $read = function (string $column) use ($values): ?int {
            $value = $values[$column] ?? null;

            return $value === null || $value === '' ? null : (int) $value;
        };

        return [$read($spec['from']['column']), $read($spec['to']['column'])];
    }

    /**
     * The edge attributes a pivot row carries.
     *
     * Empty strings and nulls are dropped rather than stored: an edge whose
     * attributes are `{"mapping_rationale": ""}` reads as "somebody wrote a
     * blank rationale", which is not what a NULL column means.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    public static function attributesFrom(array $spec, object|array $row): array
    {
        $values = self::values($row);
        $attributes = [];

        foreach ($spec['attributes'] as $column => $cast) {
            $value = $values[$column] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $attributes[$column] = $cast === 'bool' ? (bool) $value : $value;
        }

        return $attributes;
    }

    /**
     * The weight a pivot row asserts. Unweighted pivots, and weighted ones
     * that left the column null, weigh 1 — "this applies", with no claim
     * about how much of the risk it covers.
     *
     * @param  array<string, mixed>  $spec
     */
    public static function weightFrom(array $spec, object|array $row): float
    {
        if ($spec['weight'] === null) {
            return 1.0;
        }

        $values = self::values($row);

        return (float) ($values[$spec['weight']] ?? 1);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    public static function createdByFrom(array $spec, object|array $row): ?int
    {
        if ($spec['created_by'] === null) {
            return null;
        }

        $value = self::values($row)[$spec['created_by']] ?? null;

        return $value === null ? null : (int) $value;
    }

    /**
     * Rows reach this class three ways — a stdClass from the query builder
     * (the backfill), an Eloquent pivot model (the runtime hook), and a plain
     * array of original attributes (the delete hook, which has to read the
     * values the row held before it went). Normalising here is what lets the
     * readers above be written once.
     *
     * @return array<string, mixed>
     */
    private static function values(object|array $row): array
    {
        if ($row instanceof Model) {
            return $row->getAttributes();
        }

        return is_array($row) ? $row : get_object_vars($row);
    }
}
