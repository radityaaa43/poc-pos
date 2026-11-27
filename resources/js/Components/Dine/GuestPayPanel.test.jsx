import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import axios from "axios";
import i18n from "@/i18n";
import GuestPayPanel, { mergePayment } from "./GuestPayPanel";

vi.mock("axios", () => ({ default: { post: vi.fn() } }));

const raw = (state, overrides = {}) => ({ state, amount: 20000, attempt: state === "can_pay" ? 0 : 1, payment_url: null, qr: null, ...overrides });
const charge = { payment_url: "https://pay.test/r1", qr: "data:image/svg+xml;base64,QR1" };
const view = (state, overrides) => mergePayment(null, raw(state, overrides));

const panel = (payment, onChange = vi.fn()) => render(<GuestPayPanel token="tok" payment={payment} onChange={onChange} />);
const press = (name) => userEvent.setup().click(screen.getByRole("button", { name }));
const buttons = () => screen.queryAllByRole("button").map((button) => button.textContent);

beforeAll(() => i18n.changeLanguage("id"));
beforeEach(() => {
    axios.post.mockReset();
    axios.post.mockResolvedValue({ data: { payment: raw("pending", charge) } });
});
afterEach(() => vi.useRealTimers());

describe("GuestPayPanel states", () => {
    it("shows nothing while there is nothing to pay", () => {
        const { container } = panel(null);

        expect(container).toBeEmptyDOMElement();
    });

    it("shows the total with Bayar sekarang and the other method", () => {
        panel(view("can_pay"));

        expect(screen.getByText(/Rp\s*20\.000/)).toBeInTheDocument();
        expect(buttons()).toEqual(["Bayar sekarang", "Bayar dengan metode lain"]);
        expect(screen.queryByRole("img", { name: "QRIS" })).toBeNull();
    });

    it.each([
        ["Bayar sekarang", "qris"],
        ["Bayar dengan metode lain", "gateway"],
    ])("%s sends only the method, never an amount, and hands the answer back", async (name, method) => {
        const onChange = vi.fn();
        panel(view("can_pay"), onChange);

        await press(name);

        expect(axios.post).toHaveBeenCalledTimes(1);
        expect(axios.post).toHaveBeenCalledWith("/dine-order.pay", { method });
        expect(onChange).toHaveBeenCalledWith(raw("pending", charge));
    });

    it("ignores a second press while the request is in flight", async () => {
        axios.post.mockReturnValue(new Promise(() => {}));
        panel(view("can_pay"));
        const user = userEvent.setup();

        await user.click(screen.getByRole("button", { name: "Bayar sekarang" }));
        await user.click(screen.getByRole("button", { name: "Bayar sekarang" }));
        await user.click(screen.getByRole("button", { name: "Bayar dengan metode lain" }));

        expect(axios.post).toHaveBeenCalledTimes(1);
        expect(screen.getByRole("button", { name: "Bayar sekarang" })).toBeDisabled();
    });

    it("shows the QRIS, the link in a new tab and Menunggu pembayaran while pending", () => {
        panel(view("pending", charge));

        expect(screen.getByRole("heading", { name: "Menunggu pembayaran" })).toBeInTheDocument();
        expect(screen.getByRole("img", { name: "QRIS" })).toHaveAttribute("src", charge.qr);
        const link = screen.getByRole("link", { name: "Buka di aplikasi e-wallet" });
        expect(link).toHaveAttribute("href", charge.payment_url);
        expect(link).toHaveAttribute("target", "_blank");
        expect(link).toHaveAttribute("rel", "noopener noreferrer");
        expect(screen.getByText(/Rp\s*20\.000/)).toBeInTheDocument();
        expect(buttons()).toEqual(["Bayar dengan metode lain"]);
    });

    it("shows only the link when the gateway gave no QR", () => {
        panel(view("pending", { payment_url: charge.payment_url }));

        expect(screen.queryByRole("img", { name: "QRIS" })).toBeNull();
        expect(screen.getByRole("link", { name: "Buka di aplikasi e-wallet" })).toBeInTheDocument();
    });

    it("moves focus to the charge the guest's press produced", async () => {
        const { rerender } = panel(view("can_pay"));
        await press("Bayar sekarang");

        rerender(<GuestPayPanel token="tok" payment={view("pending", charge)} onChange={vi.fn()} />);

        expect(screen.getByRole("heading", { name: "Menunggu pembayaran" })).toHaveFocus();
    });

    it.each([
        ["failed", "Pembayaran gagal", true],
        ["expired", "Kedaluwarsa", true],
        ["gateway_error", "Pembayaran online sedang bermasalah — bayar di kasir", false],
    ])("shows %s with the total and Coba lagi", async (state, title, counterHint) => {
        panel(view(state));

        expect(screen.getByRole("heading", { name: title })).toBeInTheDocument();
        expect(screen.getByText(/Rp\s*20\.000/)).toBeInTheDocument();
        expect(screen.queryByText("Atau bayar di kasir") !== null).toBe(counterHint);
        expect(screen.queryByRole("img", { name: "QRIS" })).toBeNull();
        expect(buttons()).toEqual(["Coba lagi"]);

        await press("Coba lagi");
        expect(axios.post).toHaveBeenCalledWith("/dine-order.pay", { method: "qris" });
    });

    it("Coba lagi repeats the method of the last press", async () => {
        const { rerender } = panel(view("can_pay"));
        await press("Bayar dengan metode lain");
        rerender(<GuestPayPanel token="tok" payment={view("failed")} onChange={vi.fn()} />);

        await press("Coba lagi");

        expect(axios.post).toHaveBeenLastCalledWith("/dine-order.pay", { method: "gateway" });
    });

    it.each([
        ["paid", "Lunas"],
        ["counter", "Silakan bayar di kasir"],
    ])("shows %s with no button, QR or link", (state, title) => {
        panel(view(state));

        expect(screen.getByRole("heading", { name: title })).toBeInTheDocument();
        expect(screen.queryByRole("button")).toBeNull();
        expect(screen.queryByRole("img", { name: "QRIS" })).toBeNull();
        expect(screen.queryByRole("link")).toBeNull();
    });

    it.each([
        ["Belum bisa dibayar", { status: 422, data: { errors: { payment: ["not_payable"] } } }],
        ["Sudah lunas", { status: 422, data: { errors: { payment: ["already_paid"] } } }],
        ["Silakan bayar di kasir", { status: 422, data: { errors: { payment: ["no_gateway"] } } }],
        ["Pembayaran online sedang bermasalah — bayar di kasir", { status: 422, data: { errors: { payment: ["gateway_error"] } } }],
        ["Terlalu banyak percobaan. Coba lagi sebentar.", { status: 429, data: { message: "Too Many Attempts." } }],
        ["Tidak ada koneksi. Pembayaran belum dibuat.", undefined],
    ])("answers a refused press with %s", async (text, response) => {
        axios.post.mockRejectedValue({ response });
        const onChange = vi.fn();
        panel(view("can_pay"), onChange);

        await press("Bayar sekarang");

        expect(screen.getByText(text)).toBeInTheDocument();
        expect(onChange).not.toHaveBeenCalled();
        expect(screen.getByRole("button", { name: "Bayar sekarang" })).toBeEnabled();
    });

    it("drops the refusal once the bill moves on", async () => {
        axios.post.mockRejectedValue({ response: { status: 422, data: { errors: { payment: ["already_paid"] } } } });
        const { rerender } = panel(view("can_pay"));
        await press("Bayar sekarang");

        rerender(<GuestPayPanel token="tok" payment={view("paid")} onChange={vi.fn()} />);

        expect(screen.queryByText("Sudah lunas")).toBeNull();
        expect(screen.getByRole("heading", { name: "Lunas" })).toBeInTheDocument();
    });

    it("offers Coba lagi after a minute when a pending attempt has no page and no QR", async () => {
        vi.useFakeTimers();
        panel(view("pending"));

        act(() => vi.advanceTimersByTime(59_000));
        expect(screen.queryByRole("button", { name: "Coba lagi" })).toBeNull();

        act(() => vi.advanceTimersByTime(1_000));
        await act(async () => fireEvent.click(screen.getByRole("button", { name: "Coba lagi" })));
        expect(axios.post).toHaveBeenCalledWith("/dine-order.pay", { method: "qris" });
    });

    it("does not offer Coba lagi while the pending charge has its page", () => {
        vi.useFakeTimers();
        panel(view("pending", { payment_url: charge.payment_url }));

        act(() => vi.advanceTimersByTime(120_000));

        expect(screen.queryByRole("button", { name: "Coba lagi" })).toBeNull();
    });

    it("speaks English to an English browser", async () => {
        await i18n.changeLanguage("en");
        try {
            panel(view("can_pay"));

            expect(buttons()).toEqual(["Pay now", "Pay another way"]);
        } finally {
            await i18n.changeLanguage("id");
        }
    });
});

