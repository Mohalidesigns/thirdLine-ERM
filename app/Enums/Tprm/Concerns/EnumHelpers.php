<?php

namespace App\Enums\Tprm\Concerns;

/**
 * The three helpers every TPRM enum needs, declared once.
 *
 * `ControlTestStatus` carries its own copies of `values()` and
 * `validationRule()` because it was the first enum in the product and had
 * nothing to share them with. The TPRM module ships twenty enums; twenty
 * copies of `array_column(self::cases(), 'value')` is twenty places for a
 * validation rule to drift from the column it guards.
 *
 * `label()` and `color()` are NOT here. They differ per case and a default
 * that guesses ("ucfirst the value") produces "Ndpa gaid" on a screen and
 * nobody notices until a client does.
 */
trait EnumHelpers
{
    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * A validation rule string for `in:` — keeps a Form Request's rules in
     * step with the enum instead of restating it.
     */
    public static function validationRule(): string
    {
        return 'in:'.implode(',', self::values());
    }

    /**
     * The value/label pairs a select needs.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
