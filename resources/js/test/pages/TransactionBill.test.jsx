// Lives outside Pages/ because the Inertia page glob would bundle a test placed there.
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import axios from "axios";
import i18n from "@/i18n";
import Index from "@/Pages/Dashboard/Transactions/Index";

const page = vi.hoisted(() => ({ props: {}, online: true }));

vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    Link: () => null,
    router: { post: vi.fn(), reload: vi.fn() },
    usePage: () => page,
}));
vi.mock("react-hot-toast", () => ({ default: Object.assign(vi.fn(), { success: vi.fn(), error: vi.fn() }) }));
// Offline the preview request fails; online it never answers here, so the first preview stays on screen.
vi.mock("axios", () => ({ default: { post: vi.fn(() => (page.online ? new Promise(() => {}) : Promise.reject(new Error()))) } }));
vi.mock("@/Context/OnlineStatusContext", () => ({ useOnlineStatus: () => page.online }));
vi.mock("@/Utils/offlineDb", () => ({ getPendingTransactions: async () => [], getPendingCount: async () => 0 }));
vi.mock("@/Layouts/POSLayout", () => ({ default: () => null }));
vi.mock("@/Components/POS/ProductGrid", () => ({ default: () => null }));
vi.mock("@/Components/POS/CustomerSelect", () => ({ default: () => null }));
vi.mock("@/Components/POS/OpenBills", () => ({ default: () => null }));
vi.mock("@/Components/POS/HeldTransactions", () => ({ default: () => null, HoldButton: () => null }));

const carts = [{ id: 1, product_id: 1, qty: 2, price: 20000, product: { id: 1, title: "Kopi" } }];
// Stable: the page copies this prop into state, so a new object on every render would never settle.
const pricing = {
    items: [],
    summary: {
        base_subtotal: 20000, service_charge: 1000, tax_kind: "ppn", tax_total: 2310, tax_rate: 11, grand_total: 23310,
        bill_basis: { after_discount: 20000, shipping: 0, line_tax_total: 2200, shipping_tax: 0, effective_rate: 11 },
    },
};
const view = () => render(<Index carts={carts} initialPricingPreview={pricing} />);
const total = () => screen.getByText("Total", { exact: true }).nextElementSibling;

beforeAll(() => i18n.changeLanguage("id"));
beforeEach(() => {
    vi.clearAllMocks();
    localStorage.clear();
    page.online = true;
    page.props = {
        auth: { user: { id: 1 }, super: true },
        flash: {},
        activeCashierShift: {
            warehouse_id: 1,
            outlet: { id: 3, bill_settings: { service_charge_rate: 5, tax_kind: "ppn", pb1_rate: 10 } },
        },
    };
});

describe("POS checkout bill", () => {
    it("previews with the order type and again when it changes", async () => {
        view();
        const orderTypes = () => axios.post.mock.calls.map(([, body]) => body.order_type);

        await waitFor(() => expect(orderTypes()).toEqual(["in_store"]));
        expect(screen.getByText("Service charge")).toBeInTheDocument();
        fireEvent.click(screen.getByRole("button", { name: "Bawa Pulang" }));

        await waitFor(() => expect(orderTypes()).toEqual(["in_store", "takeaway"]));
    });

    it("offline, the order type stays selectable and the total follows it (BR-3)", async () => {
        page.online = false;
        view();

        expect(total()).toHaveTextContent(/23\.310/);
        const takeaway = screen.getByRole("button", { name: "Bawa Pulang" });
        expect(takeaway).toBeEnabled();
        fireEvent.click(takeaway);

        expect(total()).toHaveTextContent(/22\.200/);
        expect(screen.queryByText("Service charge")).not.toBeInTheDocument();
        await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(2));
    });
});
