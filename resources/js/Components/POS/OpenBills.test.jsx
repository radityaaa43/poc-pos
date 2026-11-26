import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import axios from "axios";
import toast from "react-hot-toast";
import { router } from "@inertiajs/react";
import i18n from "@/i18n";
import OpenBills from "./OpenBills";

vi.mock("@inertiajs/react", () => ({
    router: { post: vi.fn() },
    Link: ({ children, href }) => <a href={href}>{children}</a>,
}));
vi.mock("axios", () => ({ default: { get: vi.fn() } }));
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

describe("OpenBills while the guest pays online", () => {
    const awaiting = { ...openBills[0], pending: true, awaiting_online: true, late_payment: false };
    const props = { bankAccounts, paymentGateways };
    const expand = () => fireEvent.click(screen.getByRole("button", { name: /Belum Lunas/ }));
    const ticks = (ms) =>
        act(async () => {
            await vi.advanceTimersByTimeAsync(ms);
        });
    const setHidden = (hidden) => Object.defineProperty(document, "hidden", { configurable: true, value: hidden });

    it("marks the bill Menunggu pembayaran online in place of the fulfilment label, and keeps Bayar", async () => {
        const user = userEvent.setup();
        render(<OpenBills openBills={[awaiting]} {...props} />);

        await user.click(screen.getByRole("button", { name: /Belum Lunas/ }));

        expect(screen.getByText("Menunggu pembayaran online")).toBeInTheDocument();
        expect(screen.queryByText(/Menunggu Pembayaran/)).not.toBeInTheDocument();
        expect(screen.getByRole("button", { name: "Bayar" })).toBeInTheDocument();
        expect(screen.queryByRole("link", { name: "Lihat QR" })).not.toBeInTheDocument();
    });

    it("keeps Lihat QR and no chip for the cashier's own pending charge", async () => {
        const user = userEvent.setup();
        render(<OpenBills openBills={[{ ...awaiting, awaiting_online: false }]} {...props} />);

        await user.click(screen.getByRole("button", { name: /Belum Lunas/ }));

        expect(screen.getByRole("link", { name: "Lihat QR" })).toBeInTheDocument();
        expect(screen.queryByText("Menunggu pembayaran online")).not.toBeInTheDocument();
        expect(screen.queryByRole("button", { name: "Bayar" })).not.toBeInTheDocument();
    });

    it("shows no chip on a failed or unpaid bill", async () => {
        const user = userEvent.setup();
        render(<OpenBills openBills={openBills} {...props} />);

        await user.click(screen.getByRole("button", { name: /Belum Lunas/ }));

        expect(screen.queryByText("Menunggu pembayaran online")).not.toBeInTheDocument();
        expect(screen.getByRole("button", { name: "Bayar" })).toBeInTheDocument();
    });

    it("shows the refund marker on a bill that received a late payment, and the bill can still be paid", async () => {
        const user = userEvent.setup();
        render(<OpenBills openBills={[{ ...openBills[0], late_payment: true }]} {...props} />);

        await user.click(screen.getByRole("button", { name: /Belum Lunas/ }));

        expect(screen.getByText("Bayar ganda — refund manual")).toBeInTheDocument();
        expect(screen.getByRole("button", { name: "Bayar" })).toBeInTheDocument();
    });

    it("tells the cashier what taking over does before she submits", async () => {
        const user = userEvent.setup();
        render(<OpenBills openBills={[awaiting]} {...props} />);

        await user.click(screen.getByRole("button", { name: /Belum Lunas/ }));
        await user.click(screen.getByRole("button", { name: "Bayar" }));

        expect(screen.getByRole("status")).toHaveTextContent(
            "Tamu sedang membayar online. Tanyakan dulu apakah tamu sudah membayar. Kalau Anda menerima pembayaran sekarang, pembayaran online tamu tidak berlaku dan uangnya harus dikembalikan manual."
        );
        // the notice does not change the submit button
        expect(screen.getAllByRole("button", { name: "Bayar" }).at(-1)).toHaveAttribute("type", "submit");
    });

    it("shows no takeover notice on a bill the guest is not paying", async () => {
        const user = userEvent.setup();
        render(<OpenBills openBills={openBills} {...props} />);

        await user.click(screen.getByRole("button", { name: /Belum Lunas/ }));
        await user.click(screen.getByRole("button", { name: "Bayar" }));

        expect(screen.queryByRole("status")).not.toBeInTheDocument();
    });

    it("says it in English too", async () => {
        await i18n.changeLanguage("en");
        try {
            const user = userEvent.setup();
            render(<OpenBills openBills={[awaiting]} {...props} />);

            await user.click(screen.getByRole("button", { name: /Unpaid/ }));
            expect(screen.getByText("Waiting for online payment")).toBeInTheDocument();
            await user.click(screen.getByRole("button", { name: "Pay" }));

            expect(screen.getByRole("status")).toHaveTextContent(
                "The guest is paying online. Ask whether they have already paid. If you take payment now, the guest's online payment won't count and must be refunded by hand."
            );
        } finally {
            await i18n.changeLanguage("id");
        }
    });

    describe("polling", () => {
        beforeEach(() => vi.useFakeTimers());
        afterEach(() => {
            vi.useRealTimers();
            setHidden(false);
        });

        it("asks the server for the list every 10 seconds and shows the chip that appears", async () => {
            axios.get.mockResolvedValue({ data: { openBills: [awaiting] } });
            render(<OpenBills openBills={openBills} {...props} />);
            expand();
            expect(screen.queryByText("Menunggu pembayaran online")).not.toBeInTheDocument();

            await ticks(9000);
            expect(axios.get).not.toHaveBeenCalled();
            await ticks(1000);

            expect(axios.get).toHaveBeenCalledTimes(1);
            expect(axios.get).toHaveBeenCalledWith(expect.stringContaining("transactions.open-bills"));
            expect(screen.getByText("Menunggu pembayaran online")).toBeInTheDocument();

            await ticks(10000);
            expect(axios.get).toHaveBeenCalledTimes(2);
        });

        it("drops a bill that is paid meanwhile, without a reload", async () => {
            axios.get.mockResolvedValue({ data: { openBills: [] } });
            render(<OpenBills openBills={[awaiting]} {...props} />);
            expect(screen.getByTestId("open-bills")).toBeInTheDocument();

            await ticks(10000);

            expect(screen.queryByTestId("open-bills")).not.toBeInTheDocument();
        });

        it("shows a bill that appears while the list is empty", async () => {
            axios.get.mockResolvedValue({ data: { openBills: [awaiting] } });
            render(<OpenBills />);
            expect(screen.queryByTestId("open-bills")).not.toBeInTheDocument();

            await ticks(10000);

            expect(screen.getByTestId("open-bills")).toBeInTheDocument();
        });

        it("keeps the last list when the request fails", async () => {
            axios.get.mockRejectedValue(new Error("offline"));
            render(<OpenBills openBills={[awaiting]} {...props} />);
            expand();

            await ticks(10000);

            expect(axios.get).toHaveBeenCalledTimes(1);
            expect(screen.getByText("Menunggu pembayaran online")).toBeInTheDocument();
        });

        it("does not ask while the tab is hidden", async () => {
            axios.get.mockResolvedValue({ data: { openBills: [] } });
            setHidden(true);
            render(<OpenBills openBills={[awaiting]} {...props} />);

            await ticks(30000);

            expect(axios.get).not.toHaveBeenCalled();
            expect(screen.getByTestId("open-bills")).toBeInTheDocument();
        });

        it("takes a new list from the page props after a payment", () => {
            const { rerender } = render(<OpenBills openBills={[awaiting]} {...props} />);

            rerender(<OpenBills openBills={[]} {...props} />);

            expect(screen.queryByTestId("open-bills")).not.toBeInTheDocument();
        });

        it("stops polling when it unmounts", async () => {
            const { unmount } = render(<OpenBills openBills={[awaiting]} />);
            unmount();

            await ticks(30000);

            expect(axios.get).not.toHaveBeenCalled();
        });

        it("keeps an open pay dialog when a poll no longer lists its bill, says so, and cannot submit", async () => {
            axios.get.mockResolvedValue({ data: { openBills: [] } });
            render(<OpenBills openBills={[awaiting]} {...props} />);
            expand();
            fireEvent.click(screen.getByRole("button", { name: "Bayar" }));
            fireEvent.change(screen.getByLabelText("Uang diterima"), { target: { value: "20000" } });
            expect(screen.queryByRole("alert")).not.toBeInTheDocument();

            await ticks(10000);

            // the list is gone, the dialog and what the cashier typed are not
            expect(screen.queryByTestId("open-bills")).not.toBeInTheDocument();
            expect(screen.getByLabelText("Uang diterima")).toHaveValue(20000);
            expect(screen.getByRole("alert")).toHaveTextContent("Tagihan ini sudah tidak ada di daftar Belum Lunas");
            expect(screen.getByRole("button", { name: "Bayar" })).toBeDisabled();
            // no takeover notice for a bill that is not there any more
            expect(screen.queryByRole("status")).not.toBeInTheDocument();
            fireEvent.click(screen.getByRole("button", { name: "Bayar" }));
            expect(router.post).not.toHaveBeenCalled();
        });

        it("keeps the dialog on the fresh row while the bill is still listed", async () => {
            axios.get.mockResolvedValue({ data: { openBills: [{ ...awaiting, awaiting_online: false, pending: false }] } });
            render(<OpenBills openBills={[awaiting]} {...props} />);
            expand();
            fireEvent.click(screen.getByRole("button", { name: "Bayar" }));
            expect(screen.getByRole("status")).toBeInTheDocument(); // the guest is paying

            await ticks(10000);

            // the guest's charge is over: the notice goes, the dialog stays and can submit
            expect(screen.queryByRole("status")).not.toBeInTheDocument();
            expect(screen.queryByRole("alert")).not.toBeInTheDocument();
            expect(screen.getAllByRole("button", { name: "Bayar" }).at(-1)).toBeEnabled();
        });

        it("drops a response that is older than a newer request", async () => {
            let answerFirst;
            axios.get
                .mockImplementationOnce(() => new Promise((resolve) => { answerFirst = resolve; }))
                .mockResolvedValueOnce({ data: { openBills: [awaiting] } });
            render(<OpenBills openBills={openBills} {...props} />);
            expand();

            await ticks(10000); // request 1 is slow
            await ticks(10000); // request 2 answers at once
            expect(screen.getByText("Menunggu pembayaran online")).toBeInTheDocument();
            await act(async () => answerFirst({ data: { openBills: [] } })); // request 1 answers last, with an older list

            expect(axios.get).toHaveBeenCalledTimes(2);
            expect(screen.getByTestId("open-bills")).toBeInTheDocument();
            expect(screen.getByText("Menunggu pembayaran online")).toBeInTheDocument();
        });

        it("drops a response that was sent before the page props changed", async () => {
            let answer;
            axios.get.mockImplementationOnce(() => new Promise((resolve) => { answer = resolve; }));
            const { rerender } = render(<OpenBills openBills={[awaiting]} {...props} />);
            expand();

            await ticks(10000); // the request is out
            rerender(<OpenBills openBills={[{ ...awaiting, id: 2, invoice: "TRX-NEW" }]} {...props} />);
            await act(async () => answer({ data: { openBills: [] } })); // the old answer arrives after the new props

            expect(screen.getByText(/TRX-NEW/)).toBeInTheDocument();
        });

        it("refreshes at once when the tab becomes visible again", async () => {
            axios.get.mockResolvedValue({ data: { openBills: [awaiting] } });
            setHidden(true);
            render(<OpenBills openBills={openBills} {...props} />);
            expand();

            await ticks(10000);
            document.dispatchEvent(new Event("visibilitychange")); // still hidden: nothing to do
            expect(axios.get).not.toHaveBeenCalled();

            setHidden(false);
            await act(async () => {
                document.dispatchEvent(new Event("visibilitychange"));
            });

            expect(axios.get).toHaveBeenCalledTimes(1);
            expect(screen.getByText("Menunggu pembayaran online")).toBeInTheDocument();
        });

        it("removes its visibility listener when it unmounts", async () => {
            axios.get.mockResolvedValue({ data: { openBills: [] } });
            const { unmount } = render(<OpenBills openBills={[awaiting]} {...props} />);
            unmount();

            await act(async () => {
                document.dispatchEvent(new Event("visibilitychange"));
            });

            expect(axios.get).not.toHaveBeenCalled();
        });

        it.each([
            ["an HTML page", "<html>Please sign in to the wifi</html>"],
            ["JSON without the list", {}],
            ["nothing", undefined],
        ])("keeps the list when the 200 answer is %s", async (_label, data) => {
            axios.get.mockResolvedValue({ data });
            render(<OpenBills openBills={[awaiting]} {...props} />);
            expand();

            await ticks(10000);

            expect(axios.get).toHaveBeenCalledTimes(1);
            expect(screen.getByText("Menunggu pembayaran online")).toBeInTheDocument();
        });
    });
});
