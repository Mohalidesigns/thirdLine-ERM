<?php

namespace App\Http\Controllers\Bcms;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Bcms\DrSystem;
use App\Services\Bcms\Dr\DrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The DR ingestion webhook — Zerto/Veeam/Azure Site Recovery post their own
 * result here (clause map §3.6, ADR 0020 §3, Amendment 1).
 *
 * `POST /api/v1/bcms/dr-tests/ingest/{provider}` — a per-tenant `ApiToken`
 * (scope `bcms.dr.test.record`), NOT a shared per-provider HMAC secret.
 *
 * WHY THE CREDENTIAL CHANGED (Amendment 1). This used to sit in
 * `routes/bcms-webhooks.php`, verified with the same per-provider HMAC as the
 * EMNS gateway callbacks, and resolved its `dr_system_uuid`
 * `withoutGlobalScope(OrganizationScope::class)` — setting `TenantContext`
 * from whatever the payload named. Two defects followed from that: the DR
 * keys lived in the SAME `webhook_secrets` map the EMNS gateways use, so a DR
 * vendor's secret could forge an EMNS roll-call reply; and one global secret
 * per DR provider, shared by every tenant on the deployment, let its holder
 * post results against ANY tenant's DR system, because the tenant was
 * decided by the thing the caller wrote. Phase 7's per-provider-secret scheme
 * is right for a third-party service holding one account for the whole
 * deployment; a DR result is the opposite case — the caller is one tenant's
 * own estate, posting a payload format this product defines — which is
 * exactly what a tenant-bound machine token already exists for.
 *
 * THE TENANT COMES FROM THE TOKEN, NOW, NOT FROM THE PAYLOAD. `api.auth`
 * (`AuthenticateApiToken`) resolves the bearer token and calls
 * `TenantContext::set()` from it BEFORE this controller runs, so
 * `DrSystem::query()` below resolves `dr_system_uuid` inside that tenant's
 * ordinary `OrganizationScope` — no `withoutGlobalScope` call remains. A
 * valid token for one bank presenting another bank's system uuid gets the
 * same 404 an unknown uuid gets.
 *
 * `{provider}` IS DATA, NOT AUTHENTICATION. It is constrained to a known
 * DR-provider list before anything else runs, and stored on the row as half
 * of the `(provider, external_test_id)` idempotency key.
 *
 * `met_objectives` IS NEVER ACCEPTED FROM THE PAYLOAD (clause map §3.6): the
 * ingested row lands with it `null`, awaiting a human's confirmation against
 * our own targets.
 */
class DrIngestionWebhookController extends Controller
{
    /**
     * The only DR providers this product integrates with. A caller naming
     * anything else is refused before a query is even attempted — the same
     * reasoning AlertWebhookController applies to the EMNS gateway list, kept
     * as its own constant here because the two lists must never merge again
     * (that merge is the defect this endpoint's credential change fixes).
     *
     * @var list<string>
     */
    public const KNOWN_PROVIDERS = ['zerto', 'veeam', 'azure-site-recovery'];

    public function __construct(private DrService $dr) {}

    public function ingest(Request $request, string $provider): JsonResponse
    {
        if (! in_array($provider, self::KNOWN_PROVIDERS, true)) {
            return response()->json(['error' => 'Unknown DR provider.'], 422);
        }

        $payload = $request->json()->all();
        $systemUuid = (string) ($payload['dr_system_uuid'] ?? '');
        $externalTestId = (string) ($payload['external_test_id'] ?? '');

        if ($systemUuid === '' || $externalTestId === '') {
            return response()->json(['error' => 'dr_system_uuid and external_test_id are required.'], 422);
        }

        // Never accepted from the provider — the endpoint's own rule.
        unset($payload['met_objectives']);

        // No withoutGlobalScope: api.auth already bound the tenant from the
        // token, so this resolves only within that tenant's own systems.
        $system = DrSystem::query()->where('uuid', $systemUuid)->first();

        if ($system === null) {
            return response()->json(['error' => 'Unknown DR system.'], 404);
        }

        /** @var ApiToken|null $token */
        $token = $request->attributes->get('api_token');

        try {
            $test = $this->dr->ingest($system, $provider, $externalTestId, $payload, $token);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['matched' => true, 'dr_test_id' => $test->getKey()]);
    }
}
