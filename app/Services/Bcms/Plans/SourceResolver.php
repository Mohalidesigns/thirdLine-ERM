<?php

namespace App\Services\Bcms\Plans;

use App\Enums\Bcms\DependencyType;
use App\Enums\Bcms\PlanSectionSource;
use App\Models\Bcms\BiaAssessment;
use App\Models\Bcms\CallTree;
use App\Models\Bcms\Dependency;
use App\Models\Bcms\DrSystem;
use App\Models\Bcms\Plan;
use App\Models\Bcms\Process;
use App\Models\Bcms\Site;
use App\Models\Bcms\Strategy;
use App\Models\Tprm\Contact as VendorContact;
use App\Models\Tprm\ThirdParty;
use App\Support\Bcms\PlanBinding;
use App\Support\Rcsa\RcsaScope;

/**
 * Resolves a plan section's binding into the live data it renders.
 *
 * THIS IS WHY THE MODULE EXISTS. Every bank has continuity plans; what none of
 * them have is a plan whose recovery-time table is the BIA rather than a copy
 * of the BIA taken in 2024. A resolver is the difference between a document and
 * a view.
 *
 * EVERY RESOLVE RETURNS A PLAIN, ORDERED ARRAY. Never a model, never a builder,
 * never a collection. The same array is hashed into `source_fingerprint`,
 * rendered into the PDF and written into the offline bundle — so if it were not
 * one deterministic structure, the printed plan, the screen and the phone could
 * disagree during an event, which is the one moment they must not. Ordering is
 * explicit for the same reason: a fingerprint over an unordered result changes
 * when the database feels like returning rows differently, and every plan in
 * the estate would light up as drifted.
 *
 * NOTHING FALLS BACK TO EVERYTHING. A binding whose scope resolves to no
 * processes renders an empty section carrying the REASON it is empty. A
 * departmental plan that silently printed the whole group's recovery objectives
 * would be worse than a blank page, because somebody would act on it.
 *
 * PERSONAL DATA IS RESOLVED, NOT REDACTED, HERE. Contact numbers are what a
 * call cascade is; the NDPA question is who may see the rendered section and
 * whether it may leave the platform, and those are decided by the permission on
 * the route and by `OfflineBundleBuilder`. Deciding it twice, in two places,
 * would mean deciding it inconsistently.
 */
class SourceResolver
{
    public function __construct(private readonly RcsaScope $scope) {}

    /**
     * @return array{
     *   source: string, label: string, rows: list<array<string, mixed>>,
     *   notes: list<string>, empty_reason: ?string, scope: array<string, mixed>
     * }
     */
    public function resolve(Plan $plan, PlanBinding $binding): array
    {
        $payload = match ($binding->source) {
            PlanSectionSource::BiaRto => $this->recoveryObjectives($plan, $binding),
            PlanSectionSource::BiaDependencies => $this->dependencies($plan, $binding),
            PlanSectionSource::Strategy => $this->strategies($plan, $binding),
            PlanSectionSource::CallTree => $this->callTree($plan, $binding),
            PlanSectionSource::CrisisTeamContacts => $this->crisisTeam($plan, $binding),
            PlanSectionSource::AssemblyPoints => $this->sites($plan, $binding),
            PlanSectionSource::CriticalVendors => $this->criticalVendors($plan, $binding),
            PlanSectionSource::DrSystems => $this->drSystems($plan, $binding),
        };

        return [
            'source' => $binding->source->value,
            'label' => $binding->source->label(),
            'rows' => $payload['rows'],
            'notes' => $payload['notes'] ?? [],
            'empty_reason' => $payload['rows'] === [] ? ($payload['empty_reason'] ?? null) : null,
            'scope' => $payload['scope'] ?? [],
        ];
    }

    /**
     * A stable hash of what a binding currently resolves to.
     *
     * SHA-256 over the canonical JSON. The comparison of two of these is the
     * whole of drift detection: no per-source change tracking, no observers on
     * eight upstream tables, and — unlike an `updated_at` watermark — it sees a
     * DELETED dependency and it does not fire on a change that was reverted.
     */
    public function fingerprint(Plan $plan, PlanBinding $binding): string
    {
        return $this->hash($this->resolve($plan, $binding));
    }

