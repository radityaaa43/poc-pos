import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { router } from "@inertiajs/react";
import toast from "react-hot-toast";
import Index from "./Index";

const granted = ["dine-orders-process", "dine-orders-prepare", "dine-orders-handover"];
let permissions = granted;

vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    router: { post: vi.fn() },
    usePage: () => ({ props: { auth: { user: { permissions } } } }),
}));
vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));
vi.mock("@/Utils/authorization", () => ({
    useAuthorization: () => ({ can: (permission) => permissions.includes(permission) }),
}));
vi.mock("@/Components/Dashboard/Button", () => ({
    default: ({ label, onClick }) => (
        <button type="button" onClick={onClick}>
            {label}
        </button>
    ),
}));
vi.mock("react-hot-toast", () => ({ default: { success: vi.fn(), error: vi.fn() } }));

const order = (id, table, status, paymentStatus) => ({
    id,
    table: { name: table },
    status,
    item_count: 1,
    subtotal: 10000,
    payment_option: "pay_at_counter",
    items: [],
    has_kitchen_items: true, // the kitchen display tracks it; the cases for an untracked order say so
    transaction: { payment_status: paymentStatus },
});
const untracked = (id, table, status, paymentStatus) => ({ ...order(id, table, status, paymentStatus), has_kitchen_items: false });

const orders = [
    order(1, "Meja Baru", "submitted", null),
    order(2, "Meja Tunggu", "accepted", "unpaid"),
    order(3, "Meja Transfer", "accepted", "pending"),
    order(4, "Meja Lunas", "accepted", "paid"),
    order(5, "Meja Masak", "preparing", "paid"),
    order(6, "Meja Siap", "ready", "paid"),
];

const shown = () => orders.filter((o) => screen.queryByText(o.table.name)).map((o) => o.table.name);

beforeEach(() => {
    permissions = granted;
    globalThis.route = (name) => `/${name}`;
});

