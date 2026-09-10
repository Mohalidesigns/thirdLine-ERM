<?php

namespace App\Services\Rcsa;

use App\Models\BusinessUnit;
use App\Models\CampaignResponse;
use App\Models\Control;
use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\Rcsa\RcsaCycle;
use App\Models\Rcsa\RcsaMethodology;
use App\Models\Rcsa\RcsaRegisterControl;
use App\Models\Rcsa\RcsaRegisterRisk;
use App\Models\Risk;
use App\Support\Rcsa\RcsaMethodologyTemplate as Template;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * §13 step 3: split the legacy module into master data and historical
 * assessments, and put anything unmappable in an exceptions report rather than
 * dropping it.
 *
 * WHAT "LEGACY" MEANS HERE IS NOT WHAT THE PLAN ASSUMED. The old module has no
 * tables of its own — see RcsaLegacyInventory. So this does two different jobs
 * that the plan's wording runs together:
 *
 *   - MASTER DATA is DERIVED, not moved. `rcsa_register_risks` is built from
 *     the enterprise risk register and `rcsa_register_controls` from the
 *     controls mapped to those risks. Nothing is removed from `risks` or
 *     `controls`; they are the enterprise register that half the product reads,
 *     and the v2 universe is a governed view of the operational-risk slice of
 *     it.
 *
 *   - HISTORICAL ASSESSMENTS are RECONSTRUCTED from `campaign_responses` under
 *     `rcsa` campaigns, which is where the legacy worksheet actually filed
 *     submissions. One closed `Legacy` cycle per campaign, per §13.
 *
 * IT IS IDEMPOTENT, AND THAT IS NOT A NICETY. A one-off command that cannot be
 * run twice is one nobody dares run once. Every write is keyed on a legacy id,
 * so a second run skips what the first created and completes what it did not.
 *
 * NOTHING IS SILENTLY DROPPED. A risk with no business unit, a response whose
 * risk was deleted, a campaign with no period — each lands in `exceptions()`
 * with the row's identity and the reason, which is what an operator triages.
 *
 * THE MIGRATED CYCLES ARE CLOSED ON ARRIVAL. A `Legacy` cycle is history, not
 * work: closing it means `acceptsEdits()` is false for every assessment under
 * it, so nothing in the module offers to edit a figure the bank reported years
 * ago. It also means the migration cannot be mistaken for provisioning a live
 * cycle, which is the one way this command could do real damage.
 */
class RcsaLegacyMigrator
{
    /** Prefix for the reconstructed cycles, so they are obvious in every list. */
    public const CYCLE_PREFIX = 'Legacy';

    /**
     * How a legacy 1-5 rating maps onto the v2 control-effectiveness labels.
     *
     * The legacy worksheet stored `control_effectiveness` as free text or a
     * percentage depending on the path that wrote it, so this normalises both
     * and falls back to null rather than guessing. A null control rating makes
     * the line unscored, which is honest: the bank did not record one.
     *
     * @var array<string, string>
     */
    private const CONTROL_EFFECTIVENESS_ALIASES = [
        'fully achieved' => 'Fully Achieved',
        'fully' => 'Fully Achieved',
        'effective' => 'Fully Achieved',
        'mostly achieved' => 'Mostly Achieved',
        'mostly' => 'Mostly Achieved',
        'largely effective' => 'Mostly Achieved',
        'partially achieved' => 'Partially Achieved',
        'partially' => 'Partially Achieved',
        'partially effective' => 'Partially Achieved',
        'not achieved' => 'Not Achieved',
        'not effective' => 'Not Achieved',
        'ineffective' => 'Not Achieved',
        'none' => 'Not Achieved',
    ];

    /** @var list<array<string, mixed>> */
    private array $exceptions = [];

    public function __construct(
        private readonly RcsaLegacyInventory $inventory,
        private readonly RcsaCalculationService $calculator,
    ) {}

