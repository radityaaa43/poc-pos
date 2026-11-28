import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import toast from "react-hot-toast";
import { router } from "@inertiajs/react";
import i18n from "@/i18n";
import { queueTransaction } from "@/Utils/offlineDb";
import Index from "./Index";

// Only what a pinned add-on adds to the POS checkout (#251). Page props keep their identity between renders, as Inertia's do.
vi.mock("@inertiajs/react", () => {
    const page = { props: { auth: { user: { id: 1 }, super: true }, errors: {}, flash: {}, lowStockNotifications: [], activeCashierShift: { warehouse_id: 1 } } };

    return { Head: () => null, router: { post: vi.fn(), get: vi.fn(), reload: vi.fn(), replace: vi.fn(), on: vi.fn(() => vi.fn()) }, usePage: () => page };
});
vi.mock("react-hot-toast", () => ({ default: { success: vi.fn(), error: vi.fn() } }));
vi.mock("axios", () => ({
    default: { post: vi.fn(() => Promise.resolve({ data: { data: { items: [], summary: { grand_total: 0 } } } })), get: vi.fn() },
}));
vi.mock("@/Layouts/POSLayout", () => ({ default: ({ children }) => children }));
vi.mock("@/Components/POS/ProductGrid", () => ({ default: () => null }));
vi.mock("@/Components/POS/CartPanel", () => ({ default: () => null }));
vi.mock("@/Components/POS/HeldTransactions", () => ({ default: () => null, HoldButton: () => null }));
vi.mock("@/Components/POS/OpenBills", () => ({ default: () => null }));
vi.mock("@/Hooks/useBarcodeScanner", () => ({ default: () => ({ isScanning: false }) }));
vi.mock("@/Utils/offlineDb", () => {
    const none = () => vi.fn(() => Promise.resolve([]));
    return { queueTransaction: none(), getPendingTransactions: none(), getPendingCount: none(), updatePendingTransaction: none(), removePendingTransaction: none() };
});

const carts = [{ id: 1, product_id: 1, qty: 1, price: 10000, product: { id: 1, title: "Kopi", sell_price: 10000 } }];
const pageProps = {
    carts, heldCarts: [], openBills: [], customers: [], products: [], categories: [], paymentGateways: [], bankAccounts: [],
    warehouses: [], loyaltyTierOptions: [], initialPricingPreview: { items: [], summary: {} },
};

const checkOut = async (query = "") => {
    window.history.pushState({}, "", `/${query}`); // the pinned order is the page's own ?add_on_to
    render(<Index {...pageProps} />);
    await userEvent.click(await screen.findByRole("button", { name: /Selesaikan Transaksi/ })); // after the price preview
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
