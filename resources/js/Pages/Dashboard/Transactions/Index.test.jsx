import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, fireEvent, act, waitFor } from "@testing-library/react";
import { router } from "@inertiajs/react";
import toast from "react-hot-toast";
import i18n from "@/i18n";
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

/** Renders the POS, lets the pricing preview settle, presses F2 (pay) and returns the checkout visit's callbacks. */
const checkout = async () => {
    const view = render(screen());
    // F2 is ignored while the pricing preview loads (#499), so let the mocked fetch settle before paying.
    await act(async () => {
        await new Promise((resolve) => setTimeout(resolve, 0));
    });
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
    it("shows the success the server flashed", async () => {
        (await checkout()).onSuccess({ props: { flash: { success: "Transaksi berhasil!" } } });

        expect(toast.success).toHaveBeenCalledExactlyOnceWith("Transaksi berhasil!");
    });

    it("shows no success for a page that carries none, such as a refusal whose errors were lost", async () => {
        (await checkout()).onSuccess({ props: { flash: { success: null, error: null } } });

        expect(toast.success).not.toHaveBeenCalled();
    });

    it("shows a refusal redirected with an error flash, and no success", async () => {
        const { view, onSuccess } = await checkout();
        const flash = { success: null, error: "Shift kasir belum dibuka." };

        page.props = { ...page.props, flash };
        view.rerender(screen());
        onSuccess({ props: { flash } });

        expect(toast.error).toHaveBeenCalledExactlyOnceWith("Shift kasir belum dibuka.");
        expect(toast.success).not.toHaveBeenCalled();
    });

    it("shows a refusal that comes back as errors, and no success", async () => {
        (await checkout()).onError({ stock: "Stok Kopi tidak mencukupi. Tersedia: 0." });

        expect(toast.error).toHaveBeenCalledExactlyOnceWith("Gagal menyimpan transaksi");
        expect(toast.success).not.toHaveBeenCalled();
    });
});

describe("POS barcode scan", () => {
    // BR-4: every new text is in both languages; the sold-out toast must not stay Indonesian in the English UI.
    it("toasts Sold out in English for a scanned product with no stock", async () => {
        await i18n.changeLanguage("en");
        try {
            render(
                <Index
                    carts={[]}
                    initialPricingPreview={pricing}
                    products={[{ id: 9, title: "Kopi", barcode: "111", stock: 0 }]}
                />
            );
            for (const key of "111") fireEvent.keyDown(window, { key });
            fireEvent.keyDown(window, { key: "Enter" });

            await waitFor(() => expect(toast.error).toHaveBeenCalledWith("Kopi Sold out"));
        } finally {
            await i18n.changeLanguage("id");
        }
    });
});
