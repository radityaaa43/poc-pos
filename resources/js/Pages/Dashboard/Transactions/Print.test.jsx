import { describe, it, expect, vi, afterEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
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

afterEach(() => vi.restoreAllMocks());

describe("Print page", () => {
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
