import { beforeAll, afterEach, describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import i18n from "@/i18n";
import { useAuthorization } from "@/Utils/authorization";
import History from "./History";

vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    Link: ({ children }) => <a>{children}</a>,
    router: { get: vi.fn() },
    usePage: () => ({ props: {} }),
}));
vi.mock("@/Utils/authorization", () => ({ useAuthorization: vi.fn(() => ({ can: () => true })) }));
vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => <div>{children}</div> }));
// Mirrors the real modal's route choice: a dine bill posts to dine-orders.pay, a POS sale to transactions.settle.
vi.mock("@/Components/POS/OpenBillPayModal", () => ({
    default: ({ bill }) =>
        bill ? (
            <>
                <p>asks {bill.amount}</p>
                <p>post {bill.order_id ? "dine-orders.pay" : "transactions.settle"}</p>
            </>
        ) : null,
}));

// A dine bill of 25000 with 5000 returned while unpaid.
const bill = {
    id: 1,
    invoice: "TRX-ABC",
    grand_total: 25000,
    owed_total: 20000,
    payment_status: "unpaid",
    payment_method: "open_bill",
    created_at: "2026-11-28T10:00:00Z",
    dine_order: { id: 7, status: "accepted", table: { name: "Meja 3" } },
};

// A pay-later POS sale whose gateway charge was abandoned (no reference after two minutes).
const abandonedPosBill = {
    id: 11,
    invoice: "TRX-PL",
    grand_total: 10000,
    owed_total: 10000,
    payment_status: "pending",
    payment_method: "qris",
    is_pay_later: true,
    abandoned_claim: 1,
    created_at: "2026-11-28T10:00:00Z",
};

const dineBill = (status, overrides = {}) => ({
    id: 12,
    invoice: "TRX-DINE",
    grand_total: 25000,
    owed_total: 25000,
    payment_status: "unpaid",
    payment_method: "open_bill",
    is_pay_later: true,
    created_at: "2026-11-28T10:00:00Z",
    dine_order: { id: 8, status, table: { name: "Meja 5" } },
    ...overrides,
});

beforeAll(() => i18n.changeLanguage("id"));
afterEach(() => useAuthorization.mockImplementation(() => ({ can: () => true })));

describe("Transactions History", () => {
    it("asks what a pending returned transfer still owes, in the row and in the confirm dialog", async () => {
        const user = userEvent.setup();
        const transfer = {
            id: 2,
            invoice: "TRX-TF",
            grand_total: 25000,
            amount_due: 20000,
            payment_status: "pending",
            payment_method: "bank_transfer",
            created_at: "2026-11-28T10:00:00Z",
        };
        render(<History transactions={{ data: [transfer], links: [] }} filters={{}} />);

        expect(screen.getAllByText(/Sisa tagihan: Rp\s*20\.000/)).toHaveLength(2); // table row and mobile card

        await user.click(screen.getAllByRole("button", { name: /Konfirmasi/ })[0]);

        expect(screen.getByText("Sisa tagihan").nextElementSibling).toHaveTextContent(/Rp\s*20\.000/);
    });

    it("opens the pay dialog of an open bill with what it still owes", async () => {
        const user = userEvent.setup();
        render(<History transactions={{ data: [bill], links: [] }} filters={{}} />);

        // the row is drawn twice (table and mobile card)
        await user.click(screen.getAllByRole("button", { name: "Bayar" })[0]);

        expect(screen.getByText("asks 20000")).toBeInTheDocument();
    });

    it("offers Bayar on a pay-later sale whose gateway claim was abandoned, opening the settle dialog", async () => {
        const user = userEvent.setup();
        render(<History transactions={{ data: [abandonedPosBill], links: [] }} filters={{}} />);

        expect(screen.queryByText("Lihat QR")).not.toBeInTheDocument();
        // the row is drawn twice (table and mobile card)
        await user.click(screen.getAllByRole("button", { name: "Bayar" })[0]);

        expect(screen.getByText("asks 10000")).toBeInTheDocument();
        // no dine order: the modal posts to transactions.settle, not dine-orders.pay
        expect(screen.getByText("post transactions.settle")).toBeInTheDocument();
    });

    it("offers Bayar, not Lihat QR, on a dine bill whose gateway claim was abandoned", () => {
        const bill = dineBill("accepted", { payment_status: "pending", payment_method: "qris", abandoned_claim: 1 });
        render(<History transactions={{ data: [bill], links: [] }} filters={{}} />);

        expect(screen.queryByText("Lihat QR")).not.toBeInTheDocument();
        expect(screen.getAllByRole("button", { name: "Bayar" }).length).toBeGreaterThan(0);
    });

    it("offers Bayar on a dine bill still being prepared", () => {
        render(<History transactions={{ data: [dineBill("preparing")], links: [] }} filters={{}} />);

        expect(screen.getAllByRole("button", { name: "Bayar" }).length).toBeGreaterThan(0);
    });

    it("keeps Lihat QR for a live pending gateway claim", () => {
        const bill = dineBill("accepted", { payment_status: "pending", payment_method: "qris", abandoned_claim: null });
        render(<History transactions={{ data: [bill], links: [] }} filters={{}} />);

        expect(screen.getAllByText("Lihat QR").length).toBeGreaterThan(0);
        expect(screen.queryByRole("button", { name: "Bayar" })).not.toBeInTheDocument();
    });

    it("hides Bayar when the abandoned sale owes nothing", () => {
        render(<History transactions={{ data: [{ ...abandonedPosBill, owed_total: 0 }], links: [] }} filters={{}} />);

        expect(screen.queryByRole("button", { name: "Bayar" })).not.toBeInTheDocument();
    });

    it("needs dine-orders-process to pay a dine bill from History", () => {
        useAuthorization.mockImplementation(() => ({ can: (permission) => permission !== "dine-orders-process" }));
        const bill = dineBill("accepted", { payment_status: "pending", payment_method: "qris", abandoned_claim: 1 });
        render(<History transactions={{ data: [bill], links: [] }} filters={{}} />);

        expect(screen.getAllByText(/Belum Lunas/).length).toBeGreaterThan(0);
        expect(screen.queryByRole("button", { name: "Bayar" })).not.toBeInTheDocument();
    });

    it("leaves a pay-later sale with no abandoned claim as Piutang", () => {
        const bill = { ...abandonedPosBill, abandoned_claim: null, payment_status: "unpaid", payment_method: "pay_later" };
        render(<History transactions={{ data: [bill], links: [] }} filters={{}} />);

        expect(screen.getAllByText("Piutang").length).toBeGreaterThan(0);
        expect(screen.queryByRole("button", { name: "Bayar" })).not.toBeInTheDocument();
    });
});
