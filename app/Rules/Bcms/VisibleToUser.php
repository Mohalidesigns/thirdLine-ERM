<?php

namespace App\Rules\Bcms;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR 0017 §7 — the request-body analogue of standard §4's tenant-bound
 * `Rule::exists(...)->where('organization_id', ...)`.
 *
 * Route-model binding (`BindsToVisibleRecord`) only ever sees an id in the
 * URL. Nine call sites across BCMS turn an id in the *request body* into a
 * BCMS record instead, and a bare `exists:table,id` there is an existence
 * oracle across whatever boundary the table carries — tenant, if the model
 * has none of BCMS's own scoping, or business unit, if it does.
 *
 * REUSES `BindsToVisibleRecord::constrainToVisibleRecord()` RATHER THAN
 * RE-DERIVING THE RULE. The model already knows whether it is an anchor or
 * derived; asking it a second time here would be scoping implemented twice —
 * the exact failure mode `ScopedToOrgHierarchy`'s own docblock warns against.
 * A model with no `BindsToVisibleRecord` (organisation-level, per the pinned
 * map) simply gets the tenant's own global scope and nothing more, which is
 * correct for that category.
 */
class VisibleToUser implements ValidationRule
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        private readonly string $model,
        private readonly string $column = 'id',
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        /** @var Model $instance */
        $instance = new $this->model;

        $query = $instance->newQuery()->where($this->column, $value);

        if (method_exists($instance, 'constrainToVisibleRecord')) {
            $instance->constrainToVisibleRecord($query);
        }

        if (! $query->exists()) {
            $fail('The selected :attribute is invalid.');
        }
    }
}