    /** @param array<string, mixed> $payload */
    public function hash(array $payload): string
    {
        // `empty_reason` is prose that may be reworded; the rows and the scope
        // are the facts. Hashing the prose would flag every plan in the estate
        // as drifted the day somebody fixes a typo in this file.
        return hash('sha256', (string) json_encode([
            'source' => $payload['source'] ?? null,
            'scope' => $payload['scope'] ?? [],
            'rows' => $payload['rows'] ?? [],
        ], JSON_UNESCAPED_UNICODE));
    }

    /* ------------------------------------------------------------------ */
    /*  Scope */
    /* ------------------------------------------------------------------ */

    /**
     * The processes a binding covers.
     *
     * Explicit ids win. Otherwise the plan's own business unit and everything
     * beneath it — which is what makes the twelve templates reusable, since a
     * template cannot name process ids it has never seen. A plan with neither a
     * unit nor explicit ids is a GROUP plan and covers the organisation; a plan
     * scoped only to a site covers nothing until somebody says which processes
     * run there, because `bcms_processes` is organised by unit, not by building.
     *
     * @return array{ids: list<int>, basis: string, reason: ?string}
     */
    private function processScope(Plan $plan, PlanBinding $binding): array
    {
        $explicit = $binding->processIds();

        if ($explicit !== null) {
            return ['ids' => $explicit, 'basis' => 'explicit', 'reason' => null];
        }

        if ($plan->business_unit_id !== null) {
            $units = $this->scope->subtreeOf((int) $plan->business_unit_id, (int) $plan->organization_id);

            $ids = Process::query()
                ->whereIn('business_unit_id', $units)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            return [
                'ids' => $ids,
                'basis' => 'business_unit',
                'reason' => $ids === []
                    ? 'No processes are recorded against this plan\'s business unit or the units beneath it.'
                    : null,
            ];
        }

        if ($plan->site_id !== null) {
            return [
                'ids' => [],
                'basis' => 'site',
                'reason' => 'This plan is scoped to a site. The process catalogue is organised by business unit, '
                    .'not by building, so the processes this site runs have to be named on the binding.',
            ];
        }

        $ids = Process::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        return [
            'ids' => $ids,
            'basis' => 'organisation',
            'reason' => $ids === [] ? 'The process catalogue is empty.' : null,
        ];
    }

    /**
     * The latest APPROVED assessment per process.
     *
     * Approved, not latest. A plan that quoted a draft would print a number
     * nobody has signed off, and the whole document-control argument of this
     * module is that the version an auditor was shown is the version they were
     * shown.
     *
     * @param  list<int>  $processIds
     * @return array<int, BiaAssessment>
     */
    private function approvedAssessments(array $processIds): array
    {
        if ($processIds === []) {
            return [];
        }

        $assessments = BiaAssessment::query()
            ->whereIn('process_id', $processIds)
            ->where('status', 'approved')
            ->orderBy('approved_at')
            ->orderBy('id')
            ->get();

        $latest = [];

        foreach ($assessments as $assessment) {
            $latest[(int) $assessment->process_id] = $assessment;
        }

        return $latest;
    }

    /* ------------------------------------------------------------------ */
    /*  Resolvers */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function recoveryObjectives(Plan $plan, PlanBinding $binding): array
    {
        $scope = $this->processScope($plan, $binding);
        $assessments = $this->approvedAssessments($scope['ids']);

        $processes = Process::query()
            ->whereIn('id', $scope['ids'])
            ->orderByRaw('COALESCE(criticality_tier, 99)')
            ->orderBy('code')
            ->get();

        $rows = [];
        $unassessed = 0;

        foreach ($processes as $process) {
            $assessment = $assessments[$process->getKey()] ?? null;

            if ($assessment === null) {
                $unassessed++;
            }

            $rows[] = [
                'process_id' => $process->getKey(),
                'code' => $process->code,
                'name' => $process->name,
                'tier' => $process->criticality_tier,
                'is_critical_service' => (bool) $process->is_critical_service,
                // Null, never zero. Printing 0 would claim instant recovery of
                // a process nobody has assessed.
                'mtpd_hours' => $assessment?->mtpd_hours === null ? null : (float) $assessment->mtpd_hours,
                'rto_hours' => $assessment?->rto_hours === null ? null : (float) $assessment->rto_hours,
                'rpo_minutes' => $assessment?->rpo_minutes === null ? null : (int) $assessment->rpo_minutes,
                'mbco' => $assessment?->mbco_description,
                'min_staff_required' => $assessment?->min_staff_required,
                'approved_at' => $assessment?->approved_at?->toDateString(),
                'has_approved_assessment' => $assessment !== null,
            ];
        }

        $notes = [];

        if ($unassessed > 0) {
            $notes[] = $unassessed.' of '.count($rows).' processes in this plan have no approved business impact '
                .'assessment. Their recovery objectives are blank because none have been agreed, not because they '
                .'are zero.';
        }

        return ['rows' => $rows, 'notes' => $notes, 'empty_reason' => $scope['reason'], 'scope' => $scope];
    }