    /**
     * Run the whole migration for one tenant.
     *
     * @return array<string, mixed>
     */
    public function migrate(int $organizationId, bool $commit = false): array
    {
        $this->exceptions = [];

        $methodology = $this->calculator->methodology(organizationId: $organizationId);

        if ($methodology === null) {
            throw new RuntimeException(
                'This organisation has no RCSA methodology, so migrated assessments could not be scored. '
                .'Run the RCSA v2 migrations first.'
            );
        }

        // A DRY RUN IS A REAL RUN THAT IS ROLLED BACK. Counting what "would"
        // happen with a second, parallel implementation is how a dry run comes
        // to disagree with the real thing; doing the work and rolling it back
        // means the numbers reported are the numbers that would be written.
        $result = null;

        try {
            DB::transaction(function () use ($organizationId, $methodology, $commit, &$result) {
                $master = $this->migrateMasterData($organizationId);
                $history = $this->migrateHistory($organizationId, $methodology);

                $result = [
                    'organization_id' => $organizationId,
                    'committed' => $commit,
                    'master_data' => $master,
                    'history' => $history,
                    'exceptions' => $this->exceptions,
                    'exception_count' => count($this->exceptions),
                ];

                if (! $commit) {
                    throw new DryRunComplete;
                }
            });
        } catch (DryRunComplete) {
            // Expected: the work is done, the numbers are real, and the
            // transaction is unwound.
        }

        if ($result === null) {
            throw new RuntimeException('The migration produced no result, which should not be reachable.');
        }

        return $result;
    }

    /* ------------------------------------------------------------------ */
    /*  Master data */
    /* ------------------------------------------------------------------ */

    /**
     * The risk register becomes the RCSA Universe.
     *
     * DEDUPLICATED BY `row_hash`, which the model computes on save from the
     * unit, the process and the risk statement. Two register rows saying the
     * same thing about the same process in the same unit are one universe row —
     * which happens in every bank that has run a risk workshop twice.
     *
     * MIGRATED ROWS ARRIVE PUBLISHED. They are the risks the bank has been
     * assessing for years; landing them as drafts would mean somebody
     * re-approving several hundred rows before the first v2 cycle could open,
     * and the approval would be a formality nobody read.
     *
     * @return array<string, int>
     */
    private function migrateMasterData(int $organizationId): array
    {
        $created = $skipped = $controls = 0;

        // Keyed lookups built once: a per-row query would be several thousand
        // on a bank-sized register.
        $existingByLegacy = RcsaRegisterRisk::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNotNull('legacy_risk_id')
            ->pluck('id', 'legacy_risk_id');

        $existingByHash = RcsaRegisterRisk::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->pluck('id', 'row_hash');

        $unitCodes = BusinessUnit::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->pluck('code', 'id');

        $sequences = [];

        foreach (
            Risk::query()->withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->orderBy('id')
                ->cursor() as $risk
        ) {
            if ($existingByLegacy->has($risk->id)) {
                $skipped++;

                continue;
            }

            if ($risk->business_unit_id === null) {
                $this->except('risk', $risk->id, $risk->risk_code ?? (string) $risk->id,
                    'The risk has no business unit. Every RCSA universe row belongs to one; assign it and re-run.');

                continue;
            }

            $statement = trim((string) ($risk->description ?: $risk->title));

            if ($statement === '') {
                $this->except('risk', $risk->id, $risk->risk_code ?? (string) $risk->id,
                    'The risk has neither a description nor a title, so there is no risk statement to migrate.');

                continue;
            }

            $hash = RcsaRegisterRisk::hashFor(
                (int) $risk->business_unit_id,
                $risk->process_id !== null ? (int) $risk->process_id : null,
                null,
                $statement,
            );

            if ($existingByHash->has($hash)) {
                // A duplicate of something already in the universe — either a
                // second register row saying the same thing, or a row somebody
                // typed in before the migration ran. Recorded, not dropped:
                // the operator decides whether the register row was redundant.
                $this->except('risk', $risk->id, $risk->risk_code ?? (string) $risk->id,
                    'The same risk statement already exists in the universe for this unit and process. '
                    .'Migrated as a duplicate rather than a second row.', severity: 'info');

                $skipped++;

                continue;
            }

            $unitId = (int) $risk->business_unit_id;
            $sequences[$unitId] ??= $this->startingSequence($unitId);

            $row = new RcsaRegisterRisk([
                'organization_id' => $organizationId,
                'business_unit_id' => $unitId,
                'process_id' => $risk->process_id,
                'risk_no' => sprintf('%s-R%d', $unitCodes[$unitId] ?? 'BU', ++$sequences[$unitId]),
                'potential_risk' => $statement,
                'risk_driver' => $risk->risk_source,
                'risk_category' => $this->category($risk),
                // The register's own ratings become the universe defaults —
                // a starting point an assessor may change, never an assessment.
                'default_likelihood' => $this->scale($risk->inherent_likelihood),
                'default_impact' => $this->scale($risk->inherent_impact),
                'status' => RcsaRegisterRisk::PUBLISHED,
                'published_at' => $risk->last_assessment_date ?? $risk->created_at ?? now(),
                'owner_id' => $risk->risk_owner_id,
            ]);

            $row->legacy_risk_id = $risk->id;
            $row->save();

            $existingByHash->put($hash, $row->id);
            $created++;

            $controls += $this->migrateControlsFor($risk, $row, $organizationId);
        }

        return ['risks_created' => $created, 'risks_skipped' => $skipped, 'controls_created' => $controls];
    }

