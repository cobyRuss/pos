<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Events\DayClosed;
use App\Models\CashSession;
use App\Models\Setting;
use App\Models\User;
use App\Support\AuditLogger;
use App\Services\DrawerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class DrawerController extends Controller
{
    public function __construct(private readonly DrawerService $drawer) {}

    public function index(Request $request): View
    {
        return view('drawer.index', [
            'current' => $this->drawer->currentFor($request->user()),
            'summary' => $this->summaryFor($request->user()),
            'sessions' => CashSession::query()
                ->with('user')
                ->whereNotNull('closed_at')
                ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
                ->when($request->boolean('short_only'), fn ($q) => $q->whereNotNull('counted_cash'))
                ->latest('closed_at')
                ->paginate(25)
                ->withQueryString(),
            'cashiers' => User::query()
                ->where('role', UserRole::Staff->value)
                ->orderBy('name')
                ->get(),
            'filters' => $request->only(['user_id', 'short_only']),
        ]);
    }

    public function open(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'opening_float' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $session = $this->drawer->open(
                $request->user(),
                (float) $validated['opening_float'],
                $validated['note'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        AuditLogger::record(
            AuditLogger::DRAWER_OPENED,
            sprintf(
                '%s opened drawer #%d with a %s float.',
                $request->user()->name,
                $session->getKey(),
                \App\Models\Setting::money($session->opening_float),
            ),
            $session,
        );

        return redirect()
            ->route('drawer.index')
            ->with('success', 'Drawer opened. Count the float in before you start selling.');
    }

    /**
     * The route parameter is `cashSession` and must stay named that: Laravel
     * resolves implicit model binding by parameter name, and a mismatch makes it
     * inject a brand-new empty model instead of the drawer in the URL.
     */
    public function close(Request $request, CashSession $cashSession): RedirectResponse
    {
        $session = $cashSession;

        // A cashier may only close their own drawer. The owner reads everyone's
        // but does not close on their behalf: the count has to be the person who
        // was holding the money.
        if ($session->user_id !== $request->user()->getKey()) {
            AuditLogger::record(
                AuditLogger::ACCESS_DENIED,
                sprintf('%s tried to close drawer #%d, which is not theirs.', $request->user()->name, $session->getKey()),
                $session,
            );

            return redirect()
                ->route('drawer.index')
                ->with('error', 'You can only close your own drawer.');
        }

        $validated = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'variance_reason' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $closed = $this->drawer->close(
                $session,
                (float) $validated['counted_cash'],
                $validated['variance_reason'] ?? null,
                $validated['note'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $summary = $this->drawer->summary($closed);

        AuditLogger::record(
            AuditLogger::DRAWER_CLOSED,
            sprintf(
                '%s closed drawer #%d: counted %s against %s expected (%s).',
                $request->user()->name,
                $closed->getKey(),
                \App\Models\Setting::money($closed->counted_cash),
                \App\Models\Setting::money($summary['expected_cash']),
                $summary['variance'] === 0.0
                    ? 'balanced'
                    : \App\Models\Setting::money(abs($summary['variance'])).($summary['variance'] < 0 ? ' short' : ' over'),
            ),
            $closed,
        );

        // Dispatched after the commit so the summary can never describe a
        // drawer that was rolled back.
        DayClosed::dispatch($closed, $summary);

        return redirect()
            ->route('drawer.index')
            ->with('success', $summary['variance'] === 0.0
                ? 'Drawer closed and balanced.'
                : sprintf(
                    'Drawer closed: %s %s.',
                    \App\Models\Setting::money(abs($summary['variance'])),
                    $summary['variance'] < 0 ? 'short' : 'over',
                ));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function summaryFor(User $user): ?array
    {
        $current = $this->drawer->currentFor($user);

        return $current ? $this->drawer->summary($current) : null;
    }
}