    /** @return array<string, mixed> */
    private function dependencies(Plan $plan, PlanBinding $binding): array
    {
        $scope = $this->processScope($plan, $binding);
        $assessments = $this->approvedAssessments($scope['ids']);

        if ($assessments === []) {
            return [
                'rows' => [],
                'notes' => [],
                'empty_reason' => $scope['reason']
                    ?? 'No process in this plan has an approved business impact assessment, so no dependencies '
                        .'have been agreed.',
                'scope' => $scope,
            ];
        }

        $dependencies = Dependency::query()
            ->whereIn('assessment_id', array_map(fn (BiaAssessment $a) => $a->getKey(), $assessments))
            ->with('assessment.process')
            ->orderBy('dependable_type')
            ->orderBy('dependable_id')
            ->orderBy('id')
            ->get();

        $grouped = [];

        foreach ($dependencies as $dependency) {
            $key = $dependency->dependable_type.':'.$dependency->dependable_id;
            $type = DependencyType::tryFrom((string) $dependency->dependable_type);

            $grouped[$key] ??= [
                'type' => $dependency->dependable_type,
                'type_label' => $type?->label(),
                'id' => (int) $dependency->dependable_id,
                'name' => $dependency->dependableLabel(),
                'single_point_of_failure' => false,
                'alternative_available' => true,
                'criticality' => null,
                'processes' => [],
                'recovery_notes' => [],
            ];

            // A single point of failure for ONE process is a single point of
            // failure. The flag is an OR across the processes, not an AND.
            $grouped[$key]['single_point_of_failure'] = $grouped[$key]['single_point_of_failure']
                || (bool) $dependency->single_point_of_failure;

            // An alternative exists only if EVERY dependent process says so.
            $grouped[$key]['alternative_available'] = $grouped[$key]['alternative_available']
                && (bool) $dependency->alternative_available;

            $grouped[$key]['criticality'] = $this->highestCriticality(
                $grouped[$key]['criticality'],
                $dependency->criticality
            );

            $process = $dependency->assessment?->process;

            if ($process !== null) {
                $grouped[$key]['processes'][(int) $process->getKey()] = $process->code;
            }

            if (filled($dependency->recovery_notes)) {
                $grouped[$key]['recovery_notes'][] = (string) $dependency->recovery_notes;
            }
        }

        $rows = [];

        foreach ($grouped as $entry) {
            $codes = array_values($entry['processes']);
            sort($codes);
            $entry['processes'] = $codes;
            $entry['process_count'] = count($codes);
            $entry['recovery_notes'] = array_values(array_unique($entry['recovery_notes']));
            $rows[] = $entry;
        }

        // Single points of failure first, then breadth of exposure, then name.
        // A dependency list sorted by id is one nobody reads down.
        usort($rows, fn (array $a, array $b) => [$b['single_point_of_failure'], $b['process_count'], $a['name']]
            <=> [$a['single_point_of_failure'], $a['process_count'], $b['name']]);

        $spofs = count(array_filter($rows, fn (array $r) => $r['single_point_of_failure']));
        $notes = $spofs > 0
            ? [$spofs.' of these dependencies are recorded as single points of failure.']
            : [];

        return ['rows' => $rows, 'notes' => $notes, 'empty_reason' => null, 'scope' => $scope];
    }