    /**
     * The controls mapped to a legacy risk become that universe row's controls.
     */
    private function migrateControlsFor(Risk $risk, RcsaRegisterRisk $row, int $organizationId): int
    {
        $controls = Control::query()
            ->withoutGlobalScopes()
            ->join('risk_control_mapping', 'controls.id', '=', 'risk_control_mapping.control_id')
            ->where('risk_control_mapping.risk_id', $risk->id)
            ->select('controls.*', 'risk_control_mapping.is_key_control')
            ->get();

        $created = 0;

        foreach ($controls as $index => $control) {
            $description = trim((string) ($control->description ?: $control->name));

            if ($description === '') {
                $this->except('control', $control->id, (string) $control->control_code,
                    'The control has neither a description nor a name.');

                continue;
            }

            $registerControl = new RcsaRegisterControl([
                'organization_id' => $organizationId,
                'register_risk_id' => $row->id,
                'control_library_id' => $control->id,
                'description' => $description,
                'control_type' => $this->controlType($control->control_type),
                'frequency' => $this->frequency($control->frequency),
                'control_owner_id' => $control->owner_id,
                // getAttribute, not the property: `is_key_control` comes off
                // the joined pivot rather than the Control model, so it is not
                // a declared attribute and larastan is right to say so.
                'is_key' => (bool) $control->getAttribute('is_key_control'),
                // RcsaRegisterControl has no status constants of its own — it
                // shares the register risk's three-state lifecycle, and the
                // parent row is what governs whether a cycle picks it up.
                'status' => RcsaRegisterRisk::PUBLISHED,
                'sort_order' => $index,
            ]);

            $registerControl->legacy_control_id = $control->id;
            $registerControl->save();

            $created++;
        }

        return $created;
    }

    /* ------------------------------------------------------------------ */
    /*  Historical assessments */
    /* ------------------------------------------------------------------ */

