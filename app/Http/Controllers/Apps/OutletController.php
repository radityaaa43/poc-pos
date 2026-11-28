<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOutletRequest;
use App\Http\Requests\UpdateOutletRequest;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\OutletAccessService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class OutletController extends Controller
{
    public function __construct(
        protected readonly OutletAccessService $outletAccessService,
        protected readonly AuditLogService $auditLogService,
    ) {}

    public function index(): Response
    {
        $outlets = $this->outletAccessService->accessibleOutlets(request()->user())
            ->load(['warehouses' => fn ($query) => $query->orderBy('sort_order')->orderBy('code')])
            ->each(function (Model $model) {
                /** @var Outlet $model accessibleOutlets() returns an untyped Eloquent collection of outlets */
                $model->setAttribute('pay_later_enabled', Setting::getBoolForOutlet('pay_later_enabled', $model));
            });

        return Inertia::render('Dashboard/Settings/Outlets/Index', ['outlets' => $outlets]);
    }

    public function store(StoreOutletRequest $request): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($request, $data): void {
            $outlet = Outlet::create([
                'code' => strtoupper($data['code']),
                'name' => $data['name'],
                'is_active' => true,
                'is_sales_enabled' => (bool) ($data['is_sales_enabled'] ?? true),
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
            ]);

            $warehouse = $outlet->warehouses()->create([
                'code' => strtoupper($data['warehouse_code']),
                'name' => $data['warehouse_name'],
                'type' => $outlet->is_sales_enabled ? 'branch' : 'warehouse',
                'address' => $outlet->address,
                'phone' => $outlet->phone,
                'is_active' => true,
                'sort_order' => 0,
            ]);

            $warehouse->products()->syncWithPivotValues(Product::pluck('id'), ['stock' => 0]);

            if (! $request->user()->isSuperAdmin()) {
                $request->user()->outlets()->syncWithoutDetaching([
                    $outlet->id => ['is_default' => true],
                ]);
            }
        });

        return to_route('settings.outlets.index')->with('success', 'Outlet berhasil dibuat.');
    }

    public function update(UpdateOutletRequest $request, Outlet $outlet): RedirectResponse
    {
        $this->ensureAccess($request->user(), $outlet);
        $data = $request->validated();

        $isActive = (bool) ($data['is_active'] ?? $outlet->is_active);
        $isSalesEnabled = strtoupper($data['code']) === 'PUSAT'
            ? false
            : (bool) ($data['is_sales_enabled'] ?? $outlet->is_sales_enabled);

        if ((! $isActive || ! $isSalesEnabled) && $this->hasOpenShift($outlet)) {
            return back()->with('error', 'Tutup semua shift aktif sebelum menonaktifkan outlet atau penjualan.');
        }

        $outlet->update([
            'code' => strtoupper($data['code']),
            'name' => $data['name'],
            'address' => $data['address'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'is_active' => $isActive,
            'is_sales_enabled' => $isSalesEnabled,
        ]);
        $this->updatePayLater($outlet, $data);

        return back()->with('success', 'Outlet berhasil diperbarui.');
    }

    /**
     * Shared with the API twin. Absent field = unchanged; audited only when the value flips.
     */
    protected function updatePayLater(Outlet $outlet, array $data): void
    {
        if (! array_key_exists('pay_later_enabled', $data)) {
            return;
        }

        $new = (bool) $data['pay_later_enabled'];

        DB::transaction(function () use ($outlet, $new): void {
            $old = Setting::getBoolForOutlet('pay_later_enabled', $outlet);

            if ($new === $old) {
                return;
            }

            Setting::setForOutlet('pay_later_enabled', $new ? '1' : '0', $outlet);

            $this->auditLogService->log(
                event: 'outlet.pay_later_updated',
                module: 'outlets',
                auditable: $outlet,
                description: 'Outlet pay later setting changed',
                before: ['pay_later_enabled' => $old],
                after: ['pay_later_enabled' => $new],
            );
        });
    }

    public function destroy(Outlet $outlet): RedirectResponse
    {
        $this->ensureAccess(request()->user(), $outlet);

        if ($outlet->code === 'PUSAT' || $this->hasOperationalHistory($outlet)) {
            return back()->with('error', 'Outlet yang sudah digunakan tidak dapat dihapus. Nonaktifkan outlet sebagai gantinya.');
        }

        $outlet->delete();

        return back()->with('success', 'Outlet berhasil dihapus.');
    }

    protected function ensureAccess(User $user, Outlet $outlet): void
    {
        abort_unless($user->isSuperAdmin() || $user->outlets()->whereKey($outlet->id)->exists(), 404);
    }

    protected function hasOperationalHistory(Outlet $outlet): bool
    {
        // Reservations cascade with their outlet, and an outlet without a warehouse can hold them (#431 BR-3).
        if (DB::table('dine_reservations')->where('outlet_id', $outlet->id)->exists()) {
            return true;
        }

        $warehouseIds = $outlet->warehouses()->pluck('id');
        if ($warehouseIds->isEmpty()) {
            return false;
        }

        foreach ([
            'transactions', 'carts', 'stock_mutations', 'cashier_shifts', 'purchase_orders',
            'goods_receivings', 'supplier_returns', 'stock_opnames', 'product_batches',
        ] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'warehouse_id')
                && DB::table($table)->whereIn('warehouse_id', $warehouseIds)->exists()) {
                return true;
            }
        }

        return Schema::hasTable('stock_transfers') && (
            DB::table('stock_transfers')->whereIn('source_warehouse_id', $warehouseIds)->exists()
            || DB::table('stock_transfers')->whereIn('destination_warehouse_id', $warehouseIds)->exists()
        );
    }

    protected function hasOpenShift(Outlet $outlet): bool
    {
        return DB::table('cashier_shifts')
            ->whereIn('warehouse_id', $outlet->warehouses()->pluck('id'))
            ->where('status', 'open')
            ->exists();
    }
}
