<?php

namespace App\Http\Requests\Admin\Configuration;

use App\Models\ConfigBundle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The dry run (migration Phase 6.6).
 *
 * `bundle_id` was `exists:config_bundles,id` — bare, across every organisation
 * on the installation. It was not exploitable: `payloadFrom()` resolves the row
 * through the tenant-scoped model and asserts ownership, so another
 * institution's bundle 404s rather than being read. But the rule's error
 * message distinguishes "no such bundle" from "not yours", which is a
 * difference worth not publishing, and a later refactor that trusted the
 * validated id would have inherited a hole rather than found one.
 *
 * The uploaded file is deliberately left as a shape check rather than a schema
 * check. A diff never writes: `ConfigurationImporter::plan()` compares and
 * records a dry-run log entry. What a malformed payload can produce is a bad
 * diff, which is exactly what the operator is looking at before deciding.
 */
class DiffBundleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ConfigBundle::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'bundle_id' => [
                'nullable', 'integer',
                Rule::exists('config_bundles', 'id')
                    ->where('organization_id', TenantContext::organizationId()),
            ],
            'file' => ['nullable', 'file', 'mimetypes:application/json,text/plain', 'max:10240'],
        ];
    }
}
