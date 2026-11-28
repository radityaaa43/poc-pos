// Lives outside Pages/ because the Inertia page glob would bundle a test placed there.
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import i18n from "@/i18n";

const get = vi.fn();

vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    Link: ({ href, children, ...rest }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: { get: (...args) => get(...args), post: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: { auth: { super: true }, storeProfile: {}, printerSettings: {} } }),
}));
vi.mock("react-hot-toast", () => ({ default: { success: vi.fn(), error: vi.fn() }, Toaster: () => null }));
vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));

import History from "@/Pages/Dashboard/Transactions/History";
import PaidAfterCloseBadge from "@/Pages/Dashboard/Transactions/PaidAfterCloseBadge";
import Sales from "@/Pages/Dashboard/Reports/Sales";

const transaction = {
    id: 1,
    invoice: "INV-1",
    access_token: "tok-123",
    created_at: "2026-11-25T12:00:28.000000Z",
    cashier: { name: "Kasir" },
    customer: null,
    details: [],
    total_items: 1,
    total_profit: 4000,
    grand_total: 10000,
    payment_method: "qris",
    payment_status: "paid",
    late_superseded_payment_at: null,
    paid_after_close_at: "2026-11-25T23:30:00.000000Z",
};

beforeAll(() => i18n.changeLanguage("id"));
beforeEach(() => get.mockReset());

describe("PaidAfterCloseBadge", () => {
    it("shows the marker when the sale was paid after its shift closed", () => {
        render(<PaidAfterCloseBadge transaction={transaction} />);

        expect(screen.getByText("Dibayar setelah shift ditutup")).toBeInTheDocument();
    });

    it("renders nothing for a normal sale", () => {
        const { container } = render(<PaidAfterCloseBadge transaction={{ ...transaction, paid_after_close_at: null }} />);

        expect(container).toBeEmptyDOMElement();
    });

    it("reads in English too", async () => {
        await i18n.changeLanguage("en");
        try {
            render(<PaidAfterCloseBadge transaction={transaction} />);

            expect(screen.getByText("Paid after shift closed")).toBeInTheDocument();
        } finally {
            await i18n.changeLanguage("id");
        }
    });
});

describe("History paid-after-close marker", () => {
    const renderHistory = (filters = {}, row = transaction) =>
        render(<History transactions={{ data: [row], total: 1 }} filters={filters} />);

    it("marks a sale paid after its shift closed", () => {
        renderHistory();

        expect(screen.getAllByText("Dibayar setelah shift ditutup").length).toBeGreaterThan(0);
    });

    it("has no marker for a normal sale", () => {
        renderHistory({}, { ...transaction, paid_after_close_at: null });

        // only the filter chip carries the words, no row badge
        expect(screen.getAllByText("Dibayar setelah shift ditutup")).toHaveLength(1);
        expect(screen.getByRole("button", { name: "Dibayar setelah shift ditutup" })).toBeInTheDocument();
    });

    it("filters to those sales with the paid_after_close chip", async () => {
        renderHistory();

        await userEvent.click(screen.getByRole("button", { name: "Dibayar setelah shift ditutup" }));

        expect(get).toHaveBeenCalledWith(
            "/transactions.history",
            expect.objectContaining({ paid_after_close: "1" }),
            expect.any(Object)
        );
    });

    it("turns the chip off when it is already active", async () => {
        // The server sends filters.paid_after_close as a boolean (Request::boolean).
        renderHistory({ paid_after_close: true });

        const chip = screen.getByRole("button", { name: "Dibayar setelah shift ditutup" });
        expect(chip).toHaveAttribute("aria-pressed", "true");
        await userEvent.click(chip);

        expect(get).toHaveBeenCalledWith(
            "/transactions.history",
            expect.objectContaining({ paid_after_close: "" }),
            expect.any(Object)
        );
    });

    it("keeps the two chips apart: the refund chip does not switch this one on", async () => {
        renderHistory({ late_payment: true });

        expect(screen.getByRole("button", { name: "Dibayar setelah shift ditutup" })).toHaveAttribute("aria-pressed", "false");
    });
});

describe("Sales report paid-after-close line", () => {
    const summary = { orders_count: 3, revenue_total: 90000, discount_total: 0, items_sold: 3, profit_total: 30000, average_order: 30000 };
    const renderSales = (extra = {}, row = transaction) =>
        render(
            <Sales
                transactions={{ data: [row], links: [], current_page: 1, per_page: 10 }}
                summary={{ ...summary, ...extra }}
                filters={{}}
                cashiers={[]}
                customers={[]}
                warehouses={[]}
            />
        );

    it("shows an own line with the count and the total", () => {
        renderSales({ paid_after_close_count: 2, paid_after_close_total: 45000 });

        const line = screen.getByTestId("paid-after-close-line");
        expect(line).toHaveTextContent("Dibayar setelah shift ditutup");
        expect(line).toHaveTextContent("2 transaksi");
        expect(line).toHaveTextContent(/45\.000/);
    });

    it("hides the line when nothing was paid after a close", () => {
        renderSales({ paid_after_close_count: 0, paid_after_close_total: 0 });

        expect(screen.queryByTestId("paid-after-close-line")).not.toBeInTheDocument();
    });

    it("reads the line in English too", async () => {
        await i18n.changeLanguage("en");
        try {
            renderSales({ paid_after_close_count: 2, paid_after_close_total: 45000 });

            const line = screen.getByTestId("paid-after-close-line");
            expect(line).toHaveTextContent("Paid after shift closed: 2 transactions");
            expect(line).toHaveTextContent(/45\.000/);
        } finally {
            await i18n.changeLanguage("id");
        }
    });

    it("marks old rows counted at their sale total with a footnote", () => {
        renderSales({
            paid_after_close_count: 2,
            paid_after_close_total: 45000,
            paid_after_close_estimated_count: 1,
        });

        expect(screen.getByTestId("paid-after-close-estimated-note")).toHaveTextContent(
            "Termasuk 1 transaksi lama yang dihitung sebesar total penjualannya (perkiraan)."
        );
    });

    it("shows no footnote when every row has a stored late amount", () => {
        renderSales({
            paid_after_close_count: 2,
            paid_after_close_total: 45000,
            paid_after_close_estimated_count: 0,
        });

        expect(screen.queryByTestId("paid-after-close-estimated-note")).not.toBeInTheDocument();
    });

    it("shows no footnote when the server sends no estimate count", () => {
        renderSales({ paid_after_close_count: 2, paid_after_close_total: 45000 });

        expect(screen.queryByTestId("paid-after-close-estimated-note")).not.toBeInTheDocument();
    });

    it("copes with a server that sends no such keys", () => {
        renderSales();

        expect(screen.queryByTestId("paid-after-close-line")).not.toBeInTheDocument();
    });

    it("marks the row of such a sale and no other", () => {
        renderSales({ paid_after_close_count: 1, paid_after_close_total: 10000 });
        const marked = screen.getByRole("row", { name: /INV-1/ });
        expect(marked).toHaveTextContent("Dibayar setelah shift ditutup");

        renderSales({}, { ...transaction, id: 2, invoice: "INV-2", paid_after_close_at: null });
        expect(screen.getByRole("row", { name: /INV-2/ })).not.toHaveTextContent("Dibayar setelah shift ditutup");
    });
});
