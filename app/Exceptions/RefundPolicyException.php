<?php

namespace App\Exceptions;

use App\Models\Setting;
use App\Models\User;
use RuntimeException;

/**
 * A refund that is well-formed but breaches a store policy.
 *
 * Split from `OrderStateException` on purpose: that one means "this order cannot
 * be refunded at all" (a data problem the cashier cannot fix), whereas this one
 * means "this refund is refused by the store's rules" - which is exactly the
 * case the owner wants to be told about, so it must be distinguishable in the
 * audit log and the alert.
 */
class RefundPolicyException extends RuntimeException
{
    public static function dailyLimitExceeded(User $actor, float $requested, float $limit): self
    {
        return new self(sprintf(
            'Refund of %s would take %s past their %s daily refund limit. Ask the owner to raise the limit or process it personally.',
            Setting::money($requested),
            $actor->name,
            Setting::money($limit),
        ));
    }

    public static function dailyLimitInForce(User $actor, float $used, float $limit): self
    {
        return new self(sprintf(
            '%s has already refunded %s today, which is the %s daily limit. No further refunds can be processed under their account today.',
            $actor->name,
            Setting::money($used),
            Setting::money($limit),
        ));
    }
}
