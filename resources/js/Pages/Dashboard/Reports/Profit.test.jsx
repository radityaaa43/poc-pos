import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import { router } from "@inertiajs/react";
import i18n from "@/i18n";
import Profit from "./Profit";

vi.mock("@inertiajs/react", async (importActual) => ({
    ...(await importActual()),
    Head: () => null,
}));
vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));

const summary = (over = {}) => ({
    profit_total: 15000,
    revenue_total: 40000,
    orders_count: 1,
    items_sold: 1,
    average_profit: 15000,
    margin: 37.5,
    best_invoice: "INV-1",
    best_profit: 15000,
    waste_cost_total: 2000,
    profit_after_waste: 13000,
    ...over,
});
const filters = (over = {}) => ({
    start_date: "",
    end_date: "",
    invoice: "",
    cashier_id: "",
    customer_id: "",
    ...over,
});
const props = (over = {}) => ({
    transactions: { data: [], links: [], current_page: 1, per_page: 10 },
    summary: summary(),
    filters: filters(),
    cashiers: [],
    customers: [],
    ...over,
});

describe("Profit report page: waste line", () => {
    beforeEach(async () => {
        window.route = (name) => `/${name}`;
        vi.spyOn(router, "get").mockImplementation(() => {});
        await i18n.changeLanguage("id");
    });
    afterEach(() => vi.restoreAllMocks());

    it("shows waste cost and profit after waste as their own cards, separate from the sales profit", () => {
        render(<Profit {...props()} />);

        const cost = screen.getByText("Biaya waste").closest("div.relative");
        expect(within(cost).getByText(/Rp\s?2\.000/)).toBeInTheDocument();
        expect(within(cost).getByText("Tidak termasuk HPP penjualan")).toBeInTheDocument();

        const after = screen.getByText("Profit setelah waste").closest("div.relative");
        expect(within(after).getByText(/Rp\s?13\.000/)).toBeInTheDocument();

        // The sales line is unchanged: waste never joins it.
        const sales = screen.getByText("Total Profit").closest("div.relative");
        expect(within(sales).getByText(/Rp\s?15\.000/)).toBeInTheDocument();
        expect(screen.getByRole("link", { name: /Lihat laporan waste/ })).toHaveAttribute(
            "href",
            "/reports.waste.index",
        );
    });

    it("hides both cards and says why while an invoice, cashier or customer filter is set", () => {
        render(
            <Profit
                {...props({
                    summary: summary({ waste_cost_total: null, profit_after_waste: null }),
                    filters: filters({ invoice: "INV-1" }),
                })}
            />,
        );

        expect(screen.queryByText("Biaya waste")).not.toBeInTheDocument();
        expect(screen.queryByText("Profit setelah waste")).not.toBeInTheDocument();
        expect(
            screen.getByText("Biaya waste tidak ditampilkan saat filter faktur, kasir atau pelanggan aktif"),
        ).toBeInTheDocument();
    });

    it("shows a negative profit after waste with its sign", () => {
        render(
            <Profit
                {...props({ summary: summary({ waste_cost_total: 3000, profit_after_waste: -2000 }) })}
            />,
        );

        const after = screen.getByText("Profit setelah waste").closest("div.relative");
        expect(within(after).getByText(/-Rp\s?2\.000/)).toBeInTheDocument();
    });
});
