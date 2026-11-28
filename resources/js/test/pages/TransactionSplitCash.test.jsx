// Lives outside Pages/ because the Inertia page glob would bundle a test placed there.
import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, fireEvent, screen, act } from "@testing-library/react";
import { router } from "@inertiajs/react";
import toast from "react-hot-toast";
import Index from "@/Pages/Dashboard/Transactions/Index";

const page = vi.hoisted(() => ({ props: {} }));

vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    Link: () => null,
    router: { post: vi.fn(), reload: vi.fn() },
    usePage: () => page,
}));
vi.mock("react-hot-toast", () => ({ default: Object.assign(vi.fn(), { success: vi.fn(), error: vi.fn() }) }));
vi.mock("axios", () => ({ default: { post: vi.fn(() => Promise.resolve({ data: { data: { summary: { grand_total: 10000 } } } })) } }));
vi.mock("@/Utils/offlineDb", () => ({ getPendingTransactions: async () => [], getPendingCount: async () => 0 }));
vi.mock("@/Layouts/POSLayout", () => ({ default: () => null }));
vi.mock("@/Components/POS/ProductGrid", () => ({ default: () => null }));
vi.mock("@/Components/POS/CustomerSelect", () => ({ default: () => null }));
vi.mock("@/Components/POS/OpenBills", () => ({ default: () => null }));
vi.mock("@/Components/POS/HeldTransactions", () => ({ default: () => null, HoldButton: () => null }));

const carts = [{ id: 1, product_id: 1, qty: 1, price: 10000, product: { id: 1, title: "Kopi" } }];
// Stable: the page copies this prop into state.
const pricing = { items: [], summary: { grand_total: 10000 } };
const gateways = [{ value: "qris", label: "QRIS" }];

/** Splits Rp 10.000 into cash Rp 4.000 and QRIS Rp 6.000, types the cash received, and pays with F2. */
const paySplit = async (received) => {
    render(<Index carts={carts} initialPricingPreview={pricing} paymentGateways={gateways} />);
    // Let the price preview settle: F2 is ignored while it loads (#500).
    await act(async () => {});
    fireEvent.click(screen.getByRole("button", { name: "Split Pembayaran (2 metode)" }));
    const methods = () => screen.getAllByRole("combobox").filter((select) => select.querySelector('option[value="cash"]'));
    fireEvent.change(methods()[1], { target: { value: "qris" } });
    const [cash, qris] = screen.getAllByPlaceholderText("Nominal");
    fireEvent.change(cash, { target: { value: "4000" } });
    fireEvent.change(qris, { target: { value: "6000" } });
    if (received) {
        fireEvent.change(screen.getByPlaceholderText("Uang diterima (opsional)"), { target: { value: received } });
    }
    fireEvent.keyDown(window, { key: "F2" });
};

beforeEach(() => {
    vi.clearAllMocks();
    page.props = { auth: { user: { id: 1 }, super: true }, activeCashierShift: { warehouse_id: 1 }, flash: {} };
});

// #497: the "optional" cash received field left empty means exact cash, as the payload already treats it.
describe("POS split payment with cash", () => {
    it("accepts an empty cash received as exact cash", async () => {
        await paySplit();

        expect(toast.error).not.toHaveBeenCalled();
        const [, payload] = router.post.mock.calls[0];
        expect(payload.tenders[0]).toMatchObject({ method: "cash", amount: 4000, cash_received: 4000 });
    });

    it("still refuses a typed amount below the cash tender", async () => {
        await paySplit("3000");

        expect(toast.error).toHaveBeenCalledExactlyOnceWith("Uang tunai split kurang dari nominal tender");
        expect(router.post).not.toHaveBeenCalled();
    });
});
