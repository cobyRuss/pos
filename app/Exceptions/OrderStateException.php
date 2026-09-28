<?php

namespace App\Exceptions;

use RuntimeException;

class OrderStateException extends RuntimeException
{
    public static function cannotCancel(string $orderNumber, string $status): self
    {
        return new self(sprintf('Order %s is %s and can no longer be cancelled.', $orderNumber, $status));
    }

    public static function nothingToRefund(string $orderNumber): self
    {
        return new self(sprintf('Order %s has no refundable items left.', $orderNumber));
    }

    public static function exceedsRefundable(int $requested, int $available, string $productName): self
    {
        return new self(sprintf(
            'Cannot refund %d unit(s) of "%s". Only %d unit(s) remain refundable on this order.',
            $requested,
            $productName,
            $available,
        ));
    }
}
