<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Presenters\NavPresenter;
use App\Support\Periods\PeriodContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use ThirdLine\Platform\Http\Middleware\HandleInertiaRequests as PlatformMiddleware;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * What THIS product adds to the props every ThirdLine Inertia page receives.
 *
 * The shared half — `auth`, `old`, `flash`, `license` — is the package's, and
 * `auth.user` being id/name/email rather than the model is enforced there
 * rather than remembered here (migration Phase 7.1b). The four props below are
 * this product's own: a tenant, a reporting period, its feature flags and its
 * navigation.
 *
 * Registered in the web group AFTER ResolveTenant and ResolvePeriod, because
 * `tenant` and `period` read what those bind.
 */
class HandleInertiaRequests extends PlatformMiddleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    protected function applicationProps(Request $request): array
    {
        $user = $request->user();

        return [
            // organizationIdOrNull(), never organizationId(): the latter throws,
            // and this middleware runs on public pages too.
            'tenant' => fn () => $this->tenant(),

            'period' => fn () => $this->period(),

            'features' => fn () => array_map(
                fn ($value) => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                (array) config('features', []) + ['sso' => config('sso.enabled', false)]
            ),

            'navigation' => fn () => app(NavPresenter::class)->for($user),

            'unreadNotifications' => fn () => $user
                ? DB::table('notifications_log')->where('user_id', $user->id)->whereNull('read_at')->count()
                : 0,
        ];
    }

    /**
     * @return array{id: int, name: string, code: string|null}|null
     */
    private function tenant(): ?array
    {
        $id = TenantContext::organizationIdOrNull();

        if ($id === null) {
            return null;
        }

        $organization = Organization::query()->find($id);

        if ($organization === null) {
            return null;
        }

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'code' => $organization->short_name,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function period(): ?array
    {
        $period = PeriodContext::current();

        if ($period === null) {
            return null;
        }

        return [
            'id' => $period->id,
            'code' => $period->code,
            'name' => $period->name,
            'type' => $period->type,
            'start_date' => $period->start_date?->toDateString(),
            'end_date' => $period->end_date?->toDateString(),
            'is_closed' => (bool) $period->is_closed,
        ];
    }
}
