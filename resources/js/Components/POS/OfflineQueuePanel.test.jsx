import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { act, render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import axios from "axios";
import i18n from "@/i18n";
import {
    clearPendingTransactions,
    getPendingTransactions,
    getUnsyncedCount,
    queueTransaction,
    updatePendingTransaction,
} from "@/Utils/offlineDb";
import { markFailed } from "@/Utils/offlineQueue";
import OfflineQueuePanel from "./OfflineQueuePanel";

let online = true;
vi.mock("@/Context/OnlineStatusContext", () => ({ useOnlineStatus: () => online }));
vi.mock("axios", () => ({ default: { post: vi.fn() } }));
vi.mock("@/Components/Dashboard/Modal", () => ({
    default: ({ children, title, show }) =>
        show ? (
            <div role="dialog" aria-label={title}>
                {children}
            </div>
        ) : null,
}));

const SCOPE = "5:none";
const NOT_YET = "Transaksi ini belum tercatat di server. Catat ulang secara online dulu, lalu hapus dari sini.";
const CONFIRM = "Hapus transaksi offline ini dari perangkat? Penghapusan dicatat di audit log.";
const ON_SERVER = "Transaksi ini sudah tercatat di server.";
const AUDITED = { status: 201, data: { data: { audit_id: 1 } } };

// The shape the POS queues (Transactions/Index.jsx).
const sale = (uuid, extra = {}) => ({
    client_uuid: uuid,
    customer_id: 3,
    discount: 1000,
    redeem_points: 5,
    customer_voucher_id: "7",
    shipping_cost: 5000,
    grand_total: 25000,
    cash: 30000,
    payment_method: "cash",
    payment_gateway: null,
    bank_account_id: null,
    order_type: "delivery",
    note: "Antar sore",
    cart_ids: [8],
    items: [{ product_id: 12, unit_id: 1, qty: 2 }],
    ...extra,
});
const queue = (uuid, meta = {}, extra = {}) => queueTransaction(sale(uuid, extra), SCOPE, meta);
const failedRow = (uuid = "u-failed", meta = {}) =>
    queue(uuid, { status: "failed", last_error: "Stok tidak cukup.", attempts: 2, ...meta });

const props = { scopeKey: SCOPE, count: 1 };
const mount = (extra = {}) => render(<OfflineQueuePanel {...props} onRetry={vi.fn()} onChange={vi.fn()} {...extra} />);

async function open(extra) {
    const user = userEvent.setup();
    const view = mount(extra);
    await user.click(screen.getByRole("button", { name: /Transaksi offline tertunda|Pending offline sales/ }));
    return { user, ...view };
}

// Opens the confirmation of the only listed row and confirms it.
async function removeOnlyRow(user) {
    await user.click(await screen.findByRole("button", { name: "Hapus" }));
    // Opening the confirmation runs the idempotent retry first, so wait for the dialog before confirming.
    await screen.findByText(CONFIRM);
    await user.click(screen.getByRole("button", { name: "Hapus" }));
}

beforeAll(() => i18n.changeLanguage("id"));
beforeEach(async () => {
    vi.clearAllMocks();
    online = true;
    await clearPendingTransactions();
});

describe("OfflineQueuePanel: list", () => {
    it("shows no button while nothing is pending", () => {
        mount({ count: 0 });
        expect(screen.queryByRole("button")).not.toBeInTheDocument();
    });

    it("lists every unsynced row with its reason", async () => {
        await queue("u-pending");
        await queue("u-syncing", { status: "syncing", last_attempt_at: new Date().toISOString() });
        await failedRow();
        await queue("u-conflict", { status: "conflict", last_error: "UUID sudah dipakai dengan payload berbeda." }, { payment_method: "bank_transfer" });
        await queue("u-synced", { status: "synced" });
        await queue("u-duplicate", { status: "duplicate" });

        await open({ count: 4, products: [{ id: 12, title: "Kopi Susu" }] });

        const items = await screen.findAllByRole("listitem");
        expect(items).toHaveLength(4);
        // Newest first.
        expect(items[0]).toHaveTextContent("Konflik");
        expect(items[0]).toHaveTextContent("UUID sudah dipakai dengan payload berbeda.");
        expect(items[0]).toHaveTextContent(ON_SERVER);
        expect(items[0]).toHaveTextContent("Transfer Bank");
        expect(items[1]).toHaveTextContent("Gagal");
        expect(items[1]).toHaveTextContent("Stok tidak cukup.");
        expect(items[1]).toHaveTextContent("2 × Kopi Susu");
        expect(items[1]).toHaveTextContent(/25\.000/);
        expect(items[1]).toHaveTextContent("Tunai");
        expect(items[1].querySelector("time")).toHaveAttribute("datetime");
        expect(items[2]).toHaveTextContent("Sedang dikirim");
        expect(items[3]).toHaveTextContent("Menunggu dikirim");
        // Only a refused row can be removed; one in flight or still to be sent cannot.
        expect(within(items[0]).getByRole("button", { name: "Hapus" })).toBeEnabled();
        expect(within(items[1]).getByRole("button", { name: "Hapus" })).toBeEnabled();
        expect(within(items[2]).queryByRole("button", { name: "Hapus" })).not.toBeInTheDocument();
        expect(within(items[3]).queryByRole("button", { name: "Hapus" })).not.toBeInTheDocument();
        // A flush is running.
        expect(screen.getByRole("button", { name: "Coba sinkron sekarang" })).toBeDisabled();
    });

    it("names a line by the title stored with the queued sale, then the product list", async () => {
        const items = [
            { product_id: 12, unit_id: 1, qty: 2 },
            { product_id: 13, unit_id: 1, qty: 1 },
        ];
        // Sold out since: the POS list no longer has product 12.
        await queue("u-1", { product_titles: { 12: "Kopi Tercatat" } }, { items });

        await open({ products: [{ id: 13, title: "Teh Manis" }] });

        expect(await screen.findByText("2 × Kopi Tercatat")).toBeInTheDocument();
        expect(screen.getByText("1 × Teh Manis")).toBeInTheDocument();
    });

    it("falls back to product id", async () => {
        const items = [
            { product_id: 12, unit_id: 1, qty: 2 },
            { product_id: 999, unit_id: null, qty: 1 },
        ];
        await queue("u-1", { status: "failed" }, { items });

        const { user } = await open({ products: [{ id: 12, title: "Kopi Susu" }] });

        expect(await screen.findByText("1 × Produk #999")).toBeInTheDocument();
        expect(screen.getByText("2 × Kopi Susu")).toBeInTheDocument();
        // The confirmation names the products too.
        await user.click(screen.getByRole("button", { name: "Hapus" }));
        expect(screen.getByText("2 × Kopi Susu")).toBeInTheDocument();
    });

    it("says when nothing is pending", async () => {
        await open();
        expect(await screen.findByText("Tidak ada transaksi offline yang tertunda.")).toBeInTheDocument();
    });

    it("renders in English", async () => {
        await i18n.changeLanguage("en");
        try {
            await failedRow();
            const { user } = await open();

            expect(screen.getByRole("button", { name: "Pending offline sales (1)" })).toBeInTheDocument();
            expect(await screen.findByRole("button", { name: "Retry now" })).toBeEnabled();
            await user.click(screen.getByRole("button", { name: "Remove" }));
            expect(await screen.findByText("This sale is not recorded on the server yet. Ring it up again online first, then remove it here.")).toBeInTheDocument();
            expect(screen.getByText("Remove this offline sale from the device? The removal is recorded in the audit log.")).toBeInTheDocument();
        } finally {
            await i18n.changeLanguage("id");
        }
    });

    it("re-reads when the flush writes a result", async () => {
        const id = await failedRow();
        await open();
        await screen.findByText("Stok tidak cukup.");

        await act(() => markFailed({ id }, "Server tidak tersedia."));

        expect(await screen.findByText("Server tidak tersedia.")).toBeInTheDocument();
    });
});

describe("OfflineQueuePanel: retry now", () => {
    it("runs the flush, then re-reads the list", async () => {
        const id = await failedRow();
        const onRetry = vi.fn(() => updatePendingTransaction(id, { status: "synced" }));
        const { user } = await open({ onRetry });

        await user.click(await screen.findByRole("button", { name: "Coba sinkron sekarang" }));

        expect(onRetry).toHaveBeenCalledTimes(1);
        expect(await screen.findByText("Tidak ada transaksi offline yang tertunda.")).toBeInTheDocument();
    });

    it("is disabled offline", async () => {
        online = false;
        await failedRow();
        await open();

        expect(await screen.findByRole("button", { name: "Coba sinkron sekarang" })).toBeDisabled();
    });
});

describe("OfflineQueuePanel: remove", () => {
    it("remove is disabled offline", async () => {
        online = false;
        await failedRow();
        await open();

        expect(await screen.findByRole("button", { name: "Hapus" })).toBeDisabled();
        expect(screen.getByText("Hapus hanya bisa saat online.")).toBeInTheDocument();
    });

    it("failed row confirmation warns to ring it up again first", async () => {
        await failedRow();
        const { user } = await open();

        await user.click(await screen.findByRole("button", { name: "Hapus" }));

        const warning = screen.getByText(NOT_YET);
        const confirm = screen.getByText(CONFIRM);
        expect(warning.compareDocumentPosition(confirm) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        // The row's content is shown with the question.
        expect(screen.getByText("2 × Produk #12")).toBeInTheDocument();
        expect(screen.queryByText(ON_SERVER)).not.toBeInTheDocument();
        expect(axios.post).not.toHaveBeenCalled();
    });

    it("does not offer to discard a failed row that reached the server on the retry", async () => {
        const id = await failedRow();
        const onRetry = vi.fn(() => updatePendingTransaction(id, { status: "synced" }));
        const { user } = await open({ onRetry });

        await user.click(await screen.findByRole("button", { name: "Hapus" }));

        // The retry is idempotent by client_uuid: the row synced, so there is nothing to warn about or discard.
        expect(onRetry).toHaveBeenCalledTimes(1);
        expect(await screen.findByText("Tidak ada transaksi offline yang tertunda.")).toBeInTheDocument();
        expect(screen.queryByText(NOT_YET)).not.toBeInTheDocument();
        expect(axios.post).not.toHaveBeenCalled();
    });

    it("a conflict row's confirmation says the server has the sale", async () => {
        await queue("u-conflict", { status: "conflict" });
        const { user } = await open();

        await user.click(await screen.findByRole("button", { name: "Hapus" }));

        expect(screen.getByText(ON_SERVER)).toBeInTheDocument();
        expect(screen.getByText(CONFIRM)).toBeInTheDocument();
        expect(screen.queryByText(NOT_YET)).not.toBeInTheDocument();
    });

    it("cancelling keeps the row and sends nothing", async () => {
        await failedRow();
        const { user } = await open();

        await user.click(await screen.findByRole("button", { name: "Hapus" }));
        await user.click(await screen.findByRole("button", { name: "Batal" }));

        expect(await screen.findByText("Stok tidak cukup.")).toBeInTheDocument();
        expect(axios.post).not.toHaveBeenCalled();
    });

    // Switching views unmounts the focused button: focus must stay inside the dialog.
    it("gives the focus back to the row's Hapus after Batal, and to Tutup after a removal", async () => {
        axios.post.mockResolvedValue(AUDITED);
        await failedRow();
        await queue("u-conflict", { status: "conflict" });
        const { user } = await open({ count: 2 });
        const second = async () => (await screen.findAllByRole("button", { name: "Hapus" }))[1];

        await user.click(await second());
        await user.click(await screen.findByRole("button", { name: "Batal" }));
        expect(await second()).toHaveFocus();

        await user.click(await second());
        await user.click(await screen.findByRole("button", { name: "Hapus" }));
        await waitFor(() => expect(screen.getByRole("button", { name: "Tutup" })).toHaveFocus());
    });

    it("removes only after the server answered", async () => {
        const id = await failedRow("u-1", { last_error: "x".repeat(600) });
        await updatePendingTransaction(id, { data: sale("u-1", { note: "n".repeat(1100) }) });
        let answer;
        axios.post.mockReturnValue(new Promise((resolve) => { answer = resolve; }));
        const onChange = vi.fn();
        const { user } = await open({ onChange });

        await removeOnlyRow(user);
        await waitFor(() => expect(axios.post).toHaveBeenCalled());

        const [row] = await getPendingTransactions(SCOPE);
        expect(row).toMatchObject({ id, status: "discarding" });
        expect(axios.post).toHaveBeenCalledWith(
            "/transactions.offline-discard",
            {
                client_uuid: "u-1",
                queue_status: "failed",
                reason: "x".repeat(500),
                attempts: 2,
                queued_at: row.created_at,
                // The whole queued sale, minus the server cart ids and the gateway offline sales never have.
                sale: {
                    grand_total: 25000,
                    payment_method: "cash",
                    customer_id: 3,
                    customer_voucher_id: "7",
                    discount: 1000,
                    redeem_points: 5,
                    shipping_cost: 5000,
                    cash: 30000,
                    bank_account_id: null,
                    order_type: "delivery",
                    note: "n".repeat(1000),
                    items: [{ product_id: 12, unit_id: 1, qty: 2 }],
                },
            },
            { headers: { Accept: "application/json" } }
        );

        // The matcher above ignores undefined keys: pin that these never leave the device.
        expect(Object.keys(axios.post.mock.calls[0][1].sale)).not.toContain("cart_ids");

        await act(async () => answer(AUDITED));

        expect(await screen.findByText("Tidak ada transaksi offline yang tertunda.")).toBeInTheDocument();
        expect(await getPendingTransactions(SCOPE)).toEqual([]);
        expect(onChange).toHaveBeenCalled();
    });

    it("count drops after removal", async () => {
        axios.post.mockResolvedValue(AUDITED);
        await failedRow();
        expect(await getUnsyncedCount(5)).toBe(1);
        const { user } = await open();

        await removeOnlyRow(user);

        await waitFor(async () => expect(await getUnsyncedCount(5)).toBe(0));
    });

    it.each([
        ["a 500", () => Promise.reject({ response: { status: 500 } })],
        ["a 422", () => Promise.reject({ response: { status: 422 } })],
        ["a 429", () => Promise.reject({ response: { status: 429 } })],
        ["no network", () => Promise.reject(new Error("Network Error"))],
        // A captive portal or a followed login redirect answers 200 without writing any audit entry.
        ["a 200 page", () => Promise.resolve({ status: 200, data: "<html></html>" })],
    ])("keeps the row when the audit call fails with %s", async (_name, response) => {
        axios.post.mockImplementation(response);
        await failedRow();
        const { user } = await open();

        await removeOnlyRow(user);

        expect(await screen.findByRole("alert")).toHaveTextContent("Gagal menghapus. Transaksi tetap tersimpan di perangkat.");
        expect(await getPendingTransactions(SCOPE)).toMatchObject([{ status: "failed", last_error: "Stok tidak cukup." }]);
        expect(await screen.findByRole("button", { name: "Hapus" })).toBeEnabled();
    });

    it("does not remove a row whose status changed", async () => {
        const id = await failedRow();
        const onChange = vi.fn();
        const { user } = await open({ onChange });
        await user.click(await screen.findByRole("button", { name: "Hapus" }));
        // A flush took the row while the question was open.
        await updatePendingTransaction(id, { status: "syncing", last_attempt_at: new Date().toISOString() });

        await user.click(screen.getByRole("button", { name: "Hapus" }));

        expect(await screen.findByRole("alert")).toHaveTextContent("Status berubah, coba lagi.");
        expect(axios.post).not.toHaveBeenCalled();
        expect(await getPendingTransactions(SCOPE)).toMatchObject([{ status: "syncing" }]);
        expect(onChange).toHaveBeenCalled();
    });

    it("discarding row can be removed again", async () => {
        // The page closed between the audit call and the delete.
        axios.post.mockResolvedValue(AUDITED);
        await queue("u-1", { status: "discarding", discard_from: "failed" });
        const { user } = await open();

        await user.click(await screen.findByRole("button", { name: "Hapus" }));
        expect(await screen.findByText(NOT_YET)).toBeInTheDocument();
        await user.click(await screen.findByRole("button", { name: "Hapus" }));

        await waitFor(async () => expect(await getPendingTransactions(SCOPE)).toEqual([]));
        expect(axios.post.mock.calls[0][1].queue_status).toBe("discarding");
    });
});
