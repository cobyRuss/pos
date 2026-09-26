<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Setting;
use App\Services\SalesAnalytics;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, SalesAnalytics $analytics): View
    {
        $user = $request->user();
        $isAdmin = $user->hasRole(Role::Admin);

        // A staff member has no business seeing shop-wide takings, so their
        // dashboard is scoped to their own till.
        $range = SalesAnalytics::resolveRange($request->query('range'));

        $data = [
            'user' => $user,
            'isAdmin' => $isAdmin,
            'range' => $range,
            'ranges' => SalesAnalytics::ranges(),
            'currency' => Setting::currency(),
        ];

        if ($isAdmin) {
            $data += [
                'summary' => $analytics->summary($range),
                'trend' => $analytics->dailyTrend($range),
                'bestSellers' => $analytics->bestSellers($range),
                'byPayment' => $analytics->byPaymentMethod($range),
                'byStaff' => $analytics->byStaff($range),
                'inventory' => $analytics->inventorySummary(),
            ];
        } else {
            $data['summary'] = $analytics->summaryForUser((int) $user->getKey(), $range);
        }

        return view('dashboard', $data);
    }
}
