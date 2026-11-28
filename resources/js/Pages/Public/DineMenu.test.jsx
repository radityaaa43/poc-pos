import { describe, it, expect, vi, beforeAll, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { router } from "@inertiajs/react";
import i18n from "@/i18n";
import DineMenu from "./DineMenu";

beforeAll(() => i18n.changeLanguage("id"));

// The page has no layout, so it renders its own Toaster; without one the guest
// never saw why an order was not sent (#119).
vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    router: { post: vi.fn() },
}));

const props = {
    table: { id: 1, token: "t", name: "M1" },
    categories: [],
    products: [{ id: 7, title: "Kopi", sell_price: 10000, category_id: null }],
    selfOrderEnabled: true,
    storeName: "Toko",
};

beforeEach(() => {
    vi.clearAllMocks();
    router.post.mockReset(); // clearAllMocks keeps a previous test's mockImplementation
    global.route = () => "/dine/t/order";
    window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
    // jsdom has no <dialog> methods; the page only calls them to open/close the cart.
    HTMLDialogElement.prototype.showModal = vi.fn();
    HTMLDialogElement.prototype.close = vi.fn();
});

async function orderOne(overrides = {}) {
    const user = userEvent.setup();
    render(<DineMenu {...props} {...overrides} />);
    await user.click(screen.getByText("+ Tambah"));
    return user;
}

const pesan = () => screen.getByText("Pesan — Bayar di Kasir");

describe("DineMenu order feedback", () => {
    it("sends the order it was opened from (Tambah pesanan) and shows its refusal", async () => {
        const refusal = "Pesanan asal sudah dibatalkan — buat pesanan baru";
        window.history.pushState({}, "", "?add_on_to=tok-1");
        router.post.mockImplementation((url, data, options) => options.onError({ add_on_to: refusal }));
        const user = await orderOne();
        await user.click(pesan());

        expect(router.post.mock.calls[0][1].add_on_to).toBe("tok-1");
        expect(await screen.findByText(refusal)).toBeTruthy();
        await user.click(pesan());
        expect(router.post.mock.calls[1][1].add_on_to).toBeUndefined(); // "Buat pesanan baru": a normal order, no rescan
        window.history.pushState({}, "", "/");
    });

    it("tells the guest when self ordering is off", async () => {
        const user = await orderOne({ selfOrderEnabled: false });
        await user.click(pesan());

        expect(await screen.findByText("Fitur pemesanan sementara nonaktif.")).toBeTruthy();
        expect(router.post).not.toHaveBeenCalled();
    });

    it("tells the guest when the cart was emptied inside the dialog", async () => {
        const user = await orderOne();
        await user.click(screen.getByText("−"));
        await user.click(pesan());

        expect(await screen.findByText("Pilih minimal satu item.")).toBeTruthy();
    });

    it("shows the server's reason, e.g. short stock", async () => {
        router.post.mockImplementation((url, data, opts) =>
            opts.onError({ order: "Stok Kopi tidak mencukupi (tersedia: 0)." })
        );
        const user = await orderOne();
        await user.click(pesan());

        expect(await screen.findByText("Stok Kopi tidak mencukupi (tersedia: 0).")).toBeTruthy();
    });

    it("falls back to a generic message for other validation errors", async () => {
        router.post.mockImplementation((url, data, opts) =>
            opts.onError({ notes: "The notes field must not be greater than 500 characters." })
        );
        const user = await orderOne();
        await user.click(pesan());

        expect(await screen.findByText("Gagal mengirim pesanan.")).toBeTruthy();
    });
});