    /**
     * Each legacy campaign becomes one closed `Legacy` cycle.
     *
     * @return array<string, int>
     */
    private function migrateHistory(int $organizationId, RcsaMethodology $methodology): array
    {
        $cycles = $lines = 0;

        foreach ($this->inventory->campaigns($organizationId) as $campaign) {
            $existing = RcsaCycle::query()->withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->where('legacy_campaign_id', $campaign['id'])
                ->first();

            if ($existing !== null) {
                continue;
            }

            if ($campaign['period_start'] === null || $campaign['period_end'] === null) {
                $this->except('campaign', $campaign['id'], (string) $campaign['code'],
                    'The campaign has no start or end date, so there is no period to file its assessments under.');

                continue;
            }

            if ($campaign['responses'] === 0) {
                // Nothing to reconstruct. Not an exception — an empty campaign
                // is a campaign nobody answered, and inventing a cycle for it
                // would put an empty period in every report.
                continue;
            }

            $cycle = $this->buildCycle($organizationId, $campaign, $methodology);
            $lines += $this->buildAssessments($cycle, $campaign, $organizationId, $methodology);
            $cycles++;
        }

        return ['cycles_created' => $cycles, 'lines_created' => $lines];
    }

    private function buildCycle(int $organizationId, array $campaign, RcsaMethodology $methodology): RcsaCycle
    {
        $cycle = new RcsaCycle([
            'organization_id' => $organizationId,
            'name' => sprintf('%s — %s', self::CYCLE_PREFIX, $campaign['title'] ?: $campaign['code']),
            'description' => sprintf(
                'Reconstructed by the P8 migration from legacy campaign %s. Read-only history.',
                $campaign['code'],
            ),
            'period_start' => $campaign['period_start'],
            'period_end' => $campaign['period_end'],
            'methodology_id' => $methodology->id,
            // CLOSED ON ARRIVAL. History, not work — and a closed cycle makes
            // acceptsEdits() false for everything under it, so nothing offers
            // to edit a figure the bank reported years ago.
            'status' => RcsaCycle::CLOSED,
            'closed_at' => now(),
        ]);

        $cycle->legacy_campaign_id = $campaign['id'];
        $cycle->save();

        return $cycle;
    }

    /**
     * One assessment per business unit that answered, and one line per response.
     */
    private function buildAssessments(RcsaCycle $cycle, array $campaign, int $organizationId, RcsaMethodology $methodology): int
    {
        $responses = $this->inventory->responses([$campaign['id']])
            ->with(['assignment:id,campaign_id,business_unit_id,respondent_id', 'risk:id,risk_code,title,description,business_unit_id,process_id,risk_source,category_id'])
            ->orderBy('id')
            ->get();

        $universe = RcsaRegisterRisk::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNotNull('legacy_risk_id')
            ->pluck('id', 'legacy_risk_id');

        $assessments = [];
        $sortOrders = [];
        $lines = 0;

        foreach ($responses as $response) {
            $risk = $response->getRelationValue('risk');
            $unitId = $this->unitFor($response, $risk);

            if ($risk === null) {
                $this->except('campaign_response', $response->id, (string) $response->id,
                    'The response points at a risk that no longer exists, so there is nothing to file it against.');

                continue;
            }

            if ($unitId === null) {
                $this->except('campaign_response', $response->id, (string) $response->id,
                    'Neither the response\'s assignment nor its risk names a business unit.');

                continue;
            }

            $assessments[$unitId] ??= $this->assessmentFor($cycle, (int) $unitId, $organizationId, $response);
            $sortOrders[$unitId] ??= 0;

            $this->buildLine(
                assessment: $assessments[$unitId],
                response: $response,
                risk: $risk,
                registerRiskId: $universe[$risk->id] ?? null,
                methodology: $methodology,
                sortOrder: $sortOrders[$unitId]++,
            );

            $lines++;
        }

        // The stored completion percentage every dashboard reads.
        foreach ($assessments as $assessment) {
            $total = $assessment->lines()->count();
            $scored = $assessment->lines()
                ->whereNotNull('inherent_likelihood')
                ->whereNotNull('inherent_impact')
                ->whereNotNull('control_effectiveness')
                ->count();

            $assessment->forceFill([
                'completion_pct' => $total === 0 ? 0 : (int) floor($scored / $total * 100),
            ])->save();
        }

        return $lines;
    }