    /** @return array<string, mixed> */
    private function strategies(Plan $plan, PlanBinding $binding): array
    {
        $scope = $this->processScope($plan, $binding);

        if ($scope['ids'] === []) {
            return ['rows' => [], 'notes' => [], 'empty_reason' => $scope['reason'], 'scope' => $scope];
        }

        $strategies = Strategy::query()
            ->whereIn('process_id', $scope['ids'])
            ->where('approval_status', 'approved')
            ->where('is_selected', true)
            ->with('process')
            ->orderBy('process_id')
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($strategies as $strategy) {
            $rows[] = [
                'process_id' => (int) $strategy->process_id,
                'process_code' => $strategy->process?->code,
                'process_name' => $strategy->process?->name,
                'strategy_type' => $strategy->strategy_type->value,
                'strategy_label' => $strategy->strategy_type->label(),
                'title' => $strategy->title,
                'description' => $strategy->description,
                'rto_achievable_hours' => $strategy->rto_achievable_hours === null
                    ? null : (float) $strategy->rto_achievable_hours,
                'gap_vs_required_hours' => $strategy->gap_vs_required_hours === null
                    ? null : (float) $strategy->gap_vs_required_hours,
                'selection_rationale' => $strategy->selection_rationale,
                'resource_requirements' => $strategy->resource_requirements,
                'approved_at' => $strategy->approved_at?->toDateString(),
            ];
        }

        usort($rows, fn (array $a, array $b) => [$a['process_code'] ?? '', $a['strategy_type']]
            <=> [$b['process_code'] ?? '', $b['strategy_type']]);

        $withGap = array_filter($rows, fn (array $r) => ($r['gap_vs_required_hours'] ?? 0) > 0);

        $notes = $withGap === []
            ? []
            : [count($withGap).' of these strategies cannot meet the recovery time the BIA requires. The shortfall '
                .'is shown per process and is the number an investment case is built on.'];

        $covered = array_unique(array_column($rows, 'process_id'));
        $uncovered = count($scope['ids']) - count($covered);

        if ($uncovered > 0) {
            $notes[] = $uncovered.' of the '.count($scope['ids']).' processes in this plan have no approved, '
                .'selected strategy.';
        }

        return [
            'rows' => $rows,
            'notes' => $notes,
            'empty_reason' => 'No process in this plan has an approved and selected continuity strategy.',
            'scope' => $scope,
        ];
    }

    /** @return array<string, mixed> */
    private function callTree(Plan $plan, PlanBinding $binding): array
    {
        $tree = $this->treeFor($plan, $binding, null);

        if ($tree === null) {
            return [
                'rows' => [],
                'notes' => [],
                'empty_reason' => 'No approved call tree covers this plan\'s part of the organisation. An '
                    .'unapproved tree is deliberately not shown: a cascade nobody has signed off is a list of '
                    .'names, not a plan.',
                'scope' => ['basis' => 'call_tree', 'ids' => []],
            ];
        }

        return $this->treeRows($tree);
    }

    /** @return array<string, mixed> */
    private function crisisTeam(Plan $plan, PlanBinding $binding): array
    {
        $tree = $this->treeFor($plan, $binding, 'crisis_team');

        if ($tree === null) {
            return [
                'rows' => [],
                'notes' => [],
                'empty_reason' => 'No approved crisis team tree exists for this organisation.',
                'scope' => ['basis' => 'call_tree', 'ids' => []],
            ];
        }

        return $this->treeRows($tree);
    }

    private function treeFor(Plan $plan, PlanBinding $binding, ?string $type): ?CallTree
    {
        $explicit = $binding->callTreeId();

        if ($explicit !== null) {
            return CallTree::query()->find($explicit);
        }

        $query = CallTree::query()->where('status', 'approved');

        if ($type !== null) {
            $query->where('tree_type', $type);
        }

        if ($type === null && $plan->business_unit_id !== null) {
            // The plan's own unit first, then an organisation-level tree. A
            // department's tree beats the group's for a department's plan.
            $units = $this->scope->subtreeOf((int) $plan->business_unit_id, (int) $plan->organization_id);
            $query->where(function ($q) use ($units) {
                $q->whereIn('business_unit_id', $units)->orWhereNull('business_unit_id');
            })->orderByRaw('CASE WHEN business_unit_id IS NULL THEN 1 ELSE 0 END');
        }

        return $query->orderByDesc('approved_at')->orderByDesc('id')->first();
    }

