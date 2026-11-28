// Lives outside Pages/ because the Inertia page glob would bundle a test placed there.
import { beforeAll, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import i18n from "@/i18n";

vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    Link: ({ children, ...props }) => <a {...props}>{children}</a>,
    router: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), reload: vi.fn(), visit: vi.fn() },
    usePage: () => ({ props: { auth: { super: true, user: { id: 1 } }, activeCashierShift: { id: 1, warehouse_id: 1 } } }),
}));
vi.mock("axios", () => ({ default: { get: vi.fn(() => new Promise(() => {})), post: vi.fn(() => new Promise(() => {})) } }));

import Index from "@/Pages/Dashboard/Transactions/Index";

// Stable references: the page copies some of these into state from effects.
const none = [];
const cart = [{ id: 1, product_id: 1, qty: 1, price: 10000, product: { id: 1, title: "Kopi", sell_price: 10000 } }];
const props = { heldCarts: none, openBills: none, customers: none, products: none, categories: none, paymentGateways: none, bankAccounts: none, initialPricingPreview: { items: none, summary: {} } };

beforeAll(() => i18n.changeLanguage("id"));

describe("POS order name", () => {
    // REQ-009 AC-20 (#445): a name belongs to one order.
    it("survives the first item being added and is cleared once the cart is emptied", async () => {
        const { rerender } = render(<Index {...props} carts={none} />);
        const name = () => screen.getByLabelText("Nama pesanan");

        await userEvent.type(name(), "Budi 7");
        rerender(<Index {...props} carts={cart} />);
        expect(name()).toHaveValue("Budi 7");

        rerender(<Index {...props} carts={[]} />);
        expect(name()).toHaveValue("");
    });
});
