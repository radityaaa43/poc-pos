import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { router } from "@inertiajs/react";
import DineMenu from "./DineMenu";

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
