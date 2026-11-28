import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { IconReceipt2 } from "@tabler/icons-react";
import SummaryCard from "./SummaryCard";
import EmptyState from "./EmptyState";
import ChartCard from "./ChartCard";
import SummaryGrid from "./SummaryGrid";
import RepeatSummaryGrid from "./RepeatSummaryGrid";
import TopSellingProductsTable from "./TopSellingProductsTable";

describe("Insights components", () => {
    it("SummaryCard renders its title, value and description", () => {
        render(
            <SummaryCard
                title="Pendapatan"
                value="Rp 1.000"
                description="3 transaksi"
                icon={IconReceipt2}
                gradient="from-primary-500 to-primary-700"
            />
        );

        expect(screen.getByText("Pendapatan")).toBeInTheDocument();
        expect(screen.getByText("Rp 1.000")).toBeInTheDocument();
        expect(screen.getByText("3 transaksi")).toBeInTheDocument();
    });

    it("EmptyState renders its message", () => {
        render(<EmptyState message="Belum ada data untuk periode ini." />);

        expect(
            screen.getByText("Belum ada data untuk periode ini.")
        ).toBeInTheDocument();
    });

    it("ChartCard renders a canvas when it has data", () => {
        const { container } = render(
            <ChartCard
                title="Sales by Hour"
                subtitle="Pola omzet per jam."
                chartRef={{ current: null }}
                hasData
            />
        );

        expect(screen.getByText("Sales by Hour")).toBeInTheDocument();
        expect(screen.getByText("Pola omzet per jam.")).toBeInTheDocument();
        expect(container.querySelector("canvas")).toBeInTheDocument();
    });

    it("SummaryGrid renders the revenue card from the summary", () => {
        render(
            <SummaryGrid
                summary={{ revenue_total: 5000, orders_count: 2 }}
                cashierPerformance={[]}
            />
        );

        expect(screen.getByText("Pendapatan")).toBeInTheDocument();
        expect(screen.getByText("2 transaksi")).toBeInTheDocument();
    });

    it("RepeatSummaryGrid renders the active-customer card", () => {
        render(
            <RepeatSummaryGrid
                repeatSummary={{ active_customers: 7, new_customers: 1 }}
                stockCoverageSummary={{}}
            />
        );

        expect(screen.getByText("Customer Aktif")).toBeInTheDocument();
        expect(screen.getByText("1 pelanggan baru")).toBeInTheDocument();
    });

    it("TopSellingProductsTable renders a product row", () => {
        render(
            <TopSellingProductsTable
                topSellingProducts={[
                    {
                        product_id: 1,
                        product_title: "Kopi Susu",
                        product_sku: "K1",
                        category_name: "Minuman",
                        qty_sold: 3,
                        revenue_total: 30000,
                        profit_total: 10000,
                    },
                ]}
            />
        );

        expect(screen.getByText("Top Selling Products")).toBeInTheDocument();
        expect(screen.getByText("Kopi Susu")).toBeInTheDocument();
    });
});
