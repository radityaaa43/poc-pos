import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, fireEvent } from "@testing-library/react";
import { router } from "@inertiajs/react";
import toast from "react-hot-toast";
import Index from "./Index";

const page = vi.hoisted(() => ({ props: {} }));

vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    Link: () => null,
    router: { post: vi.fn(), reload: vi.fn() },
    usePage: () => page,
}));
vi.mock("react-hot-toast", () => ({ default: Object.assign(vi.fn(), { success: vi.fn(), error: vi.fn() }) }));
vi.mock("axios", () => ({ default: { post: vi.fn(() => Promise.resolve({ data: {} })) } }));
vi.mock("@/Utils/offlineDb", () => ({ getPendingTransactions: async () => [], getPendingCount: async () => 0 }));
vi.mock("@/Layouts/POSLayout", () => ({ default: () => null }));
vi.mock("@/Components/POS/ProductGrid", () => ({ default: () => null }));
vi.mock("@/Components/POS/CustomerSelect", () => ({ default: () => null }));
vi.mock("@/Components/POS/OpenBills", () => ({ default: () => null }));
vi.mock("@/Components/POS/HeldTransactions", () => ({ default: () => null, HoldButton: () => null }));

const carts = [{ id: 1, product_id: 1, qty: 1, price: 0, product: { id: 1, title: "Kopi" } }];
// Stable: the page copies this prop into state, so a new default object on every render would never settle.
const pricing = { items: [], summary: {} };
const screen = () => <Index carts={carts} initialPricingPreview={pricing} />;

/** Renders the POS, presses F2 (pay) and returns the view with the callbacks the checkout visit got. */
const checkout = () => {
    const view = render(screen());
    fireEvent.keyDown(window, { key: "F2" });
    const [url, , callbacks] = router.post.mock.calls[0];
    expect(url).toBe("/transactions.store");
    return { view, ...callbacks };
};

beforeEach(() => {
    vi.clearAllMocks();
    page.props = { auth: { user: { id: 1 }, super: true }, activeCashierShift: { warehouse_id: 1 }, flash: {} };
});

// #473: a refusal can arrive as a page without errors, so only the server's success flash proves a sale.
describe("POS checkout toast", () => {
    it("shows the success the server flashed", () => {
        checkout().onSuccess({ props: { flash: { success: "Transaksi berhasil!" } } });

        expect(toast.success).toHaveBeenCalledExactlyOnceWith("Transaksi berhasil!");
    });

    it("shows no success for a page that carries none, such as a refusal whose errors were lost", () => {
        checkout().onSuccess({ props: { flash: { success: null, error: null } } });

        expect(toast.success).not.toHaveBeenCalled();
    });

    it("shows a refusal redirected with an error flash, and no success", () => {
        const { view, onSuccess } = checkout();
        const flash = { success: null, error: "Shift kasir belum dibuka." };

        page.props = { ...page.props, flash };
        view.rerender(screen());
        onSuccess({ props: { flash } });

        expect(toast.error).toHaveBeenCalledExactlyOnceWith("Shift kasir belum dibuka.");
        expect(toast.success).not.toHaveBeenCalled();
    });

    it("shows a refusal that comes back as errors, and no success", () => {
        checkout().onError({ stock: "Stok Kopi tidak mencukupi. Tersedia: 0." });

        expect(toast.error).toHaveBeenCalledExactlyOnceWith("Gagal menyimpan transaksi");
        expect(toast.success).not.toHaveBeenCalled();
    });
});
