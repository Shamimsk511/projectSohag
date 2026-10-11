<?php

namespace App\Support;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Immutable BDT amount held as integer poisha (1 taka = 100 poisha). Never uses floats.
 * Use as an Eloquent cast on BIGINT columns: `'amount' => Money::class`.
 */
final readonly class Money implements Castable
{
    /** Integer part limit keeps poisha well inside a signed 64-bit BIGINT. */
    private const MAX_TAKA_DIGITS = 15;

    private function __construct(public int $poisha) {}

    public static function fromPoisha(int $poisha): self
    {
        return new self($poisha);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Parse a taka string such as "1,23,456.75" or "১২৩৪.৫০" exactly.
     *
     * @throws InvalidArgumentException
     */
    public static function fromTaka(string $taka): self
    {
        $normalized = str_replace([',', ' '], '', Numerals::toAscii(trim($taka)));
        $pattern = '/^(-?)(\d{0,'.self::MAX_TAKA_DIGITS.'})(?:\.(\d{1,2}))?$/';

        if (! preg_match($pattern, $normalized, $matches) || ($matches[2] === '' && ($matches[3] ?? '') === '')) {
            throw new InvalidArgumentException("Invalid taka amount [{$taka}].");
        }

        $poisha = (int) $matches[2] * 100 + (int) str_pad($matches[3] ?? '', 2, '0');

        return new self($matches[1] === '-' ? -$poisha : $poisha);
    }

    public function plus(self $other): self
    {
        return new self($this->poisha + $other->poisha);
    }

    public function minus(self $other): self
    {
        return new self($this->poisha - $other->poisha);
    }

    public function isZero(): bool
    {
        return $this->poisha === 0;
    }

    public function isPositive(): bool
    {
        return $this->poisha > 0;
    }

    public function isNegative(): bool
    {
        return $this->poisha < 0;
    }

    public function equals(self $other): bool
    {
        return $this->poisha === $other->poisha;
    }

    public function greaterThan(self $other): bool
    {
        return $this->poisha > $other->poisha;
    }

    /**
     * Plain decimal for form values and exports, e.g. "1234.05".
     */
    public function toTaka(): string
    {
        return ($this->poisha < 0 ? '-' : '').$this->absoluteTaka();
    }

    /**
     * Display string with lakh grouping and locale digits, e.g. "৳১২,৩৪,৫৬৭.৮৯".
     */
    public function format(?string $locale = null): string
    {
        [$whole, $fraction] = explode('.', $this->absoluteTaka());

        $grouped = substr($whole, -3);

        for ($rest = substr($whole, 0, -3); $rest !== ''; $rest = substr($rest, 0, -2)) {
            $grouped = substr($rest, -2).','.$grouped;
        }

        return ($this->poisha < 0 ? '-' : '').'৳'.Numerals::localize("{$grouped}.{$fraction}", $locale);
    }

    private function absoluteTaka(): string
    {
        $absolute = abs($this->poisha);

        return intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<int, string>  $arguments
     * @return CastsAttributes<self, self>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes
        {
            public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
            {
                return $value === null ? null : Money::fromPoisha((int) $value);
            }

            public function set(Model $model, string $key, mixed $value, array $attributes): ?int
            {
                if ($value === null) {
                    return null;
                }

                if (! $value instanceof Money) {
                    throw new InvalidArgumentException("[{$key}] must be a Money instance.");
                }

                return $value->poisha;
            }
        };
    }
}
