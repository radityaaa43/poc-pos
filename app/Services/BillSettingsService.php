<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\Setting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * The only reader and writer of an outlet's bill settings (Tagihan, REQ-010).
 */
class BillSettingsService
{
    public const TAX_KINDS = ['ppn', 'pb1'];

    public const CASH_ROUNDING_UNITS = [0, 100, 500];

    // No row means the default, and the defaults compute a bill as before these settings existed.
    private const DEFAULTS = [
        'service_charge_rate' => 0.0,
        'tax_kind' => 'ppn',
        'pb1_rate' => 10.0,
        'cash_rounding_unit' => 0,
    ];

    public function __construct(private readonly AuditLogService $auditLogService) {}

    /**
     * @return array{service_charge_rate: float, tax_kind: string, pb1_rate: float, cash_rounding_unit: int}
     */
    public function get(?Outlet $outlet): array
    {
        // Outlet rows only: deleting an outlet turns its rows into global ones, which must not reach other outlets.
        $stored = $outlet
            ? Setting::where('outlet_id', $outlet->id)->whereIn('key', array_keys(self::DEFAULTS))->pluck('value', 'key')->all()
            : [];

        return $this->parse($stored + self::DEFAULTS);
    }

    /**
     * An absent or null field is unchanged. Only changed keys are written, with one audit row for all of them.
     */
    public function update(Outlet $outlet, array $data): void
    {
        $submitted = array_filter(Arr::only($data, array_keys(self::DEFAULTS)), fn ($value) => $value !== null);

        DB::transaction(function () use ($outlet, $submitted): void {
            $old = $this->get($outlet);
            $changed = array_filter(
                $this->parse($submitted + $old),
                fn ($value, string $key) => $value !== $old[$key],
                ARRAY_FILTER_USE_BOTH,
            );

            if ($changed === []) {
                return;
            }

            foreach ($changed as $key => $value) {
                Setting::setForOutlet($key, (string) $value, $outlet);
            }

            $this->auditLogService->log(
                event: 'outlet.bill_settings_updated',
                module: 'outlets',
                auditable: $outlet,
                description: 'Outlet bill settings changed',
                before: array_intersect_key($old, $changed),
                after: $changed,
            );
        });
    }

    private function parse(array $values): array
    {
        $rate = fn ($value): float => max(0.0, min(100.0, (float) $value));
        $unit = (int) $values['cash_rounding_unit'];

        return [
            'service_charge_rate' => $rate($values['service_charge_rate']),
            'tax_kind' => in_array($values['tax_kind'], self::TAX_KINDS, true) ? $values['tax_kind'] : self::DEFAULTS['tax_kind'],
            'pb1_rate' => $rate($values['pb1_rate']),
            'cash_rounding_unit' => in_array($unit, self::CASH_ROUNDING_UNITS, true) ? $unit : 0,
        ];
    }
}
