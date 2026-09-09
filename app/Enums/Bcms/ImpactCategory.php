<?php

namespace App\Enums\Bcms;

/** BIA impact categories (Blueprint §9.1, ISO/TS 22317). */
enum ImpactCategory: string
{
    case Financial = 'financial';
    case Regulatory = 'regulatory';
    case Reputational = 'reputational';
    case Customer = 'customer';
    case Legal = 'legal';
    case Safety = 'safety';

    /**
     * Categories where a monetary amount is meaningful. The others are scored
     * only, and a screen must not print a naira figure against them — a
     * fabricated number is a build failure here (development standard §5).
     */
    public function isMonetary(): bool
    {
        return in_array($this, [self::Financial, self::Legal], true);
    }
}
