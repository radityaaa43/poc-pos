import { describe, it, expect, vi, beforeAll, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import toast from "react-hot-toast";
import { router } from "@inertiajs/react";
import i18n from "@/i18n";
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
    { id: 1, order_id: 7, invoice: "TRX-ABC", label: "Meja 3", table: "Meja 3", grand_total: 20000, order_status: "accepted", pending: false },
];
const bankAccounts = [{ id: 4, bank_name: "BCA", account_number: "123" }];
const paymentGateways = [
    { value: "bank_transfer", label: "Transfer Bank" },
    { value: "midtrans", label: "Midtrans" },
];

beforeAll(() => i18n.changeLanguage("id"));
beforeEach(() => {
    vi.clearAllMocks();
    globalThis.route = (name, id) => `/${name}/${id}`;
});

describe("OpenBills", () => {
    it("renders nothing without unpaid bills", () => {
        const { container } = render(<OpenBills />);
        expect(container).toBeEmptyDOMElement();
    });

    it("pays the accept transaction with a cash tender", async () => {
        const user = userEvent.setup();
        render(<OpenBills openBills={openBills} bankAccounts={bankAccounts} paymentGateways={paymentGateways} />);

        await user.click(screen.getByRole("button", { name: /Belum Lunas/ }));
        expect(screen.getByText(/Menunggu Pembayaran/)).toBeInTheDocument();
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

        await user.click(screen.getByRole("button", { name: /Belum Lunas/ }));
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

        await user.click(screen.getByRole("button", { name: /Belum Lunas/ }));
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

        await user.click(screen.getByRole("button", { name: /Belum Lunas/ }));
        expect(screen.queryByRole("button", { name: "Bayar" })).not.toBeInTheDocument();
        expect(screen.getByRole("link", { name: "Lihat QR" })).toHaveAttribute("href", "/transactions.print/TRX-ABC");
    });
});

describe("OpenBills pay-later bills", () => {
    const minutesAgo = (m) => new Date(Date.now() - (m * 60 + 5) * 1000).toISOString();
    const posBill = {
        id: 9, order_id: null, invoice: "TRX-POS", label: "Budi", grand_total: 50000, amount: 30000,
        order_status: null, pending: false, created_at: minutesAgo(12),
    };

    it("shows the label, the remaining amount and the age of a POS bill, with no fulfillment text", async () => {
        const user = userEvent.setup();
        render(<OpenBills openBills={[posBill]} />);

        await user.click(screen.getByRole("button", { name: /Belum Lunas/ }));

        expect(screen.getByText("Budi · TRX-POS")).toBeInTheDocument();
        expect(screen.getByText(/30\.000 · 12 mnt/)).toBeInTheDocument();
        expect(screen.queryByText(/Menunggu Pembayaran/)).not.toBeInTheDocument();
    });

    it("shows the age in hours once it passes an hour", async () => {
        const user = userEvent.setup();
        render(<OpenBills openBills={[{ ...posBill, created_at: minutesAgo(130) }]} />);

        await user.click(screen.getByRole("button", { name: /Belum Lunas/ }));

        expect(screen.getByText(/ · 2 j$/)).toBeInTheDocument();
    });

    it("keeps a dine-in row reading table, invoice, total and fulfillment", async () => {
        const user = userEvent.setup();
        render(<OpenBills openBills={openBills} />);

        await user.click(screen.getByRole("button", { name: /Belum Lunas/ }));

        expect(screen.getByText("Meja 3 · TRX-ABC")).toBeInTheDocument();
        expect(screen.getByText(/20\.000 · Menunggu Pembayaran/)).toBeInTheDocument();
    });
});
