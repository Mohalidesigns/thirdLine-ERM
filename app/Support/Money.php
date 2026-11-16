<?php

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Immutable money value: integer minor units + ISO-4217 currency code (R7).
 * Floats are rejected at construction — rounding drift in a compliance
 * product is a reportable defect, not a nuisance.
 */
final class Money implements JsonSerializable
{
    /** Currencies the platform supports out of the box (R7). */
    public const SUPPORTED = ['NGN', 'GHS', 'KES', 'ZAR', 'USD', 'EUR', 'GBP'];

    /** Minor units per currency (all supported currencies use 2). */
    private const MINOR_UNIT_EXPONENT = 2;

    private const SYMBOLS = [
        'NGN' => '₦', 'GHS' => 'GH₵', 'KES' => 'KSh', 'ZAR' => 'R',
        'USD' => '$', 'EUR' => '€', 'GBP' => '£',
    ];

    private function __construct(
        public readonly int $amount,
        public readonly string $currency,
    ) {}

    /**
     * @param  mixed  $minorUnits  strictly an integer count of minor units (kobo, pesewas, cents)
     */
    public static function of(mixed $minorUnits, string $currency): self
    {
        if (! is_int($minorUnits)) {
            throw new InvalidArgumentException(
                'Money amounts must be integer minor units — floats and strings are rejected (rule R7).'
            );
        }

        return new self($minorUnits, self::assertCurrency($currency));
    }

    /**
     * Parse a decimal string in major units ("1500.25") without ever
     * passing through a float.
     */
    public static function fromMajor(string $major, string $currency): self
    {
        $major = trim($major);

        if (! preg_match('/^-?\d+(\.\d{1,'.self::MINOR_UNIT_EXPONENT.'})?$/', $major)) {
            throw new InvalidArgumentException("Cannot parse '{$major}' as a decimal money amount.");
        }

        [$whole, $fraction] = array_pad(explode('.', ltrim($major, '-')), 2, '');
        $fraction = str_pad($fraction, self::MINOR_UNIT_EXPONENT, '0');
        $minor = (int) ($whole.$fraction);

        if (str_starts_with($major, '-')) {
            $minor = -$minor;
        }

        return new self($minor, self::assertCurrency($currency));
    }

    public static function zero(string $currency): self
    {
        return new self(0, self::assertCurrency($currency));
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    /** Major-unit decimal string suitable for an <input type="number"> or form state. */
    public function formatMajor(): string
    {
        $sign = $this->amount < 0 ? '-' : '';
        $abs = abs($this->amount);
        $factor = 10 ** self::MINOR_UNIT_EXPONENT;
        $whole = intdiv($abs, $factor);
        $fraction = str_pad((string) ($abs % $factor), self::MINOR_UNIT_EXPONENT, '0', STR_PAD_LEFT);

        return "{$sign}{$whole}.{$fraction}";
    }

    /** Localised display string with currency symbol and thousands separators. */
    public function format(): string
    {
        $sign = $this->amount < 0 ? '-' : '';
        $abs = abs($this->amount);
        $factor = 10 ** self::MINOR_UNIT_EXPONENT;
        $whole = number_format(intdiv($abs, $factor));
        $fraction = str_pad((string) ($abs % $factor), self::MINOR_UNIT_EXPONENT, '0', STR_PAD_LEFT);
        $symbol = self::SYMBOLS[$this->currency] ?? ($this->currency.' ');

        return "{$sign}{$symbol}{$whole}.{$fraction}";
    }

    public function __toString(): string
    {
        return $this->format();
    }

    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'formatted' => $this->format(),
            'major' => $this->formatMajor(),
        ];
    }

    private static function assertCurrency(string $code): string
    {
        $code = strtoupper(trim($code));

        if (! in_array($code, self::SUPPORTED, true)) {
            throw new InvalidArgumentException("Unsupported currency '{$code}'.");
        }

        return $code;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Cannot combine {$this->currency} with {$other->currency}."
            );
        }
    }
}
