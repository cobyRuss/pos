<?php

namespace App\Exceptions;

use App\Models\Product;
use RuntimeException;

/**
 * Raised when a sale or stock-out cannot be covered by sellable stock.
 *
 * `expiredAvailable` lets the message explain the common case: the units are on
 * the shelf, but FEFO is not allowed to sell them because their lot has passed
 * its date.
 */
class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly Product $product,
        public readonly int $requested,
        public readonly int $available = 0,
        public readonly int $expiredAvailable = 0,
    ) {
        $sellable = $available > 0 ? $available : (int) $product->stock;

        $message = sprintf(
            'Insufficient stock for "%s". Available: %d, requested: %d.',
            $product->name,
            $sellable,
            $requested,
        );

        if ($expiredAvailable > 0) {
            $message .= sprintf(
                ' %d unit(s) are on hand but past their expiry date and cannot be sold.',
                $expiredAvailable,
            );
        }

        parent::__construct($message);
    }
}
