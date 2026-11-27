import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import toast from "react-hot-toast";
import { router } from "@inertiajs/react";
import i18n from "@/i18n";
import { queueTransaction } from "@/Utils/offlineDb";
import Index from "./Index";

// Only the add-on parts of the POS page (#251): what a pinned add-on adds to the checkout. The panels have own tests.
// Props the page's effects depend on must keep their identity between renders, as Inertia's do.
vi.mock("@inertiajs/react", () => {
    const page = { props: { auth: { user: { id: 1 }, super: true }, errors: {}, flash: {}, lowStockNotifications: [], activeCashierShift: { warehouse_id: 1 } } };

    return { Head: () => null, router: { post: vi.fn(), get: vi.fn(), reload: vi.fn(), replace: vi.fn() }, usePage: () => page };
});
vi.mock("react-hot-toast", () => ({ default: { success: vi.fn(), error: vi.fn() } }));
vi.mock("axios", () => ({
    default: { post: vi.fn(() => Promise.resolve({ data: { data: { items: [], summary: { grand_total: 0 } } } })), get: vi.fn() },
}));
vi.mock("@/Layouts/POSLayout", () => ({ default: ({ children }) => children }));
vi.mock("@/Components/POS/ProductGrid", () => ({ default: () => null }));
vi.mock("@/Components/POS/CartPanel", () => ({ default: () => null }));
vi.mock("@/Components/POS/CustomerSelect", () => ({ default: () => null }));
vi.mock("@/Components/POS/NumpadModal", () => ({ default: () => null }));
vi.mock("@/Components/POS/HeldTransactions", () => ({ default: () => null, HoldButton: () => null }));
vi.mock("@/Components/POS/OpenBills", () => ({ default: () => null }));
vi.mock("@/Hooks/useBarcodeScanner", () => ({ default: () => ({ isScanning: false }) }));
vi.mock("@/Utils/offlineDb", () => ({
    queueTransaction: vi.fn(() => Promise.resolve()),
    getPendingTransactions: vi.fn(() => Promise.resolve([])),
    getPendingCount: vi.fn(() => Promise.resolve(0)),
    updatePendingTransaction: vi.fn(),
    removePendingTransaction: vi.fn(),
}));

const carts = [{ id: 1, product_id: 1, qty: 1, price: 10000, product: { id: 1, title: "Kopi", sell_price: 10000 } }];
const pageProps = {
    carts, heldCarts: [], openBills: [], customers: [], products: [], categories: [], paymentGateways: [], bankAccounts: [],
    warehouses: [], loyaltyTierOptions: [], initialPricingPreview: { items: [], summary: {} },
};

const checkOut = async (query = "") => {
    window.history.pushState({}, "", `/${query}`); // the pinned order is the page's own ?add_on_to
    render(<Index {...pageProps} />);
    // The button shows a spinner until the price preview is back.
    await userEvent.click(await screen.findByRole("button", { name: /Selesaikan Transaksi/ }));
};
const offline = () => vi.spyOn(navigator, "onLine", "get").mockReturnValue(false);

beforeAll(() => i18n.changeLanguage("id"));
beforeEach(() => {
    vi.restoreAllMocks();
    vi.clearAllMocks();
});

describe("POS checkout with an add-on", () => {
    it("sends the original order with the sale", async () => {
        await checkOut("?add_on_to=TRX-1");

        expect(router.post).toHaveBeenCalledWith("/transactions.store", expect.objectContaining({ add_on_to: "TRX-1" }), expect.any(Object));
    });

    it("never queues an add-on offline (BR-7)", async () => {
        offline();

        await checkOut("?add_on_to=TRX-1");

        expect(toast.error).toHaveBeenCalledWith("Pesanan tambahan tidak tersedia saat offline");
        expect(queueTransaction).not.toHaveBeenCalled();
        expect(router.post).not.toHaveBeenCalled();
    });

    it("still queues a normal sale offline", async () => {
        offline();

        await checkOut();

        expect(queueTransaction).toHaveBeenCalledTimes(1);
    });
});
