import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { router } from "@inertiajs/react";
import i18n from "@/i18n";
import { getUnsyncedCount } from "@/Utils/offlineDb";
import Show from "./Show";

let page;

vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));
vi.mock("@/Utils/offlineDb", () => ({ getUnsyncedCount: vi.fn() }));
vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    Link: ({ children }) => children,
    router: { post: vi.fn() },
    usePage: () => ({ props: page }),
}));

const shift = {
    id: 9, status: "open", user: { id: 5, name: "Kasir" }, warehouse: { id: 3, name: "Toko" },
    expected_cash: 100000, actual_cash: null, cash_difference: null, unpaid_bills_total: 0,
};
const held = [{ type: "item", order: "TRX-1", label: "Kopi × 1", amount: 15000 }];

const renderShow = ({ cashierShift, ...props } = {}) =>
    render(<Show canForceClose={false} closeBlockers={[]} {...props} cashierShift={{ ...shift, ...cashierShift }} />);

const signIn = ({ id = 5, superAdmin = false, mayClose = true, errors = {} } = {}) => {
    page = { auth: { user: { id }, super: superAdmin, permissions: { "cashier-shifts-close": mayClose } }, errors };
};

const fillCash = () => userEvent.type(screen.getByLabelText("Kas Fisik Aktual"), "100000");
const click = (name) => userEvent.click(screen.getByRole("button", { name }));
const expectPlainClose = async () => {
    await fillCash();
    await click("Finalisasi Closing");
    const sent = { actual_cash: 100000, close_notes: "" };
    await waitFor(() => expect(router.post).toHaveBeenCalledWith("/cashier-shifts.close", sent));
};

beforeAll(() => i18n.changeLanguage("id"));
beforeEach(() => {
    vi.clearAllMocks();
    getUnsyncedCount.mockResolvedValue(0);
    signIn();
});

describe("Shift close", () => {
    it.each([
        ["a holder of the force-close permission", { canForceClose: true }, {}],
        ["a super admin", {}, { superAdmin: true }],
    ])("lets %s force a held shift, with a required note", async (_who, props, viewer) => {
        signIn(viewer);
        renderShow({ ...props, closeBlockers: held });
        expect(screen.getByRole("heading", { name: "Belum selesai" })).toBeInTheDocument();
        const warning = "Item yang belum diserahkan ditandai tidak diambil, dan tagihan belum lunas masuk selisih kas.";
        expect(screen.getByText(warning)).toBeInTheDocument();
        const note = screen.getByLabelText("Catatan tutup paksa");
        expect(note).toBeRequired();

        await fillCash();
        await click("Tutup paksa");
        expect(router.post).not.toHaveBeenCalled();

        await userEvent.type(note, "Tamu sudah pulang");
        await click("Tutup paksa");
        const sent = { actual_cash: 100000, close_notes: "Tamu sudah pulang", force: true };
        await waitFor(() => expect(router.post).toHaveBeenCalledWith("/cashier-shifts.close", sent));
    });

    it("offers no force button without the permission, and leaves the refusal to the server", async () => {
        renderShow({ closeBlockers: held });
        expect(screen.getByText("Kopi × 1")).toBeInTheDocument();
        expect(screen.queryByRole("button", { name: "Tutup paksa" })).not.toBeInTheDocument();
        await expectPlainClose();
    });

    it("closes someone else's unheld shift as before: no note, no force flag", async () => {
        signIn({ id: 1 });
        renderShow({ canForceClose: true });
        expect(screen.getByLabelText("Catatan Closing")).not.toBeRequired();
        await expectPlainClose();
    });

    it("lists what holds the shift even to a viewer who cannot close it", () => {
        signIn({ id: 1, mayClose: false });
        renderShow({ closeBlockers: held });
        expect(screen.getByText("TRX-1")).toBeInTheDocument();
        expect(screen.queryByRole("button", { name: /Finalisasi|Tutup paksa/ })).not.toBeInTheDocument();
    });

    it("shows the server's refusal and note errors as alerts", () => {
        signIn({ errors: { shift: "Shift belum bisa ditutup.", close_notes: "Catatan wajib diisi." } });
        renderShow({ canForceClose: true, closeBlockers: held });
        const alerts = screen.getAllByRole("alert").map((alert) => alert.textContent);
        expect(alerts).toEqual(["Shift belum bisa ditutup.", "Catatan wajib diisi."]);
        expect(screen.getByLabelText("Catatan tutup paksa")).toHaveAccessibleDescription(/Catatan wajib diisi\./);
    });

    it("disables the close while this device still holds the shift cashier's offline sales", async () => {
        getUnsyncedCount.mockResolvedValue(2);
        signIn({ id: 1 });
        renderShow({ canForceClose: true });
        expect(await screen.findByRole("button", { name: "Sinkronkan transaksi offline dulu" })).toBeDisabled();
        expect(getUnsyncedCount).toHaveBeenCalledWith(5);
    });

    it("re-reads the queue on submit, so a sale queued after loading still blocks", async () => {
        renderShow();
        await waitFor(() => expect(getUnsyncedCount).toHaveBeenCalled());
        getUnsyncedCount.mockResolvedValue(1);
        await fillCash();
        await click("Finalisasi Closing");
        expect(await screen.findByRole("button", { name: "Sinkronkan transaksi offline dulu" })).toBeDisabled();
        expect(router.post).not.toHaveBeenCalled();
    });

    it("previews a forced close's difference with the unpaid bills taken off", async () => {
        const bill = { type: "bill", order: "TRX-2", label: "Meja 1", amount: 25000 };
        renderShow({ canForceClose: true, closeBlockers: [...held, bill] });
        await fillCash();
        expect(screen.getByText(/Selisih closing: -Rp\s*25\.000/)).toBeInTheDocument();
    });

    it("warns that a split stays payable, only when one holds the shift, and books nothing for it", async () => {
        const note = "Split pembayaran yang belum lunas tetap bisa dibayar.";
        const { unmount } = renderShow({ canForceClose: true, closeBlockers: held });
        expect(screen.queryByText(note)).not.toBeInTheDocument();
        unmount();

        const split = { type: "split", order: "TRX-3", label: null, amount: 15000 };
        renderShow({ canForceClose: true, closeBlockers: [split] });
        expect(screen.getByText(note)).toBeInTheDocument();
        expect(screen.getByLabelText("Catatan tutup paksa")).toHaveAccessibleDescription(new RegExp(note));
        await fillCash();
        expect(screen.getByText(/Selisih closing: Rp\s*0/)).toBeInTheDocument();
    });

    it("shows the unpaid bills booked on the recap only when there are any", () => {
        const { unmount } = renderShow({ cashierShift: { status: "force_closed" } });
        expect(screen.queryByText("Tagihan belum lunas dibukukan")).not.toBeInTheDocument();
        unmount();
        renderShow({ cashierShift: { status: "force_closed", unpaid_bills_total: 45000 } });
        expect(screen.getByText("Tagihan belum lunas dibukukan").parentElement).toHaveTextContent(/Rp\s45\.000/);
    });
});
