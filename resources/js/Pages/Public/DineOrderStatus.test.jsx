import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from "vitest";
import { act, render, screen } from "@testing-library/react";
import { router } from "@inertiajs/react";
import i18n from "@/i18n";
import DineOrderStatus from "./DineOrderStatus";

vi.mock("@inertiajs/react", () => ({ Head: () => null, router: { reload: vi.fn() } }));

const base = { id: 1, access_token: "tok", subtotal: 20000, items: [] };
const table = { id: 1, token: "t", name: "M1" };

const label = (order) => {
    render(<DineOrderStatus order={{ ...base, ...order }} table={table} storeName="Toko" />);
    return screen.getByTestId("dine-status").textContent;
};

beforeAll(() => i18n.changeLanguage("id"));

describe("DineOrderStatus", () => {
    // A completed order only while the server would still link an add-on to it (BR-13).
    it.each([["accepted"], ["preparing"], ["ready"], ["served"], ["completed", true]])("offers Tambah pesanan while the order is %s", (status, add_on_open) => {
        const route = vi.spyOn(globalThis, "route");
        render(<DineOrderStatus order={{ ...base, status, add_on_open }} table={table} storeName="Toko" />);

        expect(screen.getByRole("link", { name: "Tambah pesanan" })).toBeInTheDocument();
        expect(route).toHaveBeenCalledWith("dine.menu", { token: "t", add_on_to: "tok" });
        route.mockRestore();
    });

    it.each(["submitted", "completed", "rejected", "cancelled"])("offers no add-on for a %s order", (status) => {
        render(<DineOrderStatus order={{ ...base, status }} table={table} storeName="Toko" />);

        expect(screen.queryByRole("link", { name: "Tambah pesanan" })).not.toBeInTheDocument();
    });

    it.each([
        ["submitted", "Menunggu Konfirmasi"],
        ["accepted", "Menunggu Pembayaran"],
        ["preparing", "Sedang dibuat"],
        ["ready", "Siap"],
        ["served", "Disajikan"],
        ["completed", "Selesai"],
    ])("shows the %s status", (status, expected) => {
        expect(label({ status })).toBe(expected);
    });

    it("shows Diterima once an accepted order is paid", () => {
        expect(label({ status: "accepted", is_paid: true })).toBe("Diterima");
    });

    it("marks the current step in the progress list once accepted", () => {
        render(<DineOrderStatus order={{ ...base, status: "ready" }} table={table} storeName="Toko" />);
        expect(screen.getByRole("listitem", { current: "step" })).toHaveTextContent("Siap");
        expect(screen.getAllByRole("listitem").map((item) => item.textContent)).toEqual([
            "Diterima",
            "Menunggu Pembayaran",
            "Sedang dibuat",
            "Siap",
            "Disajikan",
            "Selesai",
        ]);
    });
});

