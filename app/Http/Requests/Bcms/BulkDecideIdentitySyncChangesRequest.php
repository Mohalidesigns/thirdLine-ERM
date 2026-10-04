<?php

namespace App\Http\Requests\Bcms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bulk approve/reject over one run's staged changes — ADR 0018 §2.4, §9.
 *
 * THE ID LIST IS TENANT-BOUND **AND** RUN-BOUND, never a bare `exists:`
 * (development standard §4). Scoping to `sync_run_id` as well as
 * `organization_id` means an id from a different run in the SAME
 * organisation still fails — this action only ever acts on the one run
 * `{run}` in the URL, which is what keeps `->scopeBindings()` on the nested
 * per-change route meaningful for the single-change action and honest here
 * too.
 *
 * A REQUIRES-ACK ROW IS EXCLUDED HERE, BUT DELIBERATELY NOT BY FAILING THIS
 * REQUEST'S VALIDATION. This file's own previous docblock read "an id that
 * reaches this request was, by definition, explicitly selected — that IS
 * the acknowledgement", which was wrong: `Review.jsx` disables the row's
 * checkbox so a requires_ack id can never be POSTed from the screen, but
 * that is a client-side control only — ADR 0018 §3.2/§5 is unconditional
 * ("a change that breaks a call tree or empties a saved audience requires
 * individual acknowledgement... there is no `all`") and carries no
 * exception for an id arriving through some other caller (curl, an API
 * client, a differently-written front end).
 *
 * The exclusion is enforced in `IdentitySyncController::bulkDecide()`
 * instead of here, because a single submitted batch can legitimately mix a
 * requires_ack id with ordinary pending ones (exactly the case a reviewer
 * clearing a queue produces), and a hard validation failure on the whole
 * array would also refuse the ordinary ids sharing that request — which
 * ADR 0018 never asks for. The controller applies every id that is NOT
 * requires_ack, refuses (and audits) every id that IS, and reports both in
 * the response rather than silently dropping either.
 */
class BulkDecideIdentitySyncChangesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.identity.review') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organizationId = $this->user()?->organization_id;
        $run = $this->route('run');

        return [
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'change_ids' => ['required', 'array', 'min:1'],
            'change_ids.*' => [
                'integer',
                Rule::exists('bcms_identity_sync_changes', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('sync_run_id', $run?->getKey())
                    ->where('decision', 'pending'),
            ],
        ];
    }
}
