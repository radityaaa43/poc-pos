// Lives outside Pages/ because the Inertia page glob would bundle a test placed there.
import { afterEach, beforeAll, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import axios from "axios";
import i18n from "@/i18n";
import { getPendingTransactions } from "@/Utils/offlineDb";

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
afterEach(() => vi.restoreAllMocks());

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

describe("POS offline sale", () => {
    // #445 BR-4: the next customer must not inherit the last sale's form.
    it("clears the order form once the sale is queued, like after an online sale", async () => {
        axios.post.mockResolvedValue({ data: { data: { items: [], summary: { grand_total: 10000 } } } });
        vi.spyOn(navigator, "onLine", "get").mockReturnValue(false);
        render(<Index {...props} carts={cart} />);
        const note = screen.getByPlaceholderText("Catatan pesanan (opsional)");
        const discount = screen.getByText("Diskon Manual (Rp)").nextElementSibling.querySelector("input");

        await userEvent.click(screen.getByRole("button", { name: "Bawa Pulang" }));
        await userEvent.type(note, "tanpa es");
        await userEvent.type(screen.getByLabelText("Nama pesanan"), "Budi 7");
        await userEvent.type(discount, "500");
        await screen.findByRole("button", { name: /^Kurang/ });
        await userEvent.click(screen.getByRole("button", { name: "Uang pas" }));
        await userEvent.click(await screen.findByRole("button", { name: "Selesaikan Transaksi" }));
        await screen.findByRole("button", { name: "Keranjang Kosong" });

        const [queued] = await getPendingTransactions("1:1");
        expect(queued.data).toMatchObject({ order_type: "takeaway", note: "tanpa es", order_name: "Budi 7", discount: 500 });
        expect(note).toHaveValue("");
        expect(screen.getByRole("button", { name: "Di Tempat" })).toHaveClass("bg-primary-500");
        expect(screen.getByRole("button", { name: "Bawa Pulang" })).not.toHaveClass("bg-primary-500");
        expect(screen.getByLabelText("Nama pesanan")).toHaveValue("");
        expect(discount).toHaveValue("");
    });
});