    private function assessmentFor(RcsaCycle $cycle, int $unitId, int $organizationId, CampaignResponse $response): RcsaAssessment
    {
        $assessment = new RcsaAssessment([
            'organization_id' => $organizationId,
            'cycle_id' => $cycle->id,
            'business_unit_id' => $unitId,
            // CLOSED, like its cycle. A migrated assessment was filed years ago
            // and never went through this module's workflow; putting it in
            // `validated` would claim an ORM review that never happened.
            'status' => RcsaAssessment::CLOSED,
            'submitted_by' => $response->getRelationValue('assignment')?->respondent_id,
            'submitted_at' => $response->created_at,
        ]);

        $assessment->save();

        return $assessment;
    }

    /**
     * One response becomes one line, scored by the v2 engine.
     *
     * THE FIGURES ARE RECOMPUTED, NOT COPIED. The legacy module scored on its
     * own arithmetic; filing its numbers under a v2 methodology would produce a
     * register whose residuals do not follow from its inputs, and every
     * reconciliation and heat map after that would be reading two engines'
     * output as one. The INPUTS are what migrates — likelihood, impact and the
     * control rating — and RcsaCalculationService derives the rest, exactly as
     * it would for a line typed in today.
     *
     * The consequence is deliberate and must be reported: a migrated residual
     * may differ from what the legacy screen showed. That is what the
     * reconciliation's residual-distribution comparison is FOR.
     */
    private function buildLine(
        RcsaAssessment $assessment,
        CampaignResponse $response,
        Risk $risk,
        ?int $registerRiskId,
        RcsaMethodology $methodology,
        int $sortOrder,
    ): void {
        $data = (array) ($response->questionnaire_data ?? []);

        // The worksheet stored the inherent pair in questionnaire_data and the
        // RESIDUAL pair in the scored columns — see RcsaWorksheetService. Read
        // the inherent one where it exists and fall back to the register's.
        $likelihood = $this->scale($data['inherent_likelihood'] ?? $risk->inherent_likelihood);
        $impact = $this->scale($data['inherent_impact'] ?? $risk->inherent_impact);
        $control = $this->controlEffectiveness($response->control_effectiveness ?? ($data['control_effectiveness'] ?? null));

        $result = $this->calculator->calculate(
            likelihood: $likelihood,
            impact: $impact,
            controlEffectiveness: $control,
            methodology: $methodology,
        );

        $line = new RcsaAssessmentLine([
            'organization_id' => $assessment->organization_id,
            'assessment_id' => $assessment->id,
            'register_risk_id' => $registerRiskId,
            'business_unit_id' => $assessment->business_unit_id,
            'process_id' => $risk->process_id,
            'risk_no' => $risk->risk_code ?? ('LEGACY-'.$risk->id),
            'business_unit_name' => BusinessUnit::query()->withoutGlobalScopes()
                ->whereKey($assessment->business_unit_id)->value('name') ?? 'Unknown unit',
            'potential_risk' => trim((string) ($risk->description ?: $risk->title)) ?: 'Migrated risk '.$risk->id,
            'risk_driver' => $risk->risk_source,
            'risk_category' => $this->category($risk),
            'existing_control' => $response->comments,
            'inherent_likelihood' => $likelihood,
            'inherent_impact' => $impact,
            'control_effectiveness' => $result->controlEffectiveness ?? $control,
            'assessor_id' => $response->getRelationValue('assignment')?->respondent_id,
            'assessed_at' => $response->created_at,
            'assessment_rationale' => $response->comments,
            'methodology_id' => $methodology->id,
            // Locked, like every line under a closed cycle. History is not
            // editable, and this is the column the module actually checks.
            'locked_at' => now(),
            'sort_order' => $sortOrder,
        ]);

        $line->fill($result->toLineColumns());
        $line->legacy_response_id = $response->id;
        $line->save();
    }