describe("mergePayment", () => {
    const second = { attempt: 2, payment_url: "https://pay.test/r2", qr: "data:image/svg+xml;base64,QR2" };
    const merged = (...answers) => answers.reduce(mergePayment, null);

    it("keeps the QR when an answer for the same attempt arrives late without it", () => {
        const shown = merged(raw("pending", second), raw("pending", { attempt: 2 }));

        expect(shown).toMatchObject({ ...second, state: "pending", charge: 2 });
    });

    it("keeps the QR through the poll, which never carries it", () => {
        expect(merged(raw("pending", second), raw("pending", { ...second, qr: null }))).toMatchObject({ qr: second.qr });
    });

    it("drops the superseded charge as soon as a newer attempt is known, then shows the new one", () => {
        const waiting = merged(raw("pending", charge), raw("pending", { attempt: 2 }));
        expect(waiting).toMatchObject({ attempt: 2, charge: null, qr: null, payment_url: null });

        expect(mergePayment(waiting, raw("pending", second))).toMatchObject({ ...second, charge: 2 });
    });

    it("drops the replaced QR when the newer charge has only a page", () => {
        expect(merged(raw("pending", charge), raw("pending", { ...second, qr: null }))).toMatchObject({
            charge: 2,
            qr: null,
            payment_url: second.payment_url,
        });
    });

    it("ignores an answer for an older attempt", () => {
        const shown = merged(raw("pending", second));

        expect(mergePayment(shown, raw("pending", charge))).toBe(shown);
        expect(mergePayment(shown, raw("failed"))).toBe(shown);
    });

    it("never undoes paid", () => {
        const shown = merged(raw("paid"));

        expect(mergePayment(shown, raw("pending", charge))).toBe(shown);
    });

    it("forgets a dead charge, so a retry never shows its QR", () => {
        const shown = merged(raw("pending", charge), raw("expired"), raw("pending", { attempt: 2 }));

        expect(shown).toMatchObject({ attempt: 2, charge: null, qr: null, payment_url: null });
    });

    it("clears the panel when the server has nothing to show", () => {
        expect(merged(raw("can_pay"), null)).toBeNull();
    });
});
