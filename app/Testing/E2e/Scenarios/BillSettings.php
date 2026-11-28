<?php

namespace App\Testing\E2e\Scenarios;

use App\Models\AuditLog;
use App\Models\Outlet;
use App\Models\Setting;
use App\Services\BillSettingsService;
use Database\Seeders\E2eSeeder;
use Illuminate\Support\Arr;

/**
 * Arrange scenarios for an outlet's bill settings (`billsettings.*`, REQ-010), on --arg=code=<outlet code>
 * (default the E2E outlet).
 */
class BillSettings
{
    /**
     * @return array<string, \Closure(array<string, string>): array<string, mixed>>
     */
    public static function registry(): array
    {
        $outlet = fn (array $options): Outlet => Outlet::where('code', $options['code'] ?? E2eSeeder::OUTLET_CODE)->firstOrFail();

        return [
            // Back to "never saved": no rows. The suite shares one database, and other steps expect no bill rules.
            'billsettings.reset' => function (array $options) use ($outlet): array {
                $deleted = Setting::where('outlet_id', $outlet($options)->id)
                    ->whereIn('key', array_keys(BillSettingsService::DEFAULTS))
                    ->delete();

                return ['deleted' => $deleted];
            },

            // Stores the given rules (--arg=service_charge_rate=5 ...) without the audit row of an owner's save,
            // which other steps count.
            'billsettings.set' => function (array $options) use ($outlet): array {
                $target = $outlet($options);
                foreach (Arr::only($options, array_keys(BillSettingsService::DEFAULTS)) as $key => $value) {
                    Setting::setForOutlet($key, $value, $target);
                }

                return ['values' => app(BillSettingsService::class)->get($target)];
            },

            'billsettings.state' => function (array $options) use ($outlet): array {
                $target = $outlet($options);
                $audits = AuditLog::where('event', 'outlet.bill_settings_updated')->where('auditable_id', $target->id);
                $latest = (clone $audits)->latest('id')->first();

                return [
                    'values' => app(BillSettingsService::class)->get($target),
                    'rows' => Setting::where('outlet_id', $target->id)->whereIn('key', array_keys(BillSettingsService::DEFAULTS))->count(),
                    'audits' => $audits->count(),
                    'latest' => $latest ? [
                        'user' => $latest->user()->value('email'),
                        'outlet' => $latest->target_label,
                        'at' => $latest->created_at->toIso8601String(),
                        'before' => $latest->before,
                        'after' => $latest->after,
                    ] : null,
                ];
            },
        ];
    }
}