    /** @return array<string, mixed> */
    private function treeRows(CallTree $tree): array
    {
        $nodes = $tree->nodes()
            ->with(['contact', 'user'])
            ->orderBy('tier')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($nodes as $node) {
            // BOTH ARE GENUINELY NULLABLE and are annotated so, because
            // `contact_id` and `user_id` are both nullable columns: a node can
            // be a person with a platform login, a contact with none, or a role
            // nobody currently fills. Static analysis reads a `belongsTo` as a
            // guaranteed model, which turns each honest null check below into a
            // complaint — and a complaint is how a null check gets deleted.
            /** @var ?\App\Models\Bcms\Contact $contact */
            $contact = $node->contact;
            /** @var ?\App\Models\User $person */
            $person = $node->user;

            $rows[] = [
                'node_id' => $node->getKey(),
                'tier' => (int) $node->tier,
                'role' => $node->role_label,
                'name' => $this->firstFilled($contact?->full_name, $person?->name),
                'mobile' => $contact?->mobile_primary,
                'email' => $this->firstFilled($contact?->email, $person?->email),
                'primary_channel' => $node->primary_channel,
                'secondary_channel' => $node->secondary_channel,
                'is_must_reach' => (bool) $node->is_must_reach,
                'expected_response_minutes' => (int) $node->expected_response_minutes,
                'has_deputy' => $node->deputy_user_id !== null || $node->deputy_contact_id !== null,
            ];
        }

        $noDeputy = count(array_filter($rows, fn (array $r) => $r['is_must_reach'] && ! $r['has_deputy']));

        $notes = $noDeputy > 0
            ? [$noDeputy.' must-reach people on this tree have no deputy. The person who is unreachable is the '
                .'reason this section exists.']
            : [];

        return [
            'rows' => $rows,
            'notes' => $notes,
            'empty_reason' => 'This call tree has no nodes.',
            'scope' => ['basis' => 'call_tree', 'ids' => [$tree->getKey()], 'version' => $tree->version],
        ];
    }

    /** @return array<string, mixed> */
    private function sites(Plan $plan, PlanBinding $binding): array
    {
        $explicit = $binding->siteIds();
        $query = Site::query()->where('is_active', true);
        $basis = 'organisation';

        if ($explicit !== null) {
            $query->whereIn('id', $explicit);
            $basis = 'explicit';
        } elseif ($plan->site_id !== null) {
            $query->where('id', $plan->site_id);
            $basis = 'plan_site';
        } elseif ($plan->business_unit_id !== null) {
            $units = $this->scope->subtreeOf((int) $plan->business_unit_id, (int) $plan->organization_id);
            $query->whereIn('business_unit_id', $units);
            $basis = 'business_unit';
        }

        $sites = $query->with('recoverySite:id,name,code,address,city')->orderBy('code')->get();

        $rows = [];

        foreach ($sites as $site) {
            $rows[] = [
                'site_id' => $site->getKey(),
                'code' => $site->code,
                'name' => $site->name,
                'site_type' => $site->site_type,
                'address' => $site->address,
                'city' => $site->city,
                'state' => $site->state,
                'headcount' => $site->headcount,
                'is_recovery_site' => (bool) $site->is_recovery_site,
                'recovery_site' => $site->recoverySite === null ? null : [
                    'code' => $site->recoverySite->code,
                    'name' => $site->recoverySite->name,
                    'address' => $site->recoverySite->address,
                    'city' => $site->recoverySite->city,
                ],
            ];
        }

        $withoutRecovery = count(array_filter(
            $rows,
            fn (array $r) => $r['recovery_site'] === null && ! $r['is_recovery_site']
        ));

        $notes = [
            // Said every time, not only when something is missing: a reader who
            // assumes the muster point is in the table below will walk to the
            // wrong car park.
            'The muster point for each site is authored in the text below this table. The site register records '
            .'addresses and recovery locations; it does not hold assembly points, and this section will not '
            .'invent one.',
        ];

        if ($withoutRecovery > 0) {
            $notes[] = $withoutRecovery.' of these sites have no designated recovery location.';
        }

        return [
            'rows' => $rows,
            'notes' => $notes,
            // The reason names the SCOPE that came back empty, not just the
            // fact. "No sites match" sends somebody looking for missing sites;
            // "no site is assigned to this department" sends them to the
            // binding, which is where the fix is.
            'empty_reason' => match ($basis) {
                'explicit' => 'None of the sites named on this section\'s binding is active.',
                'plan_site' => 'The site this plan covers is not marked active in the site register.',
                'business_unit' => 'No active site is recorded against this plan\'s business unit or the units '
                    .'beneath it. Sites are not assigned to a department in the register, so a departmental plan '
                    .'usually has to name the sites it covers on the binding.',
                default => 'The site register has no active sites.',
            },
            'scope' => ['basis' => $basis, 'ids' => array_column($rows, 'site_id')],
        ];
    }