describe("DineMenu pay later", () => {
    const bayarNanti = () => screen.getByText("Pesan — Bayar Belakangan");
    const helper = "Pesanan langsung dibuat. Bayar di kasir sebelum Anda pergi.";
    const onlineHelper = "Pesanan langsung dibuat. Bayar di kasir atau online dari halaman pesanan sebelum Anda pergi.";

    it("offers only Bayar di Kasir when the outlet has pay later off", async () => {
        await orderOne();

        expect(screen.queryByText("Pesan — Bayar Belakangan")).toBeNull();
        expect(screen.queryByText(helper)).toBeNull();
        expect(screen.queryByText(onlineHelper)).toBeNull();
        expect(pesan()).toBeTruthy();
    });

    // REQ-005 J3 (#328): only an outlet with a ready gateway offers paying online from the order page.
    it("mentions paying online in the helper at a gateway outlet", async () => {
        await orderOne({ payLaterEnabled: true, onlinePayment: true });

        expect(screen.getByText(onlineHelper)).toBeTruthy();
        expect(screen.queryByText(helper)).toBeNull();
    });

    it.each([
        [false, "Your order goes straight to the kitchen. Pay at the counter before you leave."],
        [true, "Your order goes straight to the kitchen. Pay at the counter, or online from the order page, before you leave."],
    ])("speaks English to an English browser (gateway: %s)", async (onlinePayment, english) => {
        await i18n.changeLanguage("en");
        try {
            await orderOne({ payLaterEnabled: true, onlinePayment });

            expect(screen.getByText(english)).toBeTruthy();
        } finally {
            await i18n.changeLanguage("id");
        }
    });

    it("offers a second button with the exact helper text when it is on", async () => {
        const user = await orderOne({ payLaterEnabled: true });

        expect(pesan()).toBeTruthy();
        expect(screen.getByText(helper)).toBeTruthy();

        await user.click(bayarNanti());
        expect(router.post).toHaveBeenCalledWith(
            "/dine/t/order",
            expect.objectContaining({ payment_option: "pay_later" }),
            expect.any(Object)
        );
    });

    it("still posts pay_at_counter from the existing button", async () => {
        const user = await orderOne({ payLaterEnabled: true });
        await user.click(pesan());

        expect(router.post).toHaveBeenCalledWith(
            "/dine/t/order",
            expect.objectContaining({ payment_option: "pay_at_counter" }),
            expect.any(Object)
        );
    });

    it("shows the server's refusal when pay later was switched off while the menu was open", async () => {
        const refusal = "Bayar Belakangan tidak tersedia di outlet ini. Pilih Bayar di kasir.";
        router.post.mockImplementation((url, data, opts) => opts.onError({ order: refusal }));
        const user = await orderOne({ payLaterEnabled: true });
        await user.click(bayarNanti());

        expect(await screen.findByText(refusal)).toBeTruthy();
    });
});

describe("DineMenu online payment (REQ-005)", () => {
    const hint = "Bisa bayar online setelah pesanan diterima";

    it("keeps Bayar di Kasir and no hint at an outlet without a gateway", async () => {
        await orderOne();

        expect(pesan()).toBeTruthy();
        expect(screen.queryByText("Pesan", { exact: true })).toBeNull();
        expect(screen.queryByText(hint)).toBeNull();
    });

    it("says Pesan with the hint at a gateway outlet and still posts pay_at_counter", async () => {
        const user = await orderOne({ onlinePayment: true });

        expect(screen.queryByText("Pesan — Bayar di Kasir")).toBeNull();
        expect(screen.getByText(hint)).toBeTruthy();
        await user.click(screen.getByText("Pesan", { exact: true }));

        expect(router.post).toHaveBeenCalledWith(
            "/dine/t/order",
            expect.objectContaining({ payment_option: "pay_at_counter" }),
            expect.any(Object)
        );
    });
});

describe("DineMenu sold out (REQ-001 AC-04)", () => {
    it("shows a recipe menu short of ingredients as Habis and cannot add it", async () => {
        const user = userEvent.setup();
        render(
            <DineMenu
                {...props}
                products={[{ id: 8, title: "Americano", sell_price: 22000, category_id: null, sold_out: true }]}
            />
        );

        expect(screen.getAllByText("Habis").length).toBeGreaterThan(0);
        const button = screen.getByRole("button", { name: "Habis" });
        expect(button).toBeDisabled();
        await user.click(button);
        expect(router.post).not.toHaveBeenCalled();
    });
});
