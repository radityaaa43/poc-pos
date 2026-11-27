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
import Print from "@/Pages/Dashboard/Transactions/Print";

const transaction = {
    id: 1,
    invoice: "INV-1",
    access_token: "tok-123",
    created_at: "2026-11-25T12:00:28.000000Z",
    cashier: { name: "Kasir" },
    customer: null,
    details: [],
    total_items: 1,
    grand_total: 10000,
    payment_method: "qris",
    payment_status: "paid",
    late_superseded_payment_at: "2026-11-25T13:00:00.000000Z",
};

beforeAll(() => i18n.changeLanguage("id"));
beforeEach(() => get.mockReset());

describe("History late-refund marker", () => {
    const renderHistory = (filters = {}, row = transaction) =>
        render(<History transactions={{ data: [row], total: 1 }} filters={filters} />);

    it("marks a sale whose superseded payment was received late", () => {
        renderHistory();

        expect(screen.getAllByText("Bayar ganda — refund manual").length).toBeGreaterThan(0);
    });

    it("has no marker for a normal sale", () => {
        renderHistory({}, { ...transaction, late_superseded_payment_at: null });

        expect(screen.queryByText("Bayar ganda — refund manual")).not.toBeInTheDocument();
    });

    it("filters to the sales that need a refund with the late_payment chip", async () => {
        renderHistory();

        await userEvent.click(screen.getByRole("button", { name: "Perlu refund" }));

        expect(get).toHaveBeenCalledWith(
            "/transactions.history",
            expect.objectContaining({ late_payment: "1" }),
            expect.any(Object)
        );
    });

    it("turns the chip off when it is already active", async () => {
        // The server sends filters.late_payment as a boolean (Request::boolean).
        renderHistory({ late_payment: true });

        const chip = screen.getByRole("button", { name: "Perlu refund" });
        expect(chip).toHaveAttribute("aria-pressed", "true");
        await userEvent.click(chip);

        expect(get).toHaveBeenCalledWith(
            "/transactions.history",
            expect.objectContaining({ late_payment: "" }),
            expect.any(Object)
        );
    });
});

describe("Print retry panel and late-payment notice", () => {
    it("offers retry on a failed sale and shows the notice of a late old payment", () => {
        render(
            <Print
                transaction={{ ...transaction, payment_status: "failed" }}
                retryable
                bankAccounts={[]}
                paymentGateways={[]}
            />
        );

        expect(screen.getByRole("button", { name: "Coba lagi" })).toBeInTheDocument();
        expect(screen.getByRole("status")).toHaveTextContent("perlu refund manual");
    });

    it("shows neither on a paid sale", () => {
        render(<Print transaction={{ ...transaction, late_superseded_payment_at: null }} retryable={false} />);

        expect(screen.queryByRole("button", { name: "Coba lagi" })).not.toBeInTheDocument();
        expect(screen.queryByRole("status")).not.toBeInTheDocument();
    });
});
