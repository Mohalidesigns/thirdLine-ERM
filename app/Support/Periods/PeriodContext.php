<?php

namespace App\Support\Periods;

use App\Models\Period;

/**
 * Holds the period selected for the current request.
 *
 * Modelled on TenantContext deliberately: a container singleton rather than
 * global mutable state, so a queued job and a parallel test process cannot
 * inherit a period from somewhere else.
 *
 * Unlike the tenant, an absent period is never fatal. A screen that has not
 * been moved onto the period model yet, or a console command that legitimately
 * spans time, reads null and behaves as it did before.
 */
class PeriodContext
{
    protected ?Period $period = null;

    public static function instance(): self
    {
        return app(self::class);
    }

    public function set(?Period $period): void
    {
        $this->period = $period;
    }

    public function get(): ?Period
    {
        return $this->period;
    }

    public static function current(): ?Period
    {
        return self::instance()->get();
    }

    public static function currentId(): ?int
    {
        return self::instance()->get()?->getKey();
    }

    public static function use(?Period $period): void
    {
        self::instance()->set($period);
    }

    /**
     * Run a callback with a different period bound, restoring the previous one.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function actingOn(?Period $period, callable $callback)
    {
        $context = self::instance();
        $previous = $context->get();
        $context->set($period);

        try {
            return $callback();
        } finally {
            $context->set($previous);
        }
    }
}