    /**
     * The unit a response belongs to: its assignment's, or the risk's.
     *
     * Explicit null checks rather than a `?->` chain — larastan types a
     * `belongsTo` as non-nullable and rejects the nullsafe as dead code, while
     * the relation genuinely is null when it has not been loaded.
     */
    private function unitFor(CampaignResponse $response, ?Risk $risk): ?int
    {
        $assignment = $response->getRelationValue('assignment');

        if ($assignment !== null && $assignment->business_unit_id !== null) {
            return (int) $assignment->business_unit_id;
        }

        return $risk?->business_unit_id === null ? null : (int) $risk->business_unit_id;
    }

    /* ------------------------------------------------------------------ */
    /*  Exceptions */
    /* ------------------------------------------------------------------ */

    /**
     * @return list<array<string, mixed>>
     */
    public function exceptions(): array
    {
        return $this->exceptions;
    }

    private function except(string $type, int $id, string $reference, string $reason, string $severity = 'warning'): void
    {
        $this->exceptions[] = [
            'type' => $type,
            'id' => $id,
            'reference' => $reference,
            'severity' => $severity,
            'reason' => $reason,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Value mapping */
    /* ------------------------------------------------------------------ */

    /**
     * Clamp a legacy rating into the 1-5 scale, or null.
     *
     * NULL RATHER THAN A DEFAULT. A line with no likelihood is an unscored
     * line, which is true; inventing a 3 would put a number the bank never gave
     * into a regulatory return.
     */
    private function scale(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int >= 1 && $int <= 5 ? $int : null;
    }

    /**
     * The legacy control rating as a v2 label, or null when it said nothing
     * this module can read.
     */
    private function controlEffectiveness(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // A percentage, which is how the control library records it.
        if (is_numeric($value)) {
            $pct = (float) $value;

            return match (true) {
                $pct >= 90 => 'Fully Achieved',
                $pct >= 70 => 'Mostly Achieved',
                $pct >= 50 => 'Partially Achieved',
                default => 'Not Achieved',
            };
        }

        $key = mb_strtolower(trim((string) $value));

        return self::CONTROL_EFFECTIVENESS_ALIASES[$key] ?? null;
    }

    /**
     * The legacy category as one of the workbook's thirteen.
     *
     * Anything unrecognised becomes "Others" rather than null: the category is
     * a reporting dimension and an uncategorised risk disappears from every
     * breakdown, which is a worse answer than "Others".
     */
    private function category(Risk $risk): string
    {
        $name = DB::table('risk_categories')->where('id', $risk->category_id)->value('name');

        if ($name === null) {
            return Template::CATEGORY_OTHERS;
        }

        foreach (Template::RISK_CATEGORIES as $category) {
            if (mb_strtolower($category) === mb_strtolower((string) $name)) {
                return $category;
            }
        }

        return Template::CATEGORY_OTHERS;
    }

    private function controlType(?string $type): ?string
    {
        $key = mb_strtolower(trim((string) $type));

        return in_array($key, RcsaRegisterControl::TYPES, true) ? $key : null;
    }

    /**
     * The control library's frequency vocabulary is not the workbook's, so
     * anything outside the module's list becomes null rather than being written
     * through and failing validation later.
     */
    private function frequency(?string $frequency): ?string
    {
        $key = mb_strtolower(trim((string) $frequency));

        return in_array($key, RcsaRegisterControl::FREQUENCIES, true) ? $key : null;
    }

    /**
     * The highest existing risk number in a unit, so migrated rows continue the
     * sequence rather than colliding with rows already there.
     */
    private function startingSequence(int $unitId): int
    {
        $numbers = RcsaRegisterRisk::query()->withoutGlobalScopes()
            ->where('business_unit_id', $unitId)
            ->pluck('risk_no');

        $highest = 0;

        foreach ($numbers as $number) {
            if (preg_match('/R(\d+)$/', (string) $number, $matches)) {
                $highest = max($highest, (int) $matches[1]);
            }
        }

        return $highest;
    }
}
