// Lives outside Pages/ because the Inertia page glob would bundle a test placed there.
import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, fireEvent, screen, act } from "@testing-library/react";
import axios from "axios";
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
vi.mock("axios", () => ({ default: { post: vi.fn() } }));
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

/** Lets the pricing preview answer: the page cannot be paid while it loads. */
const settle = () => act(async () => {});
const show = async () => {
    render(<Index carts={carts} initialPricingPreview={pricing} paymentGateways={gateways} />);
    await settle();
};
const press = (key) => fireEvent.keyDown(window, { key });
const chooseQris = () => fireEvent.click(screen.getByRole("button", { name: /QRIS/ }));
const payload = () => router.post.mock.calls[0][1];

beforeEach(() => {
    vi.clearAllMocks();
    axios.post.mockResolvedValue({ data: { data: pricing } });
    page.props = { auth: { user: { id: 1 }, super: true }, activeCashierShift: { warehouse_id: 1 }, flash: {} };
});

// #499: F2 pays what the screen shows now, not what it showed when the key listener was last registered.
describe("POS keyboard shortcuts", () => {
    it("f2 pays the current split", async () => {
        await show();
        fireEvent.click(screen.getByRole("button", { name: "Split Pembayaran (2 metode)" }));
        const methods = screen.getAllByRole("combobox").filter((select) => select.querySelector('option[value="cash"]'));
        fireEvent.change(methods[1], { target: { value: "qris" } });
        const [cash, qris] = screen.getAllByPlaceholderText("Nominal");
        fireEvent.change(cash, { target: { value: "4000" } });
        fireEvent.change(qris, { target: { value: "6000" } });
        fireEvent.change(screen.getByPlaceholderText("Uang diterima (opsional)"), { target: { value: "5000" } });

        press("F2");

        expect(toast.error).not.toHaveBeenCalled();
        expect(payload().tenders).toMatchObject([
            { method: "cash", amount: 4000, cash_received: 5000 },
            { method: "qris", amount: 6000 },
        ]);
    });

    it("f2 uses the current method", async () => {
        await show();
        chooseQris();

        press("F2");

        expect(toast.error).not.toHaveBeenCalled();
        expect(payload().payment_gateway).toBe("qris");
    });

    it("f2 uses the current discount", async () => {
        await show();
        chooseQris();
        fireEvent.change(screen.getByText("Diskon Manual (Rp)").parentElement.querySelector("input"), {
            target: { value: "1000" },
        });
        await settle();

        press("F2");

        expect(payload().discount).toBe(1000);
    });

    it("f2 waits while the new price is loading", async () => {
        await show();
        chooseQris();
        axios.post.mockReturnValue(new Promise(() => {}));
        fireEvent.change(screen.getByText("Diskon Manual (Rp)").parentElement.querySelector("input"), {
            target: { value: "1000" },
        });

        press("F2");

        expect(router.post).not.toHaveBeenCalled();
    });

    it("f2 twice submits once", async () => {
        await show();
        chooseQris();

        press("F2");
        press("F2");

        expect(router.post).toHaveBeenCalledTimes(1);
    });

    it("f4 opens and closes the shortcut help", async () => {
        await show();

        press("F4");
        expect(screen.getByText("Buka Numpad")).toBeInTheDocument();
        press("F4");

        expect(screen.queryByText("Buka Numpad")).not.toBeInTheDocument();
    });
});