describe("DineOrderStatus online payment", () => {
    const charge = { payment_url: "https://pay.test/r1", qr: "data:image/svg+xml;base64,QR1" };
    const payment = (state, overrides = {}) => ({ state, amount: 20000, attempt: 1, payment_url: null, qr: null, ...overrides });
    const counterText = /Silakan menuju kasir untuk membayar/;
    const accepted = (props) => render(<DineOrderStatus order={{ ...base, status: "accepted" }} table={table} storeName="Toko" {...props} />);
    // The next 5 s poll answers with this body.
    const poll = async (body) => {
        globalThis.fetch.mockResolvedValueOnce({ json: async () => body });
        await act(() => vi.advanceTimersByTimeAsync(5000));
    };

    beforeAll(() => i18n.changeLanguage("id"));
    beforeEach(() => {
        vi.useFakeTimers();
        router.reload.mockReset();
        globalThis.fetch = vi.fn();
    });
    afterEach(() => vi.useRealTimers());

    it.each([
        ["nothing to pay online", null, true],
        ["pay at the counter", payment("counter", { attempt: 0 }), true],
        ["can pay online", payment("can_pay", { attempt: 0 }), false],
    ])("keeps the walk to the counter text only when it is right: %s", (_name, value, shown) => {
        accepted({ payment: value });

        expect(screen.queryByText(counterText) !== null).toBe(shown);
    });

    it("shows the pay button as soon as the poll says the order can be paid", async () => {
        render(<DineOrderStatus order={{ ...base, status: "submitted" }} table={table} storeName="Toko" payment={null} />);
        expect(screen.queryByTestId("guest-pay")).toBeNull();

        await poll({ order: { status: "accepted" }, payment: payment("can_pay", { attempt: 0 }) });

        expect(screen.getByRole("button", { name: "Bayar sekarang" })).toBeInTheDocument();
    });

    it("keeps the QR through the poll and flips to Lunas without it", async () => {
        accepted({ payment: payment("pending", charge) });

        await poll({ order: { status: "accepted" }, payment: payment("pending", { payment_url: charge.payment_url }) });
        expect(screen.getByRole("img", { name: "QRIS" })).toHaveAttribute("src", charge.qr);
        expect(router.reload).not.toHaveBeenCalled();

        await poll({ order: { status: "completed", is_paid: true }, payment: payment("paid") });
        expect(screen.getByRole("heading", { name: "Lunas" })).toBeInTheDocument();
        expect(screen.queryByRole("img", { name: "QRIS" })).toBeNull();
        expect(screen.queryByRole("button", { name: /Bayar/ })).toBeNull();
    });

    it("never leaves a superseded QR up: it goes away and the new charge is asked for once", async () => {
        accepted({ payment: payment("pending", charge) });
        const gone = () => {
            expect(screen.queryByRole("img", { name: "QRIS" })).toBeNull();
            expect(screen.queryByRole("link", { name: "Buka di aplikasi e-wallet" })).toBeNull();
        };

        // Another phone claimed attempt 2; its charge is not stored yet, so there is nothing to ask for.
        await poll({ order: { status: "accepted" }, payment: payment("pending", { attempt: 2 }) });
        gone();
        expect(router.reload).not.toHaveBeenCalled();

        const stored = payment("pending", { attempt: 2, payment_url: "https://pay.test/r2" });
        await poll({ order: { status: "accepted" }, payment: stored });
        await poll({ order: { status: "accepted" }, payment: stored });
        expect(screen.queryByRole("img", { name: "QRIS" })).toBeNull();
        expect(router.reload).toHaveBeenCalledTimes(1);
        expect(router.reload).toHaveBeenCalledWith(expect.objectContaining({ only: ["payment"] }));
    });

    // REQ-005 AC-05: a pay-later order is in the kitchen while unpaid, so the banner never says it was paid or waits for payment.
    describe("pay later", () => {
        const later = (state, overrides) => payment(state, { pay_later: true, ...overrides });
        const online = "Pesanan diterima. Belum lunas: bayar sekarang atau di kasir sebelum Anda pergi.";
        const atCounter = "Pesanan diterima. Belum lunas: bayar di kasir sebelum Anda pergi.";

        it.each([
            ["can_pay", online],
            ["pending", online],
            ["failed", online],
            ["expired", online],
            ["gateway_error", online],
            ["counter", atCounter],
        ])("says Diterima and not paid yet while %s", (state, banner) => {
            accepted({ payment: later(state) });

            expect(screen.getByTestId("dine-status")).toHaveTextContent("Diterima");
            expect(screen.getByText(banner)).toBeInTheDocument();
            expect(screen.getByText("Belum lunas")).toBeInTheDocument();
            expect(screen.queryByText(/Pembayaran diterima/)).toBeNull();
            expect(screen.queryByText(counterText)).toBeNull();
            expect(screen.getByRole("listitem", { current: "step" })).toHaveTextContent("Menunggu Pembayaran");
        });

        // A fully returned bill has no payment data: a pay-later order must not be told to pay before it is made.
        it("never sends a pay-later order with nothing to pay to the cashier first", () => {
            render(<DineOrderStatus order={{ ...base, status: "accepted", payment_option: "pay_later" }} table={table} storeName="Toko" payment={null} />);

            expect(screen.queryByText(counterText)).toBeNull();
        });

        it("says the payment was received only once paid", () => {
            render(<DineOrderStatus order={{ ...base, status: "accepted", is_paid: true }} table={table} storeName="Toko" payment={later("paid")} />);

            expect(screen.getByText(/Pembayaran diterima/)).toBeInTheDocument();
            expect(screen.queryByText(/Belum lunas/)).toBeNull();
        });

        it("shows Lunas and Selesai from one poll when a served order is paid", async () => {
            render(<DineOrderStatus order={{ ...base, status: "served" }} table={table} storeName="Toko" payment={later("pending", charge)} />);
            expect(screen.getByText("Belum lunas")).toBeInTheDocument();

            await poll({ order: { status: "completed", is_paid: true }, payment: later("paid") });

            expect(screen.getByRole("heading", { name: "Lunas" })).toBeInTheDocument();
            expect(screen.getByTestId("dine-status")).toHaveTextContent("Selesai");
            expect(screen.queryByText("Belum lunas")).toBeNull();
        });

        it.each([
            ["can_pay", "Order received. Not paid yet: pay now or at the counter before you leave."],
            ["counter", "Order received. Not paid yet: please pay at the counter before you leave."],
        ])("speaks English to an English browser: %s", async (state, banner) => {
            await i18n.changeLanguage("en");
            try {
                accepted({ payment: later(state) });

                expect(screen.getByText(banner)).toBeInTheDocument();
                expect(screen.getByText("Not paid yet")).toBeInTheDocument();
            } finally {
                await i18n.changeLanguage("id");
            }
        });
    });

    it("asks once for the QR of a charge started elsewhere", async () => {
        accepted({ payment: payment("can_pay", { attempt: 0 }) });
        const elsewhere = payment("pending", { payment_url: charge.payment_url });

        await poll({ order: { status: "accepted" }, payment: elsewhere });
        await poll({ order: { status: "accepted" }, payment: elsewhere });

        expect(router.reload).toHaveBeenCalledTimes(1);
        expect(router.reload).toHaveBeenCalledWith(expect.objectContaining({ only: ["payment"] }));

        act(() => router.reload.mock.calls[0][0].onSuccess({ props: { payment: { ...elsewhere, qr: charge.qr } } }));
        expect(screen.getByRole("img", { name: "QRIS" })).toHaveAttribute("src", charge.qr);
    });
});
