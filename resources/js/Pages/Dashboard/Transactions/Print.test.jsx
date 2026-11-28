import { describe, it, expect, vi, afterEach, beforeAll } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import i18n from "@/i18n";
import Print from "./Print";
import { printReceipt } from "@/Utils/escpos";

vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    Link: ({ children, ...props }) => <a {...props}>{children}</a>,
    router: { reload: vi.fn() },
    usePage: () => ({ props: { storeProfile: {}, printerSettings: {}, auth: { super: true } } }),
}));
vi.mock("@/Utils/escpos", () => ({
    hasCachedPrinter: () => false,
    kickDrawer: vi.fn(),
    printReceipt: vi.fn(),
}));

const transaction = {
    id: 1,
    invoice: "INV-1",
    payment_method: "cash",
    payment_status: "paid",
    grand_total: 10000,
    created_at: "2026-11-25T10:00:00Z",
    details: [{ id: 1, qty: 1, price: 10000, product: { title: "Kopi" } }],
};

beforeAll(() => i18n.changeLanguage("id"));
afterEach(() => vi.restoreAllMocks());

describe("Print page", () => {
    it("asks what a pending returned transfer still owes, on the invoice and in the confirm dialog", async () => {
        const pending = { ...transaction, payment_method: "bank_transfer", payment_status: "pending", grand_total: 25000, amount_due: 20000 };
        render(<Print transaction={pending} />);

        expect(screen.getByText("Sisa tagihan").nextElementSibling).toHaveTextContent(/Rp\s*20\.000/);

        await userEvent.click(screen.getByRole("button", { name: "Konfirmasi Bayar" }));

        const asked = screen.getAllByText("Sisa tagihan");
        expect(asked).toHaveLength(2);
        asked.forEach((label) => expect(label.nextElementSibling).toHaveTextContent(/Rp\s*20\.000/));
    });

    // #441 BR-1: the server sends the due date as an ISO timestamp.
    it("shows a credit sale's due date as dd/mm/yyyy, never the raw ISO value", () => {
        const credit = (due_date) => ({ ...transaction, payment_method: "pay_later", payment_status: "unpaid", receivable: { id: 1, due_date } });
        const { rerender } = render(<Print transaction={credit("2026-12-05T00:00:00.000000Z")} />);

        expect(screen.getByText(/Jatuh tempo:/)).toHaveTextContent(/^Jatuh tempo: 05\/12\/2026$/);

        rerender(<Print transaction={credit(null)} />);
        expect(screen.getByText(/Jatuh tempo:/)).toHaveTextContent(/^Jatuh tempo: -$/);
    });

    // REQ-009 AC-20 (#445): the invoice view, both thermal previews and the printer bytes.
    it("shows the order name on every receipt, and no line without one", async () => {
        printReceipt.mockResolvedValue();
        vi.spyOn(window, "open").mockReturnValue(null);
        window.matchMedia = () => ({ matches: false });
        const { unmount } = render(<Print transaction={{ ...transaction, order_name: "Budi 7" }} />);

        expect(screen.getByText("Nama pesanan: Budi 7")).toBeInTheDocument();
        await userEvent.click(screen.getByRole("button", { name: "Struk 80mm" }));
        expect(screen.getByText("Nama pesanan: Budi 7")).toBeInTheDocument();
        await userEvent.click(screen.getByRole("button", { name: "Struk 58mm" }));
        expect(screen.getByText("Nama pesanan: Budi 7")).toBeInTheDocument();
        await userEvent.click(screen.getByRole("button", { name: "Thermal" }));
        expect(printReceipt.mock.calls.at(-1)[0].order_name_line).toBe("Nama pesanan: Budi 7");

        unmount();
        render(<Print transaction={transaction} />);
        expect(screen.queryByText(/Nama pesanan/)).not.toBeInTheDocument();
    });

    // #130: the page has no layout, so it has to render its own Toaster.
    it("shows the toast when thermal printing fails", async () => {
        printReceipt.mockRejectedValue(new Error("Printer belum terhubung."));
        vi.spyOn(window, "open").mockReturnValue(null);
        // jsdom has no matchMedia, which the Toaster reads.
        window.matchMedia = () => ({ matches: false });

        render(<Print transaction={transaction} />);
        await userEvent.click(screen.getByRole("button", { name: /Thermal/ }));

        expect(await screen.findByText(/Printer belum terhubung\./)).toBeInTheDocument();
    });
});