    /**
     * The critical third parties the plan's processes depend on.
     *
     * READ THROUGH `bcms_dependencies`, NOT THROUGH TPRM'S OWN CRITICALITY.
     * TPRM scores a vendor across the whole relationship; what a continuity
     * plan needs is the vendor THIS plan's processes cannot run without, which
     * is a judgement the BIA assessor made per process. A vendor can be
     * strategically important and continuity-irrelevant, and the reverse.
     *
     * @return array<string, mixed>
     */
    private function criticalVendors(Plan $plan, PlanBinding $binding): array
    {
        $scope = $this->processScope($plan, $binding);
        $assessments = $this->approvedAssessments($scope['ids']);

        if ($assessments === []) {
            return [
                'rows' => [],
                'notes' => [],
                'empty_reason' => $scope['reason']
                    ?? 'No process in this plan has an approved business impact assessment, so no vendor '
                        .'dependencies have been agreed.',
                'scope' => $scope,
            ];
        }

        $dependencies = Dependency::query()
            ->whereIn('assessment_id', array_map(fn (BiaAssessment $a) => $a->getKey(), $assessments))
            ->where('dependable_type', DependencyType::Vendors->value)
            ->whereIn('criticality', ['critical', 'high'])
            ->with('assessment.process')
            ->orderBy('dependable_id')
            ->get();

        if ($dependencies->isEmpty()) {
            return [
                'rows' => [],
                'notes' => [],
                'empty_reason' => 'No third party is recorded as a critical or high dependency of this plan\'s '
                    .'processes.',
                'scope' => $scope,
            ];
        }

        $vendorIds = array_values(array_unique($dependencies->pluck('dependable_id')->map(fn ($id) => (int) $id)->all()));

        // A vendor soft-deleted in TPRM leaves the dependency row behind.
        // `DependencyType::isOwnedByBcms()` exists to make that case explicit:
        // the row is shown, named as gone, rather than dropped silently.
        $vendors = ThirdParty::query()->whereIn('id', $vendorIds)->get()->keyBy(fn ($v) => (int) $v->getKey());

        $contacts = VendorContact::query()
            ->whereIn('third_party_id', $vendorIds)
            ->orderBy('third_party_id')
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($c) => (int) $c->third_party_id);

        $rows = [];

        foreach ($dependencies as $dependency) {
            $vendorId = (int) $dependency->dependable_id;
            // Nullable for real: the dependency row outlives a vendor
            // soft-deleted in TPRM, which is the case `is_in_register` below
            // exists to show rather than hide.
            /** @var ?ThirdParty $vendor */
            $vendor = $vendors->get($vendorId);

            $rows[$vendorId] ??= [
                'vendor_id' => $vendorId,
                'name' => $this->firstFilled($vendor?->legal_name, $dependency->dependableLabel()),
                'trading_name' => $vendor?->trading_name,
                'status' => $vendor?->status,
                'is_in_register' => $vendor !== null,
                'criticality' => null,
                'single_point_of_failure' => false,
                'alternative_available' => true,
                'processes' => [],
                'contacts' => array_map(fn ($c) => [
                    'name' => $c->name,
                    'role' => $c->role_type,
                    'email' => $c->email,
                    'phone' => $c->phone,
                ], ($contacts[$vendorId] ?? collect())->all()),
            ];

            $rows[$vendorId]['criticality'] = $this->highestCriticality(
                $rows[$vendorId]['criticality'],
                $dependency->criticality
            );
            $rows[$vendorId]['single_point_of_failure'] = $rows[$vendorId]['single_point_of_failure']
                || (bool) $dependency->single_point_of_failure;
            $rows[$vendorId]['alternative_available'] = $rows[$vendorId]['alternative_available']
                && (bool) $dependency->alternative_available;

            $process = $dependency->assessment?->process;

            if ($process !== null) {
                $rows[$vendorId]['processes'][(int) $process->getKey()] = $process->code;
            }
        }

