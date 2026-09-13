<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET api/v1/graph/objects/{object}/related — a node's typed neighbours.
 *
 * `depth` is capped at 5 in the rules, not in the service: a graph traversal is
 * the one API parameter where an unbounded value is a denial of service rather
 * than a large response, and the cap belongs where a caller is told about it.
 */
class RelatedObjectsRequest extends FormRequest
{
    /**
     * The route carries `scope:` middleware and the controller asserts the
     * object's tenant before this is read. Authorisation is not this class's
     * job; the cross-tenant check is, and it cannot be expressed as a rule
     * because the object is a route binding rather than input.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'relationship' => ['required', 'string', 'max:60'],
            'direction' => ['nullable', 'in:out,in'],
            'depth' => ['nullable', 'integer', 'min:1', 'max:5'],
        ];
    }
}
