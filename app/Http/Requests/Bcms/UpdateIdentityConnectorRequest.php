<?php

namespace App\Http\Requests\Bcms;

use App\Support\Bcms\DirectoryAttributeMap;
use App\Support\Bcms\DirectoryHostGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use RuntimeException;

/**
 * The Entra connector form — ADR 0018 §2.2, work order §5.
 *
 * `client_secret` IS NULLABLE AND AN EMPTY VALUE LEAVES THE STORED SECRET
 * ALONE. Secrets are write-only on this screen (ADR 0018 §5) — the field
 * shows whether one is set, never its value — so a save with the field left
 * blank must not wipe a working credential. The controller is what implements
 * "blank means unchanged"; this request only allows blank through.
 *
 * `attribute_map` IS VALIDATED AGAINST THE WRITE ALLOWLIST, not against a free
 * shape — a saved map naming a column outside `ChangeApplier::WRITE_ALLOWLIST`
 * would be a configuration screen that can widen what a directory read can
 * touch, which is the one thing ADR 0018 §3.4 refuses.
 *
 * `token_base_url`/`graph_base_url` ARE CHECKED AGAINST
 * `DirectoryHostGuard::assertAllowed()` (gate 2 blocking defect 1) — a field
 * error here, not the 500 a `RuntimeException` from `EntraGraphClient` at
 * fetch time would otherwise be. This is the courtesy check; the same guard
 * runs again at fetch time regardless, because a value that was allowed when
 * it was typed is not re-validated on every read.
 */
class UpdateIdentityConnectorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.identity.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'directory_tenant_id' => ['required', 'string', 'max:100'],
            'client_id' => ['required', 'string', 'max:100'],
            // Blank leaves the stored secret alone — never required.
            'client_secret' => ['nullable', 'string', 'max:20000'],
            'token_base_url' => ['required', 'url', 'max:190'],
            'graph_base_url' => ['required', 'url', 'max:190'],
            // An OData $filter over user attributes, not a group id (ADR 0018
            // §3.2) — validated as a string only; Graph itself is the
            // authority on whether the filter is well-formed, which
            // `testConnection()` surfaces.
            'directory_filter' => ['nullable', 'string', 'max:255'],
            'attribute_map' => ['nullable', 'array'],
            'attribute_map.*' => ['string'],
            'sync_schedule' => ['required', Rule::in(['nightly', 'nightly_plus_delta', 'manual'])],
            'auto_apply_policy' => ['required', Rule::in(['none', 'safe_only'])],
            'credential_expires_on' => ['nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $map = (array) $this->input('attribute_map', []);

            if ($map !== [] && ! DirectoryAttributeMap::isValid($map)) {
                $validator->errors()->add(
                    'attribute_map',
                    'Every mapped field must be one of the columns a directory sync is allowed to write.',
                );
            }

            foreach (['token_base_url', 'graph_base_url'] as $field) {
                $url = $this->input($field);

                if (! is_string($url) || $url === '') {
                    continue;
                }

                try {
                    DirectoryHostGuard::assertAllowed($url);
                } catch (RuntimeException $e) {
                    $validator->errors()->add($field, $e->getMessage());
                }
            }
        });
    }
}
