<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\ApiResourceRegistry;
use App\Http\Api\QueryShaper;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Concerns\ScopedToGraph;
use App\Services\ReferenceCodeService;
use App\Support\Authorization\GraphScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * WP-07 TASK 2 — one controller over the whole object model.
 *
 * Every resource in ApiResourceRegistry is served from here. Twenty
 * near-identical controllers would be twenty places for the tenancy check, the
 * field allowlist and the scope check to drift apart — and the one that drifts
 * is the one nobody notices until it is serving another tenant's loss events.
 *
 * TENANCY IS NOT THIS CLASS'S JOB and that is deliberate: every model in the
 * registry carries BelongsToOrganization, so the global scope filters the query
 * before it is built. The explicit check in show()/update() is belt to that
 * brace, for the case where a model is added to the registry without the trait.
 *
 * NODE SCOPING IS this class's job, because it is not a global scope — it
 * cannot be, or every roll-up and background job would inherit it. Every read
 * therefore starts from baseQuery(), which derives the scope from the model's
 * traits; see the note there for why that is not a registry flag.
 */
class ResourceController extends Controller
{
    /**
     * List records of a resource type.
     *
     * Supports `filter[...]`, `sort`, `fields[...]`, `include` and cursor
     * pagination. Anything asked for that the resource does not publish is
     * IGNORED and reported in `meta.ignored`, rather than silently dropped —
     * a client whose filter did nothing should be told, not handed the whole
     * table and left to assume it worked.
     */
    public function index(Request $request, string $resource): JsonResponse
    {
        [$definition, $shaper] = $this->resolve($resource);

        $query = $this->baseQuery($definition);
        $shaper->apply($query, $request);

        $page = $shaper->paginate($query, $request);
        $fields = $shaper->fieldsFor($resource, $request);

        return response()->json([
            'data' => collect($page->items())->map(
                fn (Model $model) => $this->present($model, $resource, $fields, $request)
            )->all(),
            'links' => $this->links($page),
            'meta' => array_filter([
                'per_page' => $page->perPage(),
                'total' => method_exists($page, 'total') ? $page->total() : null,
                'ignored' => $shaper->ignored() ?: null,
            ], fn ($value) => $value !== null),
        ]);
    }

    public function show(Request $request, string $resource, string $id): JsonResponse
    {
        [$definition, $shaper] = $this->resolve($resource);

        $query = $this->baseQuery($definition);
        $shaper->apply($query, $request);

        $model = $query->find($id);

        if ($model === null) {
            return $this->notFound($resource, $id);
        }

        $this->assertSameTenant($model);

        return response()->json([
            'data' => $this->present($model, $resource, $shaper->fieldsFor($resource, $request), $request),
        ]);
    }

    public function store(Request $request, string $resource): JsonResponse
    {
        [$definition] = $this->resolve($resource, 'create');

        $writable = (array) ($definition['writable'] ?? []);

        if ($writable === []) {
            return $this->readOnly($resource);
        }

        $attributes = $this->validated($request, $writable, required: true);

        // The reference code is generated, not accepted. It is the identifier
        // an examiner cites, so a client must not be able to choose it — and
        // the column is NOT NULL, so without this every POST fails.
        if (isset($definition['reference'])) {
            $attributes[$definition['reference']['column']] = ReferenceCodeService::generate(
                (new $definition['model'])->getTable(),
                $definition['reference']['column'],
                $definition['reference']['prefix'],
            );
        }

        if (in_array('created_by', (new $definition['model'])->getFillable(), true)) {
            $attributes['created_by'] = $request->user()?->id;
        }

        // organization_id is stamped by BelongsToOrganization from the tenant
        // the TOKEN resolved — never from the payload, so there is no field a
        // client can send that writes into another organization.
        $model = $definition['model']::create($attributes);

        return response()->json([
            'data' => $this->present($model->fresh(), $resource, (array) $definition['fields'], $request),
        ], 201);
    }

    public function update(Request $request, string $resource, string $id): JsonResponse
    {
        [$definition] = $this->resolve($resource, 'edit');

        $writable = (array) ($definition['writable'] ?? []);

        if ($writable === []) {
            return $this->readOnly($resource);
        }

        $model = $this->baseQuery($definition)->find($id);

        if ($model === null) {
            return $this->notFound($resource, $id);
        }

        $this->assertSameTenant($model);

        $model->update($this->validated($request, $writable, required: false));

        return response()->json([
            'data' => $this->present($model->fresh(), $resource, (array) $definition['fields'], $request),
        ]);
    }

    /**
     * Everything the API serves, and what a token needs to read it.
     *
     * Deliberately unauthenticated beyond a valid token: an integrator has to
     * be able to discover the surface before they know which scopes to ask for.
     */
    public function catalogue(Request $request): JsonResponse
    {
        /** @var ApiToken|null $token */
        $token = $request->attributes->get('api_token');

        return response()->json([
            'data' => collect(ApiResourceRegistry::all())->map(fn (array $definition, string $name) => [
                'type' => $name,
                'scopes' => $definition['permissions'],
                // Whether THIS token can read it, so an integrator does not
                // have to discover their gaps one 403 at a time.
                'readable' => $token?->permits($definition['permissions']['view']) ?? false,
                'writable' => ($definition['writable'] ?? []) !== []
                    && isset($definition['permissions']['create']),
                'filters' => $definition['filters'] ?? [],
                'sorts' => $definition['sorts'] ?? [],
                'includes' => $definition['includes'] ?? [],
                'fields' => $definition['fields'] ?? [],
            ])->values()->all(),
        ]);
    }

    /* ================================================================== */

