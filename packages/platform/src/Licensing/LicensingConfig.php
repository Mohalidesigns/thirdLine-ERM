<?php

namespace ThirdLine\Platform\Licensing;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * The application-specific fact the licensing cluster needs: what a seat is.
 *
 * Exactly one read in ten service classes named the application —
 * `App\Models\User::count()`, the seats-in-use figure reported to the licence
 * server. Everything else in the cluster is about JWTs, grace periods, clock
 * drift and tamper detection, none of which care what product they are in.
 *
 * NO DEFAULT, for the same reason as TenancyConfig: a package that guesses
 * `App\Models\User` works until it meets a consumer that calls it something
 * else, and then under-reports seat usage to a licence server rather than
 * failing — which is a licence compliance problem discovered by an auditor
 * rather than by a test.
 */
final class LicensingConfig
{
    /**
     * @return class-string<Model>
     */
    public static function userModel(): string
    {
        $model = config('licensing.user_model');

        if (! is_string($model) || $model === '') {
            throw new RuntimeException(
                'licensing.user_model is not configured. Set it to the class a licence seat '
                .'is counted from — the licence server is told how many are in use, so this '
                .'cannot be guessed.'
            );
        }

        if (! is_subclass_of($model, Model::class)) {
            throw new RuntimeException(
                "licensing.user_model is set to \"{$model}\", which is not an Eloquent model."
            );
        }

        return $model;
    }
}
