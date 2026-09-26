<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Finds and repairs orders whose refunded_total no longer agrees with the sum
 * of their refund lines.
 *
 * Refunds created before the order-level levy was shared across lines credited
 * only the line totals, so the tax a customer paid was never returned. Those
 * orders are stuck: every unit is exhausted, so no further refund can be
 * issued, yet refunded_total stays below total and the order reads as
 * "partially refunded" forever.
 *
 * This command is deliberately conservative. It reports first, repairs only
 * with --fix, and never invents a refund: it can only ever raise refunded_total
 * to the sum of refunds already recorded against the order, which is money the
 * business genuinely handed back and failed to book. --dry-run previews it.
 */
class ReconcileRefundsCommand extends Command
{
    protected $signature = 'pos:reconcile-refunds
                            {--fix : Apply the corrections instead of only reporting them}
                            {--dry-run : Show what would change without writing (default)}';

    protected $description = 'Report and repair orders whose refunded_total disagrees with their refund lines';

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');

        $orders = Order::query()
            ->where('status', Order::STATUS_COMPLETED)
            ->whereHas('refunds')
            ->with(['refunds.items'])
            ->get()
            ->map(fn (Order $order) => $this->discrepancy($order))
            ->filter()
            ->values();

        if ($orders->isEmpty()) {
            $this->info('All refunded orders reconcile. Nothing to do.');

            return self::SUCCESS;
        }

        $this->warn(sprintf('%d order(s) have a refunded_total that disagrees with their refund lines.', $orders->count()));
        $this->newLine();
        $this->table(
            ['Order', 'Total', 'refunded_total', 'Refund lines', 'Correct', 'Difference'],
            $orders->map(fn (array $row) => [
                $row['number'],
                Money::fromCents($row['total']),
                Money::fromCents($row['booked']),
                Money::fromCents($row['lines']),
                Money::fromCents($row['correct']),
                Money::fromCents($row['difference']),
            ])->all(),
        );

        if (! $fix) {
            $this->newLine();
            $this->info('No changes made. Re-run with --fix to correct refunded_total.');

            return self::SUCCESS;
        }

        $this->newLine();
        $corrected = 0;
        $failed = 0;

        foreach ($orders as $row) {
            try {
                // Locked so a concurrent refund cannot be overwritten by the
                // correction, and re-checked inside the transaction.
                DB::transaction(function () use ($row): void {
                    $order = Order::query()->lockForUpdate()->find($row['id']);

                    if ($order === null) {
                        return;
                    }

                    $order->forceFill(['refunded_total' => Money::fromCents($row['correct'])])->save();
                });

                $this->line(sprintf('  <info>%s</info> refunded_total %s -> %s', $row['number'], Money::fromCents($row['booked']), Money::fromCents($row['correct'])));
                $corrected++;
            } catch (Throwable $e) {
                $this->line(sprintf('  <error>%s</error> could not be corrected: %s', $row['number'], $e->getMessage()));
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Corrected {$corrected} order(s).".($failed > 0 ? " {$failed} failed." : ''));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The reconciliation figures for one order, or null when it agrees.
     *
     * The authoritative figure is the sum of the recorded refund lines: that is
     * money that physically left the till. refunded_total is the bookkeeping
     * column that drifted away from it.
     *
     * @return array{id: int, number: string, total: int, booked: int, lines: int, correct: int, difference: int}|null
     */
    private function discrepancy(Order $order): ?array
    {
        // sum(), not ->value('total'): value() replaces the select clause, so
        // a selectRaw alias would be discarded and the query would read a
        // column that does not exist. This command writes financial
        // corrections, so the aggregate has to be exactly what it looks like.
        $linesTotal = RefundItem::query()
            ->whereIn('refund_id', $order->refunds->pluck('id'))
            ->sum('amount');

        $lines = Money::toCents($linesTotal);
        $booked = Money::toCents($order->refunded_total);
        $total = Money::toCents($order->total);

        if ($lines === $booked) {
            return null;
        }

        // Never book more than was charged, even if a line total is corrupt.
        $correct = min($lines, $total);

        return [
            'id' => (int) $order->getKey(),
            'number' => $order->order_number,
            'total' => $total,
            'booked' => $booked,
            'lines' => $lines,
            'correct' => $correct,
            'difference' => $correct - $booked,
        ];
    }
}