describe("dine orders queue", () => {
    it("keeps unpaid and pending-payment orders out of the kitchen view", async () => {
        const user = userEvent.setup();
        render(<Index orders={orders} />);

        await user.click(screen.getByRole("button", { name: "Dapur" }));
        expect(shown()).toEqual(["Meja Lunas", "Meja Masak"]);

        await user.click(screen.getByRole("button", { name: "Menunggu Pembayaran" }));
        expect(shown()).toEqual(["Meja Tunggu", "Meja Transfer"]);

        await user.click(screen.getByRole("button", { name: "Penyerahan" }));
        expect(shown()).toEqual(["Meja Siap"]);
    });

    it("has no Mulai Buat, Siap or Serahkan on orders the kitchen display tracks: it owns those steps", () => {
        render(<Index orders={orders} />);

        expect(screen.queryByRole("button", { name: "Mulai Buat" })).toBeNull();
        expect(screen.queryByRole("button", { name: "Siap" })).toBeNull();
        expect(screen.queryByRole("button", { name: "Serahkan" })).toBeNull();
        expect(screen.getAllByText("Menunggu Pembayaran").length).toBeGreaterThan(0);
    });

    it("gives an order without kitchen items its order-level steps, each posting to the legacy route", async () => {
        const user = userEvent.setup();
        const { unmount } = render(<Index orders={[untracked(4, "Meja Lunas", "accepted", "paid")]} />);
        await user.click(screen.getByRole("button", { name: "Mulai Buat" }));
        expect(router.post).toHaveBeenLastCalledWith("/dine-orders.prepare", {}, expect.any(Object));
        unmount();

        const second = render(<Index orders={[untracked(5, "Meja Masak", "preparing", "paid")]} />);
        await user.click(screen.getByRole("button", { name: "Siap" }));
        expect(router.post).toHaveBeenLastCalledWith("/dine-orders.ready", {}, expect.any(Object));
        second.unmount();

        render(<Index orders={[untracked(6, "Meja Siap", "ready", "paid")]} />);
        await user.click(screen.getByRole("button", { name: "Serahkan" }));
        expect(router.post).toHaveBeenLastCalledWith("/dine-orders.serve", {}, expect.any(Object));
    });

    it("keeps the pay-first gate: no Mulai Buat on an unpaid order without kitchen items", () => {
        render(<Index orders={[untracked(2, "Meja Tunggu", "accepted", "unpaid")]} />);

        expect(screen.queryByRole("button", { name: "Mulai Buat" })).toBeNull();
        expect(screen.getAllByText("Menunggu Pembayaran").length).toBeGreaterThan(0);
    });

    it("shows Mulai Buat and Siap only to the prepare permission", () => {
        permissions = ["dine-orders-process", "dine-orders-handover"];
        render(<Index orders={[untracked(4, "Meja Lunas", "accepted", "paid"), untracked(5, "Meja Masak", "preparing", "paid")]} />);

        expect(screen.queryByRole("button", { name: "Mulai Buat" })).toBeNull();
        expect(screen.queryByRole("button", { name: "Siap" })).toBeNull();
    });

    it("has no Serahkan button on a ready order the kitchen display tracks: it is handed over there", () => {
        render(<Index orders={[{ ...order(6, "Meja Siap", "ready", "paid"), has_kitchen_items: true }]} />);

        expect(screen.queryByRole("button", { name: "Serahkan" })).toBeNull();
        expect(screen.getByText("Meja Siap")).toBeTruthy();
    });

    it("keeps Serahkan on a ready order without kitchen items, its only exit, and posts to the legacy route", async () => {
        const user = userEvent.setup();
        render(<Index orders={[{ ...order(6, "Meja Siap", "ready", "paid"), has_kitchen_items: false }]} />);

        await user.click(screen.getByRole("button", { name: "Serahkan" }));

        expect(router.post).toHaveBeenLastCalledWith("/dine-orders.serve", {}, expect.any(Object));
    });

    it("shows that Serahkan only to the hand-over permission, and never on an order that is not ready", () => {
        permissions = ["dine-orders-process", "dine-orders-prepare"];
        const { unmount } = render(<Index orders={[{ ...order(6, "Meja Siap", "ready", "paid"), has_kitchen_items: false }]} />);
        expect(screen.queryByRole("button", { name: "Serahkan" })).toBeNull();
        unmount();

        permissions = granted;
        render(<Index orders={[{ ...order(5, "Meja Masak", "preparing", "paid"), has_kitchen_items: false }]} />);
        expect(screen.queryByRole("button", { name: "Serahkan" })).toBeNull();
    });

    it("says why an add-on to a cancelled order cannot be accepted", async () => {
        const refusal = "Pesanan asal sudah dibatalkan — buat pesanan baru";
        router.post.mockImplementationOnce((url, data, options) => options.onError({ add_on_to: refusal }));
        vi.stubGlobal("confirm", () => true);
        render(<Index orders={orders} />);

        await userEvent.click(screen.getByRole("button", { name: "Terima" }));

        expect(toast.error).toHaveBeenCalledWith(refusal);
        vi.unstubAllGlobals();
    });

    it("says an order was already processed when it is accepted again", async () => {
        const refusal = "Pesanan ini sudah diproses.";
        router.post.mockImplementationOnce((url, data, options) => options.onError({ order: refusal }));
        vi.stubGlobal("confirm", () => true);
        render(<Index orders={orders} />);

        await userEvent.click(screen.getByRole("button", { name: "Terima" }));

        expect(toast.error).toHaveBeenCalledWith(refusal);
        vi.unstubAllGlobals();
    });

    it("still accepts and rejects a submitted order", async () => {
        const user = userEvent.setup();
        vi.stubGlobal("confirm", () => true);
        vi.stubGlobal("prompt", () => "Habis");
        render(<Index orders={orders} />);

        await user.click(screen.getByRole("button", { name: "Terima" }));
        expect(router.post).toHaveBeenLastCalledWith("/dine-orders.accept", {}, expect.any(Object));

        await user.click(screen.getByRole("button", { name: "Tolak" }));
        expect(router.post).toHaveBeenLastCalledWith("/dine-orders.reject", expect.any(Object), expect.any(Object));
        vi.unstubAllGlobals();
    });
});

describe("dine orders queue, view-only user", () => {
    it("shows no Terima or Tolak button, and no tab name that contains them", () => {
        permissions = ["dine-orders-access"];
        render(<Index orders={orders} />);

        // Playwright matches button names as case-insensitive substrings, so mirror that here (a "Serah-terima" tab once matched "Terima").
        expect(screen.queryAllByRole("button", { name: /terima|tolak/i })).toHaveLength(0);
        expect(screen.queryAllByRole("button", { name: /mulai buat|siap|serahkan/i })).toHaveLength(0);
    });
});

describe("dine orders queue, pay later", () => {
    it("shows staff which payment option the guest chose", () => {
        render(<Index orders={[{ ...order(7, "Meja Nanti", "submitted", null), payment_option: "pay_later" }]} />);

        expect(screen.getByText("Bayar Belakangan")).toBeTruthy();
    });
});
