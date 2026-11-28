import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { router } from "@inertiajs/react";
import i18n from "@/i18n";
import AddOnButton from "./AddOnButton";

let auth;
let online;

vi.mock("@inertiajs/react", () => ({
    router: { get: vi.fn() },
    usePage: () => ({ props: { auth } }),
}));
vi.mock("@/Context/OnlineStatusContext", () => ({ useOnlineStatus: () => online }));

const sale = { id: 7, invoice: "TRX-1", payment_status: "paid", parent: null };
const button = () => screen.queryByRole("button", { name: "Tambah pesanan" });

beforeAll(() => i18n.changeLanguage("id"));
beforeEach(() => {
    vi.clearAllMocks();
    auth = { super: false, permissions: { "transactions-access": true } };
    online = true;
});

describe("AddOnButton", () => {
    it.each([
        ["this order", sale],
        ["the original order, from an add-on's own receipt", { ...sale, invoice: "TRX-2", parent: { id: 7, invoice: "TRX-1" } }],
    ])("opens the POS with %s pinned", async (name, transaction) => {
        render(<AddOnButton transaction={transaction} allowed />);

        await userEvent.click(button());

        expect(router.get).toHaveBeenCalledWith("/transactions.index", { add_on_to: "TRX-1" });
    });

    it.each(["pending", "unpaid", "failed", "expired"])("is shown for a %s sale the server allows", (payment_status) => {
        render(<AddOnButton transaction={{ ...sale, payment_status }} allowed />);

        expect(button()).toBeEnabled();
    });

    it.each([
        ["the server says the order is cancelled", false, { "transactions-access": true }],
        ["the user lacks transactions-access", true, {}],
    ])("is hidden when %s", (name, allowed, permissions) => {
        auth = { super: false, permissions };
        render(<AddOnButton transaction={sale} allowed={allowed} />);

        expect(button()).not.toBeInTheDocument();
    });

    it("is disabled offline and says why", () => {
        online = false;
        render(<AddOnButton transaction={sale} allowed />);

        expect(button()).toBeDisabled();
        expect(button()).toHaveAccessibleDescription("Pesanan tambahan tidak tersedia saat offline");
    });
});
