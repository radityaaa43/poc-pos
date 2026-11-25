import { describe, it, expect, vi, beforeAll, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import toast from "react-hot-toast";
import { router } from "@inertiajs/react";
import i18n from "@/i18n";
import OpenBillPayModal from "./OpenBillPayModal";

let online = true;
vi.mock("@/Context/OnlineStatusContext", () => ({ useOnlineStatus: () => online }));
vi.mock("@inertiajs/react", () => ({ router: { post: vi.fn() } }));
vi.mock("react-hot-toast", () => ({ default: { success: vi.fn(), error: vi.fn() } }));
vi.mock("@/Components/Dashboard/Modal", () => ({
    default: ({ children, title }) => (
        <div>
            <h2>{title}</h2>
            {children}
        </div>
    ),
}));

const dineBill = { id: 1, order_id: 7, invoice: "TRX-ABC", label: "Meja 3", grand_total: 20000, amount: 20000 };
const posBill = { id: 9, order_id: null, invoice: "TRX-POS", label: "Budi", grand_total: 50000, amount: 30000 };

const payCash = async (user, cash) => {
    await user.type(screen.getByLabelText("Uang diterima"), String(cash));
    await user.click(screen.getByRole("button", { name: "Bayar" }));
};

beforeAll(() => i18n.changeLanguage("id"));
beforeEach(() => {
    vi.clearAllMocks();
    online = true;
    globalThis.route = (name, id) => `/${name}/${id}`;
});

describe("OpenBillPayModal", () => {
    it("settles a POS bill through transactions.settle for the remaining amount", async () => {
        const user = userEvent.setup();
        render(<OpenBillPayModal bill={posBill} onClose={() => {}} />);

        expect(screen.getByText("Bayar Budi · TRX-POS")).toBeInTheDocument();
        expect(screen.getByText(/30\.000/)).toBeInTheDocument();
        await payCash(user, 30000);

        expect(router.post).toHaveBeenCalledWith(
            "/transactions.settle/9",
            { tenders: [{ method: "cash", amount: 30000, cash_received: 30000, bank_account_id: null }] },
            expect.any(Object)
        );
    });

    it("pays a dine-in bill through dine-orders.pay", async () => {
        const user = userEvent.setup();
        render(<OpenBillPayModal bill={dineBill} onClose={() => {}} />);

        await payCash(user, 20000);

        expect(router.post).toHaveBeenCalledWith("/dine-orders.pay/7", expect.any(Object), expect.any(Object));
    });

    it("shows the server message keyed bill", async () => {
        const user = userEvent.setup();
        render(<OpenBillPayModal bill={posBill} onClose={() => {}} />);

        await payCash(user, 30000);
        router.post.mock.calls[0][2].onError({ tenders: "Jumlah salah.", bill: "Tagihan ini sudah dilunasi." });

        expect(toast.error).toHaveBeenCalledWith("Tagihan ini sudah dilunasi.");
    });

    it("disables Bayar offline and never posts", async () => {
        online = false;
        render(<OpenBillPayModal bill={posBill} onClose={() => {}} />);

        expect(screen.getByRole("button", { name: "Bayar" })).toBeDisabled();
    });
});
