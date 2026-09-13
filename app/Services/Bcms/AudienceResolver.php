<?php

namespace App\Services\Bcms;

use App\Exceptions\Bcms\CircularAudienceRuleException;
use App\Models\Bcms\CallTreeNode;
use App\Models\Bcms\Contact;
use App\Models\Bcms\ExerciseParticipant;
use App\Models\Bcms\SavedGroup;
use App\Models\BusinessUnit;
use App\Support\Bcms\AudienceRule;
use Illuminate\Support\Collection;

/**
 * The one resolver for the one audience grammar (ADR 0003).
 *
 * IT RESOLVES TO CONTACTS, NEVER TO USERS. Orchestration §5 states it as a
 * contract and this class is where it is true: a user has a name and a login,
 * a contact has a mobile number and a consent state, and BCMS never queries
 * `users` for a channel. A `role` rule finds users and then finds THEIR
 * contacts; a user with no contact record is not reachable, which is a data
 * hygiene finding rather than a silent omission.
 *
 * RESOLUTION IS A SNAPSHOT. The caller persists the contacts this returns
 * against the alert or reminder. An examiner asking who was told gets the list
 * that was told, not the list the rule would produce today.
 *
 * `none_of` IS APPLIED LAST AND ONLY REMOVES. Every combinator falls back to an
 * empty set rather than to everybody: a rule that matches nobody sends to
 * nobody. Fail closed, always, in a system that can put a message on ten
 * thousand handsets.
 */
class AudienceResolver
{
    /**
     * How many `saved_group` hops a single resolution may traverse.
     *
     * `AudienceRule::MAX_DEPTH` bounds nesting INSIDE one rule document; it does
     * nothing for a chain of saved groups, because each hop into a group starts
     * a fresh `AudienceRule::fromArray()` at depth 0. This is the separate bound
     * for that separate axis — deliberately generous (nobody hand-builds a
     * chain this long) so it only ever fires on a rule that could not
     * legitimately need it.
     */
    public const MAX_GROUP_HOPS = 20;

    /**
     * @return Collection<int, Contact> keyed by contact id
     */
    public function resolve(?AudienceRule $rule): Collection
    {
        if ($rule === null) {
            return collect();
        }

        return $this->contactsFor($this->resolveIds($rule));
    }

    /**
     * The contact ids a rule matches.
     *
     * Ids rather than models all the way down, so an `all_of` across three
     * branches intersects integers instead of hydrating three collections of
     * models to throw most of them away.
     *
     * PUBLIC ENTRY POINT ONLY — this always starts a fresh traversal with an
     * empty visited-group set. Recursion inside this class goes through
     * `resolveWithinTraversal()`, which carries that set forward; nothing here
     * or in a caller should call this method recursively.
     *
     * @return Collection<int, int>
     *
     * @throws CircularAudienceRuleException if the rule re-enters a saved group
     *                                       already on its own resolution stack, or nests saved groups deeper
     *                                       than {@see self::MAX_GROUP_HOPS}.
     */
    public function resolveIds(AudienceRule $rule): Collection
    {
        return $this->resolveWithinTraversal($rule, [], 0);
    }

    /** How many people a rule would reach — for the live recipient count on the console. */
    public function count(?AudienceRule $rule): int
    {
        return $rule === null ? 0 : $this->resolveIds($rule)->count();
    }

    /**
     * The same grammar `resolveIds()` matches, carrying the saved groups
     * already entered on this traversal and how many group hops have been
     * spent so far.
     *
     * @param  list<int>  $visitedGroupIds
     * @return Collection<int, int>
     */
    private function resolveWithinTraversal(AudienceRule $rule, array $visitedGroupIds, int $hops): Collection
    {
        return match ($rule->type()) {
            'all_of' => $this->intersect($rule, $visitedGroupIds, $hops),
            'any_of' => $this->union($rule, $visitedGroupIds, $hops),
            'none_of' => collect(),
            'org_node' => $this->byOrgNode($rule),
            'site' => $this->bySite($rule),
            'role' => $this->byRole($rule),
            'call_tree' => $this->byCallTree($rule),
            'occurrence_participants' => $this->byOccurrence($rule),
            'saved_group' => $this->bySavedGroup($rule, $visitedGroupIds, $hops),
            'geo' => $this->byGeo($rule),
            default => collect(),
        };
    }

    /* ------------------------------------------------------------------ */
    /*  Combinators */
    /* ------------------------------------------------------------------ */

    /**
     * @param  list<int>  $visitedGroupIds
     * @return Collection<int, int>
     */
    private function union(AudienceRule $rule, array $visitedGroupIds, int $hops): Collection
    {
        $ids = collect();

        foreach ($rule->children() as $child) {
            if ($child->type() === 'none_of') {
                continue;
            }

            $ids = $ids->merge($this->resolveWithinTraversal($child, $visitedGroupIds, $hops));
        }

        return $this->applyExclusions($rule, $ids->unique()->values(), $visitedGroupIds, $hops);
    }

