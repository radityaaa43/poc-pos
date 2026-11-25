import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
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

        await user.click(screen.getByRole("button", { name: "Serah-terima" }));
        expect(shown()).toEqual(["Meja Siap"]);
    });

    it("offers Mulai Buat only on a paid accepted order", () => {
        render(<Index orders={orders} />);

        expect(screen.getAllByRole("button", { name: "Mulai Buat" })).toHaveLength(1);
        expect(screen.getAllByText("Menunggu Pembayaran").length).toBeGreaterThan(0);
    });

    it("shows each step button only with its permission", () => {
        permissions = ["dine-orders-process"];
        render(<Index orders={orders} />);

        expect(screen.queryByRole("button", { name: "Mulai Buat" })).toBeNull();
        expect(screen.queryByRole("button", { name: "Serahkan" })).toBeNull();
        expect(screen.getByRole("button", { name: "Terima" })).toBeTruthy();
    });
});
