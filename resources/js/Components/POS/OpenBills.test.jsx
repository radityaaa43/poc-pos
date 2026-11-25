import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import toast from "react-hot-toast";
import { router } from "@inertiajs/react";
import OpenBills from "./OpenBills";

vi.mock("@inertiajs/react", () => ({
    router: { post: vi.fn() },
    Link: ({ children, href }) => <a href={href}>{children}</a>,
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
    { order_id: 7, invoice: "TRX-ABC", table: "Meja 3", grand_total: 20000, order_status: "served", pending: false },
];
const bankAccounts = [{ id: 4, bank_name: "BCA", account_number: "123" }];
const paymentGateways = [
    { value: "bank_transfer", label: "Transfer Bank" },
    { value: "midtrans", label: "Midtrans" },
];

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
        render(<OpenBills openBills={openBills} bankAccounts={bankAccounts} paymentGateways={paymentGateways} />);

        await user.click(screen.getByRole("button", { name: /Tagihan Terbuka/ }));
        expect(screen.getByText(/Sudah diserahkan/)).toBeInTheDocument();
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
        render(<OpenBills openBills={openBills} bankAccounts={bankAccounts} paymentGateways={paymentGateways} />);

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

describe("OpenBills gateway payments", () => {
    it("offers QRIS and sends a gateway tender without cash fields", async () => {
        const user = userEvent.setup();
        render(<OpenBills openBills={openBills} bankAccounts={bankAccounts} paymentGateways={paymentGateways} />);

        await user.click(screen.getByRole("button", { name: /Tagihan Terbuka/ }));
        await user.click(screen.getByRole("button", { name: "Bayar" }));
        await user.selectOptions(screen.getByLabelText("Metode pembayaran"), "qris");
        await user.click(screen.getAllByRole("button", { name: "Bayar" }).at(-1));

        expect(router.post).toHaveBeenCalledWith(
            "/dine-orders.pay/7",
            { tenders: [{ method: "qris", amount: 20000, cash_received: null, bank_account_id: null }] },
            expect.any(Object)
        );
    });

    it("links a bill with a pending gateway payment to its QR instead of charging again", async () => {
        const user = userEvent.setup();
        render(
            <OpenBills
                openBills={[{ ...openBills[0], pending: true }]}
                bankAccounts={bankAccounts}
                paymentGateways={paymentGateways}
            />
        );

        await user.click(screen.getByRole("button", { name: /Tagihan Terbuka/ }));
        expect(screen.queryByRole("button", { name: "Bayar" })).not.toBeInTheDocument();
        expect(screen.getByRole("link", { name: "Lihat QR" })).toHaveAttribute("href", "/transactions.print/TRX-ABC");
    });
});