    /**
     * @param  list<int>  $visitedGroupIds
     * @return Collection<int, int>
     */
    private function intersect(AudienceRule $rule, array $visitedGroupIds, int $hops): Collection
    {
        $positive = array_values(array_filter(
            $rule->children(),
            fn (AudienceRule $c) => $c->type() !== 'none_of'
        ));

        if ($positive === []) {
            // `all_of` containing only exclusions has no positive set to
            // narrow. It resolves to nobody rather than to everybody.
            return collect();
        }

        $ids = $this->resolveWithinTraversal($positive[0], $visitedGroupIds, $hops);

        foreach (array_slice($positive, 1) as $child) {
            $ids = $ids->intersect($this->resolveWithinTraversal($child, $visitedGroupIds, $hops));

            if ($ids->isEmpty()) {
                break;
            }
        }

        return $this->applyExclusions($rule, $ids->unique()->values(), $visitedGroupIds, $hops);
    }

    /**
     * `none_of` children, applied after everything else.
     *
     * @param  Collection<int, int>  $ids
     * @param  list<int>  $visitedGroupIds
     * @return Collection<int, int>
     */
    private function applyExclusions(AudienceRule $rule, Collection $ids, array $visitedGroupIds, int $hops): Collection
    {
        foreach ($rule->children() as $child) {
            if ($child->type() !== 'none_of') {
                continue;
            }

            foreach ($child->children() as $excluded) {
                $ids = $ids->diff($this->resolveWithinTraversal($excluded, $visitedGroupIds, $hops));
            }
        }

        return $ids->values();
    }

    /* ------------------------------------------------------------------ */
    /*  Leaves */
    /* ------------------------------------------------------------------ */

    /** @return Collection<int, int> */
    private function byOrgNode(AudienceRule $rule): Collection
    {
        $unitIds = collect([(int) $rule->get('id')]);

        if ($rule->get('include_descendants', true)) {
            $unitIds = $this->withDescendants($unitIds);
        }

        return $this->activeContacts()
            ->whereIn('business_unit_id', $unitIds->all())
            ->pluck('id');
    }

    /** @return Collection<int, int> */
    private function bySite(AudienceRule $rule): Collection
    {
        return $this->activeContacts()
            ->whereIn('site_id', array_map('intval', (array) $rule->get('ids', [])))
            ->pluck('id');
    }

    /**
     * Users holding a role, then THEIR contacts.
     *
     * A user with no contact record resolves to nothing here. That is correct
     * and it is also a finding: somebody with an emergency responsibility and
     * no way to be reached is what the contact hygiene report exists to
     * surface (Blueprint §6.4).
     *
     * @return Collection<int, int>
     */
    private function byRole(AudienceRule $rule): Collection
    {
        $names = array_values(array_filter((array) $rule->get('names', []), 'is_string'));

        if ($names === []) {
            return collect();
        }

        $users = \App\Models\User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', $names));

        if ($rule->get('org_node_id') !== null) {
            $unitIds = $this->withDescendants(collect([(int) $rule->get('org_node_id')]));
            $users->whereIn('business_unit_id', $unitIds->all());
        }

        $userIds = $users->pluck('id');

        if ($userIds->isEmpty()) {
            return collect();
        }

        return $this->activeContacts()->whereIn('user_id', $userIds->all())->pluck('id');
    }

    /** @return Collection<int, int> */
    private function byCallTree(AudienceRule $rule): Collection
    {
        $query = CallTreeNode::query()->where('call_tree_id', (int) $rule->get('id'));

        $tiers = array_values(array_filter((array) $rule->get('tiers', []), 'is_numeric'));

        if ($tiers !== []) {
            $query->whereIn('tier', array_map('intval', $tiers));
        }

        return $query->whereNotNull('contact_id')->pluck('contact_id')->unique()->values();
    }

    /** @return Collection<int, int> */
    private function byOccurrence(AudienceRule $rule): Collection
    {
        $query = ExerciseParticipant::query()->where('occurrence_id', (int) $rule->get('id'));

        $roles = array_values(array_filter((array) $rule->get('roles', []), 'is_string'));

        if ($roles !== []) {
            $query->whereIn('role', $roles);
        }

        $direct = $query->whereNotNull('contact_id')->pluck('contact_id');

        // Participants recorded by user rather than contact — the state a
        // participant list is in before Phase 2C resolves it — still have to
        // reach somebody.
        $viaUser = $this->activeContacts()
            ->whereIn('user_id', (clone $query)->whereNull('contact_id')->whereNotNull('user_id')->pluck('user_id')->all())
            ->pluck('id');

        return $direct->merge($viaUser)->unique()->values();
    }

