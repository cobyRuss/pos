<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSettingsRequest;
use App\Models\Setting;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SettingController extends Controller
{
    /**
     * Every setting the system understands, with defaults applied.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const DEFAULTS = [
        'store_name' => ['Demo Store', 'general', 'Store name'],
        'store_address' => ['', 'general', 'Store address'],
        'store_phone' => ['', 'general', 'Store phone'],
        'store_email' => ['', 'general', 'Store email'],
        'currency_symbol' => ['₱', 'general', 'Currency symbol'],
        'tax_rate' => ['0', 'tax', 'Tax rate (%)'],
        'receipt_footer' => ['Thank you for your purchase!', 'receipt', 'Receipt footer message'],
        'receipt_size' => ['80mm', 'receipt', 'Receipt paper width'],
        'low_stock_default' => ['5', 'inventory', 'Default low-stock threshold'],
        'refund_review_threshold' => ['500', 'refunds', 'Flag refunds above'],
        'refund_daily_limit' => ['0', 'refunds', 'Cashier daily refund limit'],
        'telegram_bot_token' => ['', 'integrations', 'Telegram bot token'],
        'telegram_chat_id' => ['', 'integrations', 'Telegram chat ID'],
    ];

    public function edit(): View
    {
        $current = Setting::all_as_array();

        $settings = [];

        foreach (self::DEFAULTS as $key => [$default, $group, $label]) {
            $settings[$key] = [
                'key' => $key,
                'label' => $label,
                'group' => $group,
                'value' => $current[$key] ?? $default,
                'default' => $default,
            ];
        }

        return view('settings.edit', [
            'settings' => $settings,
        ]);
    }

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        $before = Setting::all_as_array();
        $data = $request->validated();

        DB::transaction(function () use ($data) {
            $grouped = [];

            foreach (self::DEFAULTS as $key => [$default, $group]) {
                $grouped[$group][$key] = $data[$key] ?? $default;
            }

            foreach ($grouped as $group => $values) {
                Setting::setMany($values, $group);
            }
        });

        $diff = AuditLogger::diff($before, Setting::all_as_array());

        AuditLogger::record(
            AuditLogger::SETTINGS_UPDATED,
            sprintf('%s updated system settings.', $request->user()->name),
            null,
            $diff['old'],
            $diff['new'],
        );

        return redirect()
            ->route('admin.settings.edit')
            ->with('success', 'Settings saved.');
    }
}
