import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { router } from "@inertiajs/react";
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
    transaction: { payment_status: paymentStatus },
});

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

    it("has no Mulai Buat or Siap button: the kitchen display owns those steps", () => {
        render(<Index orders={orders} />);

        expect(screen.queryByRole("button", { name: "Mulai Buat" })).toBeNull();
        expect(screen.queryByRole("button", { name: "Siap" })).toBeNull();
        expect(screen.getAllByText("Menunggu Pembayaran").length).toBeGreaterThan(0);
    });

    it("has no Serahkan button even for a role with the hand-over permission: the kitchen display owns it", () => {
        render(<Index orders={orders} />);

        expect(screen.queryByRole("button", { name: "Serahkan" })).toBeNull();
        expect(screen.getByText("Meja Siap")).toBeTruthy();
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
