<?php

namespace App\Http\Controllers\Reports;

use App\Models\SalesReturnItem;
use App\Models\Transaction;
use App\Services\OutletAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** Sales per modifier option (REQ-008 AC-19), with the sales report's outlet scope and date filters. */
class ModifierSalesReportController extends SalesReportController
{
    public function index(Request $request, OutletAccessService $outletAccessService)
    {
        $filters = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ]) + ['start_date' => null, 'end_date' => null];

        $paidSales = $this->applyFilters(
            Transaction::query()->where('payment_status', 'paid'),
            $filters,
            $this->scopedWarehouseIds($request, $outletAccessService)
        )->select('id');

        // A return counts against the period of its sale, so a period never shows a negative quantity.
        $returned = SalesReturnItem::query()
            ->join('sales_returns', 'sales_returns.id', '=', 'sales_return_items.sales_return_id')
            ->where('sales_returns.status', 'completed')
            ->whereIn('sales_returns.transaction_id', $paidSales)
            ->groupBy('transaction_detail_id')
            ->selectRaw('transaction_detail_id, SUM(qty_return) as qty');

        // Grouped by the names sold under, never the master, so a renamed or deleted option stays as it was.
        $rows = DB::table('transaction_detail_modifiers as sold')
            ->join('transaction_details as line', 'line.id', '=', 'sold.transaction_detail_id')
            ->leftJoinSub($returned, 'returned', 'returned.transaction_detail_id', '=', 'line.id')
            ->whereIn('line.transaction_id', $paidSales)
            ->groupBy('modifier_option_id', 'group_name', 'option_name')
            ->selectRaw('modifier_option_id, group_name, option_name,
                SUM(line.qty - COALESCE(returned.qty, 0)) as qty,
                SUM(sold.price_delta * (line.qty - COALESCE(returned.qty, 0))) as revenue')
            ->orderByDesc('revenue')->orderByDesc('qty')
            ->orderBy('group_name')->orderBy('option_name')->orderBy('modifier_option_id')
            ->paginate($this->perPage(50))
            ->withQueryString()
            ->through(fn (object $row) => [...(array) $row, 'qty' => (int) $row->qty, 'revenue' => (int) $row->revenue]);

        return Inertia::render('Dashboard/Reports/ModifierSales', ['rows' => $rows, 'filters' => $filters]);
    }
}
