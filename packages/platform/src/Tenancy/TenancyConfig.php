<?php

namespace ThirdLine\Platform\Tenancy;

use RuntimeException;

/**
 * The application-specific facts the tenancy primitives need.
 *
 * There is exactly one: which class is the tenant. Everything else about
 * tenancy — the column name, the global scope, the bypass audit trail — is the
 * same in every application that uses it, which is why the rest of this
 * namespace carries no configuration at all.
 *
 * IT FAILS LOUDLY RATHER THAN GUESSING. A missing or non-existent
 * `platform.tenancy.organization_model` throws here, at the point of use, with
 * the key named. The alternative — defaulting to `App\Models\Organization`
 * because that is what both current applications happen to call it — would
 * make the package appear to work in an application that had not configured
 * it, until the first `->organization` on a differently-named model returned a
 * relation to a class that does not exist.
 */
final class TenancyConfig
{
    /**
     * @return class-string
     */
    public static function organizationModel(): string
    {
        $model = config('platform.tenancy.organization_model');

        if (! is_string($model) || $model === '') {
            throw new RuntimeException(
                'platform.tenancy.organization_model is not configured. Publish the platform '
                .'config, or set it to the class that represents a tenant in this application '
                .'— BelongsToOrganization cannot build its relation without one.'
            );
        }

        if (! class_exists($model)) {
            throw new RuntimeException(
                "platform.tenancy.organization_model is set to \"{$model}\", which does not exist."
            );
        }

        return $model;
    }
}
