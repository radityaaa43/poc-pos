import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import i18n from "@/i18n";
import RetryPaymentPanel from "./RetryPaymentPanel";

const post = vi.fn();
let auth = { super: false, permissions: { "transactions-access": true } };

vi.mock("@inertiajs/react", () => ({
    router: { post: (...args) => post(...args) },
    usePage: () => ({ props: { auth } }),
}));

const failed = { id: 7, invoice: "INV-7", payment_method: "qris", payment_status: "failed", grand_total: 50000 };
const bankAccounts = [{ id: 3, bank_name: "BCA", account_number: "123", account_name: "Toko" }];
const paymentGateways = [
    { value: "bank_transfer", label: "Transfer Bank" },
    { value: "midtrans", label: "Midtrans" },
];

const renderPanel = (props = {}) =>
    render(
        <RetryPaymentPanel
            transaction={failed}
            retryable
            bankAccounts={bankAccounts}
            paymentGateways={paymentGateways}
            {...props}
        />
    );

beforeAll(() => i18n.changeLanguage("id"));
beforeEach(() => {
    post.mockReset();
    auth = { super: false, permissions: { "transactions-access": true } };
});

describe("RetryPaymentPanel", () => {
    it.each(["failed", "expired"])("offers both actions for a %s sale", (payment_status) => {
        renderPanel({ transaction: { ...failed, payment_status } });

        expect(screen.getByRole("button", { name: "Coba lagi" })).toBeEnabled();
        expect(screen.getByRole("button", { name: "Bayar dengan cara lain" })).toBeEnabled();
    });

    it("names the rest of a split sale and offers only another way", () => {
        renderPanel({ transaction: { ...failed, payment_method: "split", retry_amount: 15000 } });

        expect(screen.getByText("Sisa pembayaran belum diterima.")).toBeInTheDocument();
        expect(screen.getByText(/Sisa tagihan: Rp\s*15\.000/)).toBeInTheDocument();
        // Splitting the rest again is not offered: the cashier picks one method for what is left.
        expect(screen.queryByRole("button", { name: "Coba lagi" })).not.toBeInTheDocument();
    });

    it("says what the sale still owes and computes the change from it", async () => {
        const user = userEvent.setup();
        renderPanel({ transaction: { ...failed, retry_amount: 30000 } });

        expect(screen.getByText(/Sisa tagihan: Rp\s*30\.000/)).toBeInTheDocument();
        await user.click(screen.getByRole("button", { name: "Bayar dengan cara lain" }));
        await user.type(screen.getByLabelText("Uang diterima"), "50000");

        expect(screen.getByText(/Kembalian: Rp\s*20\.000/)).toBeInTheDocument();
    });

    it("renders nothing when the sale is not retryable (paid, pending, restocked)", () => {
        renderPanel({ retryable: false, transaction: { ...failed, payment_status: "paid" } });

        expect(screen.queryByRole("button")).not.toBeInTheDocument();
    });

    it("offers no Coba lagi for a failed credit sale, which the server sends as not retryable", () => {
        renderPanel({ retryable: false, transaction: { ...failed, receivable: { id: 1, status: "unpaid" } } });

        expect(screen.queryByRole("button", { name: "Coba lagi" })).not.toBeInTheDocument();
        expect(screen.queryByRole("button", { name: "Bayar dengan cara lain" })).not.toBeInTheDocument();
    });

    it("renders nothing without the transactions-access permission", () => {
        auth = { super: false, permissions: {} };
        renderPanel();

        expect(screen.queryByRole("button")).not.toBeInTheDocument();
    });

    it("names a failed sale Pembayaran gagal and an expired one Kedaluwarsa", () => {
        const { rerender } = renderPanel();
        expect(screen.getByText("Pembayaran gagal")).toBeInTheDocument();

        rerender(<RetryPaymentPanel transaction={{ ...failed, payment_status: "expired" }} retryable bankAccounts={bankAccounts} paymentGateways={paymentGateways} />);
        expect(screen.getByText("Kedaluwarsa")).toBeInTheDocument();
    });

    it("does not offer Coba lagi for a method that cannot be started again (split, bank transfer, cash)", () => {
        for (const payment_method of ["split", "bank_transfer", "cash"]) {
            const { unmount } = renderPanel({ transaction: { ...failed, payment_method } });

            expect(screen.queryByRole("button", { name: "Coba lagi" })).not.toBeInTheDocument();
            expect(screen.getByRole("button", { name: "Bayar dengan cara lain" })).toBeEnabled();
            unmount();
        }
    });

    it("uses the bank accounts that arrive after the panel was first shown", async () => {
        post.mockImplementation(() => {});
        const { rerender } = renderPanel({ bankAccounts: [], paymentGateways: [] });
        // The receipt was polled into `failed`: the server now sends the accounts and gateways.
        rerender(<RetryPaymentPanel transaction={failed} retryable bankAccounts={bankAccounts} paymentGateways={paymentGateways} />);

        await userEvent.click(screen.getByRole("button", { name: "Bayar dengan cara lain" }));
        await userEvent.selectOptions(screen.getByLabelText("Metode pembayaran"), "bank_transfer");
        await userEvent.click(screen.getByRole("button", { name: "Bayar" }));

        expect(post).toHaveBeenCalledWith(
            "/transactions.retry-payment",
            { payment_method: "bank_transfer", bank_account_id: 3 },
            expect.any(Object)
        );
    });

    it("Coba lagi posts the same payment method again", async () => {
        renderPanel();

        await userEvent.click(screen.getByRole("button", { name: "Coba lagi" }));

        expect(post).toHaveBeenCalledWith(
            "/transactions.retry-payment",
            { payment_method: "qris" },
            expect.objectContaining({ preserveScroll: true })
        );
    });

    it("pays another way in cash and shows the change", async () => {
        renderPanel();

        await userEvent.click(screen.getByRole("button", { name: "Bayar dengan cara lain" }));
        await userEvent.type(screen.getByRole("spinbutton", { name: "Uang diterima" }), "60000");

        expect(screen.getByText(/Kembalian/)).toHaveTextContent("10.000");
        await userEvent.click(screen.getByRole("button", { name: "Bayar" }));

        expect(post).toHaveBeenCalledWith(
            "/transactions.retry-payment",
            { payment_method: "cash", cash_amount: 60000 },
            expect.objectContaining({ preserveScroll: true })
        );
    });

    it("pays another way by bank transfer with the chosen account", async () => {
        renderPanel();

        await userEvent.click(screen.getByRole("button", { name: "Bayar dengan cara lain" }));
        await userEvent.selectOptions(screen.getByRole("combobox", { name: "Metode pembayaran" }), "bank_transfer");
        await userEvent.selectOptions(screen.getByRole("combobox", { name: "Rekening tujuan" }), "3");
        await userEvent.click(screen.getByRole("button", { name: "Bayar" }));

        expect(post).toHaveBeenCalledWith(
            "/transactions.retry-payment",
            { payment_method: "bank_transfer", bank_account_id: 3 },
            expect.any(Object)
        );
    });

    it("pays another way through a gateway", async () => {
        renderPanel();

        await userEvent.click(screen.getByRole("button", { name: "Bayar dengan cara lain" }));
        await userEvent.selectOptions(screen.getByRole("combobox", { name: "Metode pembayaran" }), "midtrans");
        await userEvent.click(screen.getByRole("button", { name: "Bayar" }));

        expect(post).toHaveBeenCalledWith("/transactions.retry-payment", { payment_method: "midtrans" }, expect.any(Object));
    });

    it("shows the server errors and re-enables the buttons", async () => {
        post.mockImplementation((_url, _data, options) => {
            options.onStart();
            options.onError({ payment_method: "Pembayaran ini tidak bisa diulang" });
            options.onFinish();
        });
        renderPanel();

        await userEvent.click(screen.getByRole("button", { name: "Coba lagi" }));

        expect(screen.getByRole("alert")).toHaveTextContent("Pembayaran ini tidak bisa diulang");
        expect(screen.getByRole("button", { name: "Coba lagi" })).toBeEnabled();
    });

    it("keeps showing the refusal when the reloaded sale is no longer retryable (restocked)", async () => {
        post.mockImplementation((_url, _data, options) => {
            options.onStart();
            options.onError({ payment: "Penjualan ini sudah dibatalkan otomatis dan stoknya dikembalikan — buat penjualan baru." });
            options.onFinish();
        });
        const { rerender } = renderPanel();

        await userEvent.click(screen.getByRole("button", { name: "Coba lagi" }));
        // Inertia re-renders the page with fresh props: the sale is not retryable any more.
        rerender(<RetryPaymentPanel transaction={failed} retryable={false} bankAccounts={bankAccounts} paymentGateways={paymentGateways} />);

        expect(screen.getByRole("alert")).toHaveTextContent("Penjualan ini sudah dibatalkan otomatis");
        expect(screen.queryByRole("button")).not.toBeInTheDocument();
    });

    it("disables the buttons while the request is in flight", async () => {
        post.mockImplementation((_url, _data, options) => options.onStart());
        renderPanel();

        await userEvent.click(screen.getByRole("button", { name: "Coba lagi" }));

        expect(screen.getByRole("button", { name: "Coba lagi" })).toBeDisabled();
        expect(screen.getByRole("button", { name: "Bayar dengan cara lain" })).toBeDisabled();
    });
});