    /**
     * `saved_group` — with a fail-closed guard against re-entering a group
     * already on this traversal's stack.
     *
     * REFUSES THE WHOLE RESOLUTION RATHER THAN THE RE-ENTRANT BRANCH ALONE.
     * `AudienceRule::MAX_DEPTH` bounds nesting inside one stored rule document;
     * it cannot see across a `saved_group` hop, because each hop starts a fresh
     * `AudienceRule::fromArray()` at depth 0. Silently skipping the cycle and
     * returning whatever the rest of the rule matched would hand every caller —
     * the live recipient count on the EMNS console among them — a
     * shorter-than-true audience with nothing to say it was truncated. During a
     * crisis a wrong count that looks right is worse than a visible error, so
     * this throws instead: {@see CircularAudienceRuleException}.
     *
     * @param  list<int>  $visitedGroupIds
     * @return Collection<int, int>
     */
    private function bySavedGroup(AudienceRule $rule, array $visitedGroupIds, int $hops): Collection
    {
        $groupId = (int) $rule->get('id');
        $group = SavedGroup::query()->find($groupId);

        if ($group === null) {
            return collect();
        }

        if ($group->is_dynamic && is_array($group->rule)) {
            if (in_array($groupId, $visitedGroupIds, true)) {
                throw CircularAudienceRuleException::cycle($groupId, $visitedGroupIds);
            }

            $hops++;

            if ($hops > self::MAX_GROUP_HOPS) {
                throw CircularAudienceRuleException::hopLimitExceeded(self::MAX_GROUP_HOPS, [...$visitedGroupIds, $groupId]);
            }

            return $this->resolveWithinTraversal(
                AudienceRule::fromArray($group->rule),
                [...$visitedGroupIds, $groupId],
                $hops,
            );
        }

        return $group->members()->pluck('bcms_contacts.id');
    }

    /**
     * Everyone within a radius.
     *
     * A BOUNDING BOX, NOT A GREAT-CIRCLE DISTANCE IN SQL. A trigonometric
     * distance in the WHERE clause is spelled differently on MySQL and SQLite
     * and cannot use an index on either, so the box narrows in the database and
     * the exact radius is applied in PHP. At 50,000 contacts per tenant
     * (Blueprint §14) the box is the difference between an indexed range scan
     * and a full table scan during a crisis.
     *
     * @return Collection<int, int>
     */
    private function byGeo(AudienceRule $rule): Collection
    {
        $lat = (float) $rule->get('lat');
        $lng = (float) $rule->get('lng');
        $radiusKm = (float) $rule->get('radius_km');

        $latDelta = $radiusKm / 111.0;
        $cos = cos(deg2rad($lat));
        $lngDelta = $cos == 0.0 ? 180.0 : $radiusKm / (111.320 * abs($cos));

        return $this->activeContacts()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [$lat - $latDelta, $lat + $latDelta])
            ->whereBetween('longitude', [$lng - $lngDelta, $lng + $lngDelta])
            ->get(['id', 'latitude', 'longitude'])
            ->filter(fn (Contact $c) => $this->haversineKm($lat, $lng, (float) $c->latitude, (float) $c->longitude) <= $radiusKm)
            ->pluck('id')
            ->values();
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthKm = 6371.0088;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    /** @return \Illuminate\Database\Eloquent\Builder<Contact> */
    private function activeContacts()
    {
        // Consent is NOT filtered here. A withdrawal removes the personal-phone
        // CHANNELS at dispatch, not the person from the audience — somebody who
        // withdrew consent for SMS is still reachable on a corporate email and
        // is still counted in a roll-call. Filtering here would silently drop
        // them from a headcount (ADR 0003).
        return Contact::query()->where('is_active', true);
    }

    /**
     * A unit and everything under it.
     *
     * Iterative rather than a recursive CTE, for the reason `RcsaScope` records:
     * `WITH RECURSIVE` is spelled differently enough across MySQL and SQLite
     * that the version which only runs on the production driver is the version
     * no test can hold.
     *
     * @param  Collection<int, int>  $roots
     * @return Collection<int, int>
     */
    private function withDescendants(Collection $roots): Collection
    {
        $all = $roots->values();
        $frontier = $roots->values();

        for ($depth = 0; $depth < 12 && $frontier->isNotEmpty(); $depth++) {
            $children = BusinessUnit::query()
                ->whereIn('parent_id', $frontier->all())
                ->whereNotIn('id', $all->all())
                ->pluck('id');

            if ($children->isEmpty()) {
                break;
            }

            $all = $all->merge($children);
            $frontier = $children;
        }

        return $all->unique()->values();
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return Collection<int, Contact>
     */
    private function contactsFor(Collection $ids): Collection
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        return Contact::query()->whereIn('id', $ids->all())->get()->keyBy('id');
    }
}
