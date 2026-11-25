import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import toast from "react-hot-toast";
import { router } from "@inertiajs/react";
import OpenBills from "./OpenBills";

vi.mock("@inertiajs/react", () => ({
    router: { post: vi.fn() },
}));
vi.mock("react-hot-toast", () => ({
    default: { success: vi.fn(), error: vi.fn() },
}));
vi.mock("@/Components/Dashboard/Modal", () => ({
    default: ({ children, title }) => (
        <div>
            <h2>{title}</h2>
            {children}
        </div>
    ),
}));

const openBills = [
    { order_id: 7, invoice: "TRX-ABC", table: "Meja 3", grand_total: 20000, served: true },
];
const bankAccounts = [{ id: 4, bank_name: "BCA", account_number: "123" }];

beforeEach(() => {
    vi.clearAllMocks();
    globalThis.route = (name, id) => `/${name}/${id}`;
});

describe("OpenBills", () => {
    it("renders nothing without open bills", () => {
        const { container } = render(<OpenBills />);
        expect(container).toBeEmptyDOMElement();
    });

    it("pays the accept transaction with a cash tender", async () => {
        const user = userEvent.setup();
        render(<OpenBills openBills={openBills} bankAccounts={bankAccounts} />);

        await user.click(screen.getByRole("button", { name: /Tagihan Terbuka/ }));
        expect(screen.getByText(/Sudah disajikan/)).toBeInTheDocument();
        await user.click(screen.getByRole("button", { name: "Bayar" }));

        // cash short of the bill is blocked on the client
        await user.type(screen.getByLabelText("Uang diterima"), "10000");
        await user.click(screen.getAllByRole("button", { name: "Bayar" }).at(-1));
        expect(router.post).not.toHaveBeenCalled();
        expect(toast.error).toHaveBeenCalled();

        await user.clear(screen.getByLabelText("Uang diterima"));
        await user.type(screen.getByLabelText("Uang diterima"), "25000");
        await user.click(screen.getAllByRole("button", { name: "Bayar" }).at(-1));

        expect(router.post).toHaveBeenCalledWith(
            "/dine-orders.pay/7",
            { tenders: [{ method: "cash", amount: 20000, cash_received: 25000, bank_account_id: null }] },
            expect.any(Object)
        );
    });

    it("pays by bank transfer to the chosen account", async () => {
        const user = userEvent.setup();
        render(<OpenBills openBills={openBills} bankAccounts={bankAccounts} />);

        await user.click(screen.getByRole("button", { name: /Tagihan Terbuka/ }));
        await user.click(screen.getByRole("button", { name: "Bayar" }));
        await user.selectOptions(screen.getByLabelText("Metode pembayaran"), "bank_transfer");
        await user.click(screen.getAllByRole("button", { name: "Bayar" }).at(-1));

        expect(router.post).toHaveBeenCalledWith(
            "/dine-orders.pay/7",
            { tenders: [{ method: "bank_transfer", amount: 20000, cash_received: null, bank_account_id: 4 }] },
            expect.any(Object)
        );
    });
});
