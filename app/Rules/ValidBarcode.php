<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Barcodes are scanned as plain numeric strings on most handhelds, so reject
 * anything that is not a plausible EAN-8, UPC-A, EAN-13 or ITF-14 payload.
 */
class ValidBarcode implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (! ctype_digit($value)) {
            $fail('The :attribute must contain digits only.');

            return;
        }

        $length = strlen($value);

        if (! in_array($length, [8, 12, 13, 14], true)) {
            $fail('The :attribute must be 8, 12, 13 or 14 digits.');

            return;
        }

        if (in_array($length, [8, 13], true) && ! $this->hasValidCheckDigit($value)) {
            $fail('The :attribute check digit is invalid.');
        }
    }

    /**
     * EAN-8 / UPC-A / EAN-13 share the same mod-10 weighting.
     */
    protected function hasValidCheckDigit(string $barcode): bool
    {
        $digits = array_map('intval', str_split($barcode));
        $checkDigit = array_pop($digits);

        $digits = array_reverse($digits);

        $sum = 0;

        foreach ($digits as $index => $digit) {
            $sum += $digit * ($index % 2 === 0 ? 3 : 1);
        }

        return ((10 - ($sum % 10)) % 10) === $checkDigit;
    }
}
