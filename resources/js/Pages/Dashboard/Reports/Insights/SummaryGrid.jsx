import {
    IconCoin,
    IconPackage,
    IconReceipt2,
    IconUsers,
} from "@tabler/icons-react";
import SummaryCard from "./SummaryCard";
import { formatCurrency } from "./format";

export default function SummaryGrid({ summary, cashierPerformance }) {
    return (
        <div data-tour="reports-summary" className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <SummaryCard
                title="Pendapatan"
                value={formatCurrency(summary?.revenue_total ?? 0)}
                description={`${summary?.orders_count ?? 0} transaksi`}
                icon={IconReceipt2}
                gradient="from-primary-500 to-primary-700"
            />
            <SummaryCard
                title="Profit"
                value={formatCurrency(summary?.profit_total ?? 0)}
                description={`Rata-rata ${formatCurrency(summary?.average_order ?? 0)}`}
                icon={IconCoin}
                gradient="from-emerald-500 to-emerald-700"
            />
            <SummaryCard
                title="Item Terjual"
                value={(summary?.items_sold ?? 0).toLocaleString("id-ID")}
                description={`Diskon manual ${formatCurrency(summary?.manual_discount_total ?? 0)}`}
                icon={IconPackage}
                gradient="from-amber-500 to-amber-700"
            />
            <SummaryCard
                title="Kasir Aktif di Filter"
                value={cashierPerformance.length.toLocaleString("id-ID")}
                description="Leaderboard performa kasir"
                icon={IconUsers}
                gradient="from-fuchsia-500 to-fuchsia-700"
            />
        </div>
    );
}
