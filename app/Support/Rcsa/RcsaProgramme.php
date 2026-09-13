<?php

namespace App\Support\Rcsa;

/**
 * The subject of the RCSA module's abilities (migration Phase 3.5 — 3.8).
 *
 * WHY THIS CLASS EXISTS. Every other Phase 3 module authorises against its own
 * model — `Gate::authorize('viewAny', Control::class)` and so on — because
 * Laravel discovers a policy from the model it is named for. **RCSA has no
 * model.** It is four screens computed over Risk, Control and
 * RiskControlMapping, and one write that lands in campaign_assignments and
 * campaign_responses. There is no `App\Models\Rcsa` and there should not be:
 * inventing an empty model with no table, or hanging `rcsa.view` off
 * CampaignAssignment (which the campaigns module owns and will want to
 * authorise with `campaign.*`), would both be worse than saying plainly that
 * the subject here is the programme itself.
 *
 * `Gate::policy()` accepts any class string, not only Eloquent models, so
 * registering this one against RcsaPolicy in AppServiceProvider gives the RCSA
 * controller the same `Gate::authorize('ability', Subject::class)` shape every
 * other ported controller uses, and lets a test assert the wiring with
 * `Gate::getPolicyFor()`.
 *
 * It holds no state and is never instantiated — it is a name to authorise
 * against.
 */
final class RcsaProgramme
{
    private function __construct() {}
}
