// Lives outside Pages/ because the Inertia page glob would bundle a test placed there.
import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, fireEvent, screen, act } from "@testing-library/react";
import Index from "@/Pages/Dashboard/Transactions/Index";

const page = vi.hoisted(() => ({ props: {} }));

vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    Link: () => null,
    router: { post: vi.fn(), reload: vi.fn() },
    usePage: () => page,
}));
vi.mock("react-hot-toast", () => ({ default: Object.assign(vi.fn(), { success: vi.fn(), error: vi.fn() }) }));
vi.mock("axios", () => ({ default: { post: vi.fn(() => new Promise(() => {})) } }));
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

/** Splits Rp 10.000 into a Rp 4.000 first part and QRIS Rp 6.000, typing the cash received when given. */
const split = async ({ first = "cash", received } = {}) => {
    render(<Index carts={carts} initialPricingPreview={pricing} paymentGateways={gateways} />);
    await act(async () => {});
    fireEvent.click(screen.getByRole("button", { name: "Split Pembayaran (2 metode)" }));
    const methods = screen.getAllByRole("combobox").filter((select) => select.querySelector('option[value="cash"]'));
    fireEvent.change(methods[0], { target: { value: first } });
    fireEvent.change(methods[1], { target: { value: "qris" } });
    const [a, b] = screen.getAllByPlaceholderText("Nominal");
    fireEvent.change(a, { target: { value: "4000" } });
    fireEvent.change(b, { target: { value: "6000" } });
    if (received) {
        fireEvent.change(screen.getByPlaceholderText("Uang diterima (opsional)"), { target: { value: received } });
    }
};
const changes = () => screen.queryAllByText("Kembalian").map((label) => label.nextElementSibling.textContent);

beforeEach(() => {
    vi.clearAllMocks();
    page.props = { auth: { user: { id: 1 }, super: true }, activeCashierShift: { warehouse_id: 1 }, flash: {} };
});

// #501: in split mode the change due is on the cash part, shown in the footer (the cash panel is not on screen).
describe("POS split payment change", () => {
    it("split shows the change on the cash part", async () => {
        await split({ received: "5000" });

        expect(changes()).toEqual([expect.stringMatching(/1\.000/)]);
    });

    it("split exact cash shows no change", async () => {
        await split();

        expect(changes()).toEqual([]);
    });

    it("split without a cash part shows no change", async () => {
        await split({ first: "qris" });

        expect(changes()).toEqual([]);
    });
});
