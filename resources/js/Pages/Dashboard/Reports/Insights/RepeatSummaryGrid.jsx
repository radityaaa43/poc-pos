import {
    IconClock,
    IconCoin,
    IconTrendingUp,
    IconUsers,
} from "@tabler/icons-react";
import SummaryCard from "./SummaryCard";
import { formatCurrency, formatPercentage } from "./format";

export default function RepeatSummaryGrid({ repeatSummary, stockCoverageSummary }) {
    return (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <SummaryCard
                title="Customer Aktif"
                value={(repeatSummary.active_customers ?? 0).toLocaleString("id-ID")}
                description={`${repeatSummary.new_customers ?? 0} pelanggan baru`}
                icon={IconUsers}
                gradient="from-sky-500 to-sky-700"
            />
            <SummaryCard
                title="Repeat Rate"
                value={`${formatPercentage(repeatSummary.repeat_rate ?? 0)}%`}
                description={`${repeatSummary.repeat_customers ?? 0} pelanggan repeat`}
                icon={IconTrendingUp}
                gradient="from-violet-500 to-violet-700"
            />
            <SummaryCard
                title="Member Revenue Share"
                value={`${formatPercentage(repeatSummary.member_revenue_share ?? 0)}%`}
                description={formatCurrency(
                    repeatSummary.member_revenue_total ?? 0
                )}
                icon={IconCoin}
                gradient="from-teal-500 to-teal-700"
            />
            <SummaryCard
                title="Stok Perlu Perhatian"
                value={(
                    (stockCoverageSummary.critical ?? 0) +
                    (stockCoverageSummary.low ?? 0)
                ).toLocaleString("id-ID")}
                description={`${stockCoverageSummary.window_days ?? 0} hari jendela analisa`}
                icon={IconClock}
                gradient="from-rose-500 to-rose-700"
            />
        </div>
    );
}
