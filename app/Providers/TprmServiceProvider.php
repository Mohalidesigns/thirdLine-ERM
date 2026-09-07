<?php

namespace App\Providers;

use App\Models\Tprm\BusinessFunction;
use App\Models\Tprm\Category;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\ThirdParty;
use App\Support\Tprm\RuleEvaluator;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Third-Party Risk Management module.
 *
 * `config/tprm.php` is NOT published from here. This is a first-party module
 * inside the application, not a package: the file is in `config/` already, is
 * version-controlled with the code that reads it, and `engine_version` in it
 * is stamped onto every score run. A publishable copy would let a deployment
 * carry a scoring constant the code has never seen.
 *
 * Policies are registered by Laravel's own discovery, which looks for
 * `App\Policies\{Model}Policy`. The TPRM models live in `App\Models\Tprm`, so
 * discovery would look in `App\Policies\Tprm` — which is where they are put.
 * The mapping is stated below rather than left implicit, because development
 * standard §3 records two separate outages caused by a policy Laravel could
 * not find: an ability written on the wrong policy class returns false for
 * everybody, silently.
 */
class TprmServiceProvider extends ServiceProvider
{
    /**
     * Model to policy, for the guard test to assert against.
     *
     * @var array<class-string, class-string>
     */
    public const POLICIES = [
        ThirdParty::class => \App\Policies\Tprm\ThirdPartyPolicy::class,
        Engagement::class => \App\Policies\Tprm\EngagementPolicy::class,
        Category::class => \App\Policies\Tprm\CategoryPolicy::class,
        BusinessFunction::class => \App\Policies\Tprm\BusinessFunctionPolicy::class,
    ];

    public function register(): void
    {
        // The rule evaluator holds per-evaluation state (`unresolvedFacts`), so
        // it is bound as a transient rather than a singleton. A shared instance
        // would let one screen's unresolved facts leak into another's preview.
        $this->app->bind(RuleEvaluator::class, fn () => new RuleEvaluator);
    }

    public function boot(): void
    {
        // FR-ASM-05's publish gate. Registered as an observer rather than
        // enforced in a Form Request so that the seeder shipping the packs,
        // any clone-and-publish, and the API all meet the same rule.
        \App\Models\Tprm\QuestionnaireTemplate::observe(
            \App\Observers\Tprm\QuestionnaireTemplateObserver::class
        );

        foreach (self::POLICIES as $model => $policy) {
            if (class_exists($policy)) {
                \Illuminate\Support\Facades\Gate::policy($model, $policy);
            }
        }
    }
}