        $rows = array_values(array_map(function (array $row) {
            $codes = array_values($row['processes']);
            sort($codes);
            $row['processes'] = $codes;
            $row['process_count'] = count($codes);

            return $row;
        }, $rows));

        usort($rows, fn (array $a, array $b) => [$b['single_point_of_failure'], $b['process_count'], $a['name']]
            <=> [$a['single_point_of_failure'], $a['process_count'], $b['name']]);

        $notes = [];
        $missing = count(array_filter($rows, fn (array $r) => ! $r['is_in_register']));
        $noContact = count(array_filter($rows, fn (array $r) => $r['contacts'] === []));

        if ($missing > 0) {
            $notes[] = $missing.' of these third parties are no longer in the vendor register. They are shown '
                .'because the dependency was recorded against them, and a plan that quietly dropped them would '
                .'hide the gap.';
        }

        if ($noContact > 0) {
            $notes[] = $noContact.' of these third parties have no escalation contact recorded. Nobody knows who '
                .'to ring.';
        }

        return ['rows' => $rows, 'notes' => $notes, 'empty_reason' => null, 'scope' => $scope];
    }

    /** @return array<string, mixed> */
    private function drSystems(Plan $plan, PlanBinding $binding): array
    {
        $systems = DrSystem::query()
            ->with(['application:id,name', 'drSite:id,code,name', 'runbook:id,title,version'])
            ->orderByRaw('COALESCE(recovery_tier, 99)')
            ->orderBy('name')
            ->get();

        $rows = [];

        foreach ($systems as $system) {
            $rows[] = [
                'system_id' => $system->getKey(),
                'name' => $system->name,
                'application' => $system->application?->name,
                'recovery_tier' => $system->recovery_tier,
                'rto_target_hours' => $system->rto_target_hours === null ? null : (float) $system->rto_target_hours,
                'rpo_target_minutes' => $system->rpo_target_minutes === null ? null : (int) $system->rpo_target_minutes,
                'dr_strategy' => $system->dr_strategy,
                'dr_site' => $system->drSite?->name,
                'runbook' => $system->runbook === null ? null : [
                    'title' => $system->runbook->title,
                    'version' => $system->runbook->version,
                ],
                'last_test_date' => $system->last_test_date?->toDateString(),
                'last_test_met_objectives' => $system->last_test_met_objectives === null
                    ? null : (bool) $system->last_test_met_objectives,
                'next_test_due' => $system->next_test_due?->toDateString(),
            ];
        }

        $untested = count(array_filter($rows, fn (array $r) => $r['last_test_date'] === null));
        $missed = count(array_filter($rows, fn (array $r) => $r['last_test_met_objectives'] === false));

        $notes = [];

        if ($untested > 0) {
            $notes[] = $untested.' of these systems have never had a recorded failover test.';
        }

        if ($missed > 0) {
            $notes[] = $missed.' of these systems missed their recovery objectives at the last test.';
        }

        return [
            'rows' => $rows,
            'notes' => $notes,
            'empty_reason' => 'No IT recovery systems are recorded.',
            'scope' => ['basis' => 'organisation', 'ids' => array_column($rows, 'system_id')],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * The first of these that has a value, or null.
     *
     * A fallback chain rather than a `??` chain, because `$model?->field ?? …`
     * reads to static analysis as a nullsafe call it believes cannot be null —
     * a `belongsTo` on a nullable foreign key is inferred as a guaranteed model
     * — and the complaint that follows is how somebody eventually deletes the
     * `?->` and gets a fatal on the row where the contact really is missing.
     * This says what the chain means and keeps the null check.
     */
    private function firstFilled(?string ...$values): ?string
    {
        foreach ($values as $value) {
            if (filled($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The higher of two criticalities.
     *
     * One assessor calling a shared dependency Medium does not make it Medium
     * for the process that called it Critical — the same rule
     * `DependencyService::spofRegister()` applies.
     */
    private function highestCriticality(?string $current, mixed $candidate): ?string
    {
        $rank = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
        $candidate = is_string($candidate) ? $candidate : null;

        if ($candidate === null) {
            return $current;
        }

        if ($current === null) {
            return $candidate;
        }

        return ($rank[$candidate] ?? 0) > ($rank[$current] ?? 0) ? $candidate : $current;
    }
}
