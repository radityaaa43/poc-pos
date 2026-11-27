import { expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import i18n from "@/i18n";
import { queueTransaction } from "@/Utils/offlineDb";
import Show from "./Show";

vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));
vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    Link: ({ children }) => children,
    router: { post: vi.fn() },
    usePage: () => ({ props: { auth: { user: { id: 5 }, permissions: { "cashier-shifts-close": true } }, errors: {} } }),
}));

// REQ-003 BR-12 against the real queue: the POS keys it without a warehouse id ("5:none").
it("disables the close for a sale the POS queued on this device", async () => {
    await i18n.changeLanguage("id");
    await queueTransaction({ client_uuid: "u-1" }, "5:none");

    render(<Show cashierShift={{ id: 9, status: "open", user: { id: 5, name: "Kasir" }, warehouse: { id: 3 }, expected_cash: 0, actual_cash: null }} />);

    expect(await screen.findByRole("button", { name: "Sinkronkan transaksi offline dulu" })).toBeDisabled();
});
