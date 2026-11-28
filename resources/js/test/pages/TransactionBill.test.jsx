// Lives outside Pages/ because the Inertia page glob would bundle a test placed there.
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
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
    summary: { base_subtotal: 20000, service_charge: 1000, tax_kind: "ppn", tax_total: 2310, tax_rate: 11, grand_total: 23310 },
};
const view = () => render(<Index carts={carts} initialPricingPreview={pricing} />);
const total = () => screen.getByText("Total", { exact: true }).nextElementSibling;

beforeAll(() => i18n.changeLanguage("id"));
beforeEach(() => {
    vi.clearAllMocks();
    page.online = true;
    page.props = { auth: { user: { id: 1 }, super: true }, activeCashierShift: { warehouse_id: 1 }, flash: {} };
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

    it("offline at an outlet with a service charge, the order type stays and the screen says why (BR-5)", async () => {
        page.online = false;
        page.props.activeCashierShift.service_charge_rate = 5;
        view();

        await userEvent.click(screen.getByRole("button", { name: "Bawa Pulang" }));

        expect(screen.getByText("Tipe pesanan tidak bisa diubah saat offline di outlet ini")).toBeInTheDocument();
        expect(total()).toHaveTextContent(/23\.310/);
        // Only the preview on opening: the order type never changed.
        await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
        expect(axios.post.mock.calls[0][1].order_type).toBe("in_store");
    });
});
