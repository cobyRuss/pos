<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Models\Setting;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function edit(): View
    {
        return view('admin.settings.edit', [
            'settings' => Setting::allCached(),
        ]);
    }

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        $changed = [];

        foreach ($request->validated() as $key => $value) {
            $before = Setting::get($key);

            if ((string) $before !== (string) $value) {
                $changed[] = $key;
            }

            Setting::put($key, $value);
        }

        $this->audit->record(
            AuditLogger::SETTINGS_UPDATED,
            null,
            $request->user()->getKey(),
            $changed === []
                ? 'Saved settings with no changes.'
                : 'Changed settings: '.implode(', ', $changed).'.',
        );

        return back()->with('success', 'Settings saved.');
    }
}
