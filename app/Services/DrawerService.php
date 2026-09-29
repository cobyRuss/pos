<?php

namespace App\Services;

use App\Events\DayClosed;
use App\Models\CashSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DrawerService
{
    /**
     * Open a drawer for a cashier, starting from a counted float.
     *
     * The float is what the owner physically handed over, so it is the first
     * thing in the expected-cash arithmetic. Opening it at zero and "adjusting
     * later" is how a drawer ends up looking short by exactly the float.
     */
    public function open(User $cashier, float $openingFloat, ?string $note = null): CashSession
    {
        if ($openingFloat < 0) {
            throw new RuntimeException('The opening float cannot be negative.');
        }

        return DB::transaction(function () use ($cashier, $openingFloat, $note) {
            // One open drawer per cashier. Two would make "what should be in
            // here" ambiguous and count the same float twice.
            if (CashSession::query()->open()->where('user_id', $cashier->getKey())->lockForUpdate()->exists()) {
                throw new RuntimeException('You already have an open drawer. Close it before opening another.');
            }

            return CashSession::create([
                'user_id' => $cashier->getKey(),
                'opening_float' => round($openingFloat, 2),
                'opened_at' => now(),
                'note' => $note,
            ]);
        });
    }

    /**
     * Close a drawer against a physical count.
     *
     * A short or over drawer does not have to be explained, but it should be:
     * the reason is what turns "the drawer was 40 short" into something the
     * owner can actually follow up. It is optional because a miscount is the
     * most common reason and the cashier should not be blocked from closing.
     */
    public function close(CashSession $session, float $countedCash, ?string $varianceReason = null, ?string $note = null): CashSession
    {
        if (! $session->isOpen()) {
            throw new RuntimeException('This drawer has already been closed.');
        }

        if ($countedCash < 0) {
            throw new RuntimeException('A cash count cannot be negative.');
        }

        $session->forceFill([
            'closed_at' => now(),
            'counted_cash' => round($countedCash, 2),
            'variance_reason' => $varianceReason ?: null,
            'note' => $note ?: $session->note,
        ])->save();

        return $session->refresh();
    }

    /**
     * The cashier's currently open drawer, if any.
     */
    public function currentFor(User $cashier): ?CashSession
    {
        return CashSession::query()
            ->open()
            ->where('user_id', $cashier->getKey())
            ->latest('opened_at')
            ->first();
    }

    /**
     * Everything the close-of-day summary needs, computed once.
     *
     * @return array<string, mixed>
     */
    public function summary(CashSession $session): array
    {
        $cashSales = $session->cashSales();
        $cashRefunds = $session->cashRefunds();
        $expected = $session->expectedCash();
        $counted = $session->counted_cash === null ? null : (float) $session->counted_cash;

        return [
            'session' => $session,
            'cash_sales' => $cashSales,
            'cash_refunds' => $cashRefunds,
            'expected_cash' => $expected,
            'counted_cash' => $counted,
            'variance' => $counted === null ? null : round($counted - $expected, 2),
            'opened_at' => $session->opened_at,
            'closed_at' => $session->closed_at,
        ];
    }
}
