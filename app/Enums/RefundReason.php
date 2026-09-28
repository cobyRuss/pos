<?php

namespace App\Enums;

/**
 * Why a customer is getting their money back.
 *
 * A fixed list rather than free text: the owner reviews refunds in a report, and
 * "the barcode wouldn't scan" and "haggles a lot" are not comparable signals.
 * Anything that does not fit is `other`, which obliges the cashier to add a note
 * so the entry still says something useful three months later.
 */
enum RefundReason: string
{
    case Damaged = 'damaged';
    case Expired = 'expired';
    case WrongItem = 'wrong_item';
    case ChangedMind = 'changed_mind';
    case DuplicateSale = 'duplicate_sale';
    case PriceAdjustment = 'price_adjustment';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Damaged => 'Damaged / defective item',
            self::Expired => 'Expired or near expiry',
            self::WrongItem => 'Wrong item handed over',
            self::ChangedMind => 'Customer changed mind',
            self::DuplicateSale => 'Duplicate charge',
            self::PriceAdjustment => 'Price adjustment',
            self::Other => 'Other (explain below)',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Damaged, self::Expired => 'text-bg-danger',
            self::DuplicateSale, self::PriceAdjustment => 'text-bg-warning',
            default => 'text-bg-secondary',
        };
    }

    /**
     * Reasons that carry an integrity signal rather than a customer preference.
     * Surfaced on the reconciliation report.
     */
    public function isIntegrityRisk(): bool
    {
        return in_array($this, [self::DuplicateSale, self::PriceAdjustment], true);
    }

    /**
     * `other` is the catch-all, so it is the one reason that cannot stand alone.
     */
    public function requiresDetail(): bool
    {
        return $this === self::Other;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
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