    /**
     * The starting query for a resource, tenanted and node-scoped.
     *
     * WP-00 NODE SCOPING, APPLIED HERE RATHER THAN IN THE REGISTRY. The
     * registry declares a resource's model and nothing about how to read it;
     * adding a `scoped => true` flag there would mean every future resource is
     * scoped only if somebody remembers the flag, and the one nobody remembers
     * is the one that serves another branch's loss events. Deriving it from the
     * model's own traits instead makes scoping the DEFAULT: a resource is node
     * scoped exactly when its model carries ScopedToGraph, which is the same
     * fact the web grids and exports read, so the API cannot drift from them.
     *
     * Currently that is risks, controls, kris, issues and loss-events. A
     * resource added next quarter whose model carries the trait is scoped the
     * moment it is registered, with no entry in this file.
     *
     * WHOSE SCOPE. AuthenticateApiToken calls auth()->setUser() for a token
     * with an acting user, so visibleTo() resolves that user's pin — a
     * personal token cannot read past its owner's subtree. A machine token has
     * no user, GraphScope::isSubtreeLimited(null) is false, and the query stays
     * organization-wide. That is the existing contract for integration tokens
     * (a nightly reconciliation feed has to see the whole book) and this change
     * does not narrow it; a machine token's blast radius is bounded by the
     * permissions on the token itself.
     *
     * @param  array<string, mixed>  $definition
     */
    private function baseQuery(array $definition): \Illuminate\Database\Eloquent\Builder
    {
        $query = $definition['model']::query();

        if (in_array(ScopedToGraph::class, class_uses_recursive($definition['model']), true)) {
            return $query->visibleTo();
        }

        // A child of a scoped model — a treatment plan, an assessment, a
        // control test — has no node of its own, so which relation carries its
        // visibility cannot be derived and the registry names it. This is the
        // one case that has to be remembered, and it is remembered next to the
        // model rather than here.
        if (isset($definition['scope_through'])) {
            GraphScope::applyThrough($query, $definition['scope_through']);
        }

        return $query;
    }

    /**
     * @return array{0: array<string, mixed>, 1: QueryShaper}
     */
    private function resolve(string $resource, string $action = 'view'): array
    {
        $definition = ApiResourceRegistry::find($resource);

        abort_if($definition === null, 404, "There is no [{$resource}] resource.");

        // EnsureResourceScope has already applied the permission for this
        // method. Re-checked here anyway: this controller is reachable from
        // four routes, and a route added later without the middleware would
        // otherwise be an open door with nothing to catch it.
        $needed = $definition['permissions'][$action] ?? null;

        /** @var ApiToken|null $token */
        $token = request()->attributes->get('api_token');

        abort_if($needed === null, 405, "The {$resource} resource is read-only through the API.");
        abort_unless($token?->permits($needed), 403, "This token may not {$needed}.");

        return [$definition, new QueryShaper($definition)];
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function present(Model $model, string $resource, array $fields, Request $request): array
    {
        $attributes = [];

        foreach ($fields as $field) {
            $attributes[$field] = $model->getAttribute($field);
        }

        $payload = [
            'type' => $resource,
            'id' => (string) $model->getKey(),
            'attributes' => $attributes,
        ];

        $loaded = array_keys($model->getRelations());

        if ($loaded !== []) {
            $payload['relationships'] = collect($loaded)
                ->mapWithKeys(fn (string $relation) => [
                    $relation => ['data' => $this->relationIdentifier($model->getRelation($relation))],
                ])->all();
        }

        return $payload;
    }

    private function relationIdentifier(mixed $related): mixed
    {
        if ($related instanceof Model) {
            return ['type' => $related->getMorphClass(), 'id' => (string) $related->getKey()];
        }

        if ($related instanceof \Illuminate\Support\Collection) {
            return $related->map(fn (Model $m) => ['type' => $m->getMorphClass(), 'id' => (string) $m->getKey()])->all();
        }

        return null;
    }

    /**
     * @param  list<string>  $writable
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $writable, bool $required): array
    {
        $payload = (array) ($request->input('data.attributes') ?? $request->all());

        $unknown = array_diff(array_keys($payload), $writable);

        if ($unknown !== []) {
            // Refused rather than ignored. A client that thinks it set
            // `residual_score` and got a 200 has no way to learn otherwise, and
            // will believe the value it sent is what the register holds.
            throw ValidationException::withMessages([
                'data.attributes' => 'These fields are not writable on this resource: '.implode(', ', $unknown).'.',
            ]);
        }

        $attributes = array_intersect_key($payload, array_flip($writable));

        if ($required && $attributes === []) {
            throw ValidationException::withMessages([
                'data.attributes' => 'No writable attributes were supplied.',
            ]);
        }

        return Validator::make($attributes, [])->validate() + $attributes;
    }

    private function assertSameTenant(Model $model): void
    {
        $organizationId = $model->getAttribute('organization_id');

        abort_if(
            $organizationId !== null && $organizationId !== TenantContext::organizationId(),
            404,
            'Not found.',
        );
    }

    private function links(mixed $page): array
    {
        return array_filter([
            'self' => $page->url($page instanceof \Illuminate\Pagination\CursorPaginator ? null : $page->currentPage()),
            'next' => $page->nextPageUrl(),
            'prev' => $page->previousPageUrl(),
        ], fn ($value) => $value !== null);
    }

    private function notFound(string $resource, string $id): JsonResponse
    {
        return response()->json([
            'errors' => [[
                'status' => '404',
                'title' => 'Not found',
                'detail' => "No {$resource} with id {$id}.",
            ]],
        ], 404);
    }

    private function readOnly(string $resource): JsonResponse
    {
        return response()->json([
            'errors' => [[
                'status' => '405',
                'title' => 'Read only',
                'detail' => "The {$resource} resource is read-only through the API. "
                    .'It is produced by the platform rather than supplied to it.',
            ]],
        ], 405);
    }
}
