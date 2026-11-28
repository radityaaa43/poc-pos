import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { IconReceipt2 } from "@tabler/icons-react";
import SummaryCard from "./SummaryCard";
import EmptyState from "./EmptyState";
import ChartCard from "./ChartCard";

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
});
