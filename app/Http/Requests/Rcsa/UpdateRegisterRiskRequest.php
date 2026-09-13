<?php

namespace App\Http\Requests\Rcsa;

/**
 * Change a universe risk (rcsa.universe.update).
 *
 * The rules are the store request's — a risk statement that is too short to
 * create is too short to edit into — so it extends rather than repeats them.
 * The two differences are the ability checked and the uniqueness test, which
 * must exclude the row being edited; the parent request already does that
 * through `$this->route('risk')`.
 */
class UpdateRegisterRiskRequest extends StoreRegisterRiskRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('risk'));
    }
}
