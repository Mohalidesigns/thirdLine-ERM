<?php

namespace App\Enums\Bcms;

/**
 * `bcms_identity_connectors.auto_apply_policy`.
 *
 * TWO SETTINGS AND DELIBERATELY NO THIRD (ADR 0018 §3.3). `none` reviews
 * everything; `safe_only` auto-applies changes whose `impact_json` is empty.
 * There is no `all`: a change that breaks a call tree or empties a saved
 * audience requires BC-admin acknowledgement, unconditionally — a tenant
 * switch that could turn that off would be the switch somebody flips on a
 * busy Friday.
 */
enum AutoApplyPolicy: string
{
    case None = 'none';
    case SafeOnly = 'safe_only';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Review everything',
            self::SafeOnly => 'Auto-apply changes with no call-tree or audience impact',
        };
    }

    /**
     * Whether a change with the given impact may be applied without a human
     * decision. `$requiresAck` always wins: an unconditional rule, not a
     * policy choice.
     */
    public function permitsAutoApply(bool $requiresAck): bool
    {
        if ($requiresAck) {
            return false;
        }

        return $this === self::SafeOnly;
    }
}
