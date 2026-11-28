import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from "vitest";
import { act, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import axios from "axios";
import toast from "react-hot-toast";
import { router } from "@inertiajs/react";
import i18n from "@/i18n";
import { queueTransaction } from "@/Utils/offlineDb";
import TableOrderModal from "./TableOrderModal";

vi.mock("@inertiajs/react", () => ({ router: { post: vi.fn() } }));
vi.mock("axios", () => ({ default: { get: vi.fn() } }));
vi.mock("react-hot-toast", () => ({ default: { success: vi.fn(), error: vi.fn() } }));
vi.mock("@/Utils/offlineDb", () => ({ queueTransaction: vi.fn() }));
vi.mock("@/Components/Dashboard/Modal", () => ({
    default: ({ children, title, show }) => (show ? <div role="dialog" aria-label={title}>{children}</div> : null),
}));

const tables = [
    { id: 1, name: "Meja 1", area_name: "Teras", capacity: 4, status: "free", open_bills: 0, orders: [] },
    { id: 2, name: "Meja 2", area_name: null, capacity: 2, status: "occupied", open_bills: 2, orders: [{ id: 41, status: "accepted", item_count: 3 }] },
    { id: 3, name: "Meja 3", area_name: null, capacity: 2, status: "reserved", open_bills: 0, orders: [] },
];
const carts = [{ id: 9, qty: 2, product: { title: "Nasi goreng" } }];
const answer = (data = {}) => axios.get.mockResolvedValue({ data: { tables, payLaterEnabled: false, cartTotal: 20000, ...data } });

async function open(props = {}) {
    render(<TableOrderModal carts={carts} {...props} />);
    await userEvent.click(await screen.findByRole("button", { name: "Pesanan meja" }));
}
const pick = (name) => userEvent.click(screen.getByRole("button", { name: new RegExp(name) }));
const send = () => screen.getByRole("button", { name: "Kirim pesanan" });
const online = (value) => vi.spyOn(navigator, "onLine", "get").mockReturnValue(value);

beforeAll(() => i18n.changeLanguage("id"));
beforeEach(() => {
    vi.clearAllMocks();
    answer();
});
afterEach(() => vi.restoreAllMocks());

describe("TableOrderModal", () => {
    it("shows each table's status as text with its unpaid bills", async () => {
        await open();

        expect(screen.getByRole("button", { name: /Meja 1.*Kosong.*0 tagihan Belum Lunas/ })).toBeInTheDocument();
        expect(screen.getByRole("button", { name: /Meja 2.*Terisi.*2 tagihan Belum Lunas/ })).toBeInTheDocument();
        expect(screen.getByRole("button", { name: /Meja 3.*Dipesan/ })).toBeInTheDocument();
        expect(send()).toBeDisabled();
    });

    it("is not offered to a shop without tables", async () => {
        answer({ tables: [] });
        const { container } = render(<TableOrderModal carts={carts} />);
        await waitFor(() => expect(axios.get).toHaveBeenCalled());
        await act(async () => {});

        expect(container).toBeEmptyDOMElement();
    });

    it("sends a new order, payable at the counter, once", async () => {
        await open();
        await pick("Meja 1");
        expect(screen.getByText("Nasi goreng")).toBeInTheDocument();
        expect(screen.getByText(/20\.000/)).toBeInTheDocument();
        expect(screen.queryByRole("radio", { name: "Bayar Belakangan" })).not.toBeInTheDocument();

        await userEvent.click(send());
        await userEvent.click(screen.getByRole("button", { name: "Mengirim..." }));

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(router.post.mock.calls[0].slice(0, 2)).toEqual([
            "/transactions.table-orders.store",
            { dine_table_id: 1, payment_option: "pay_at_counter", add_on_to_dine_order_id: null },
        ]);
        act(() => router.post.mock.calls[0][2].onSuccess());
        expect(toast.success).toHaveBeenCalledWith("Pesanan terkirim ke Meja 1");
        expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    });

    it("adds to a running order and offers pay later when the outlet has it", async () => {
        answer({ payLaterEnabled: true });
        await open();
        await pick("Meja 2");
        expect(screen.getByRole("radio", { name: "Pesanan baru" })).toBeChecked();

        await userEvent.click(screen.getByRole("radio", { name: "Tambah ke pesanan #41 (3 item)" }));
        await userEvent.click(screen.getByRole("radio", { name: "Bayar Belakangan" }));
        await userEvent.click(send());

        expect(router.post.mock.calls[0][1]).toEqual({ dine_table_id: 2, payment_option: "pay_later", add_on_to_dine_order_id: 41 });
    });

    it("shows the server's refusal and lets the cashier try again", async () => {
        await open();
        await pick("Meja 1");
        await userEvent.click(send());

        const options = router.post.mock.calls[0][2];
        act(() => {
            options.onError({ stock: "Stok Nasi goreng tidak mencukupi (tersedia: 1)." });
            options.onFinish();
        });

        expect(toast.error).toHaveBeenCalledWith("Stok Nasi goreng tidak mencukupi (tersedia: 1).");
        expect(send()).toBeEnabled();
    });

    it("says so when the network drops while sending", async () => {
        await open();
        await pick("Meja 1");
        await userEvent.click(send());

        let handled;
        act(() => {
            handled = router.post.mock.calls[0][2].onNetworkError(new Error("offline"));
        });

        expect(handled).toBe(false);
        expect(toast.error).toHaveBeenCalledWith("Pesanan belum terkirim. Keranjang tetap ada.");
    });

    it("cannot send an empty cart", async () => {
        await open({ carts: [] });
        await pick("Meja 1");

        expect(screen.getByText("Keranjang kosong. Tambahkan item dulu.")).toBeInTheDocument();
        expect(send()).toBeDisabled();
    });

    it("refuses offline and queues nothing", async () => {
        await open();
        await pick("Meja 1");
        online(false);

        await userEvent.click(send());

        expect(toast.error).toHaveBeenCalledWith("Pesanan meja memerlukan koneksi internet.");
        expect(router.post).not.toHaveBeenCalled();
        expect(queueTransaction).not.toHaveBeenCalled();
    });

    it("stays offered when the list cannot load, and refuses offline without opening", async () => {
        axios.get.mockRejectedValue(new Error("offline"));
        online(false);
        await open();

        expect(toast.error).toHaveBeenCalledWith("Pesanan meja memerlukan koneksi internet.");
        expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    });

    it("offers a retry when the list fails online", async () => {
        axios.get.mockRejectedValue(new Error("500"));
        await open();
        expect(await screen.findByRole("alert")).toHaveTextContent("Daftar meja gagal dimuat.");

        answer();
        await userEvent.click(screen.getByRole("button", { name: "Coba lagi" }));

        expect(await screen.findByRole("button", { name: /Meja 1/ })).toBeInTheDocument();
    });
});
