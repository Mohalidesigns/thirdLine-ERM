<?php

namespace App\Http\Requests\Admin\Integrations;

use App\Models\ApiToken;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;

/**
 * Issue an API token (migration Phase 6.7).
 *
 * `scopes.*` was `string|max:100` while the form rendered every seeded
 * permission as a checkbox. A scope that does not exist grants nothing —
 * `ApiToken::permits()` intersects at request time — so this was fail-closed
 * rather than dangerous. It was also silent: a typo produced a token that
 * looked configured and refused the request it was issued for.
 *
 * `*` STAYS ALLOWED HERE, and only here. A personal token cannot grant what its
 * owner does not have, so `*` on one means "whatever I can do", which is what
 * the person could do by logging in anyway. A machine token has no owner to be
 * narrowed by, and `ApiToken::assertMachineScopesAreExplicit()` refuses `*` on
 * one — a check the controller already made and still makes, because it belongs
 * to the model rather than to this form.
 */
class StoreApiTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ApiToken::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', 'max:100', Rule::in(self::assignableScopes())],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'token_type' => ['nullable', 'in:personal,client_credentials'],
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function assignableScopes(): array
    {
        return [...Permission::query()->orderBy('name')->pluck('name')->all(), '*'];
    }
}
