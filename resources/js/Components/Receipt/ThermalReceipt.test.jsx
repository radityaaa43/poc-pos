import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import ThermalReceipt, { ThermalReceipt58mm } from "./ThermalReceipt";

// #482: a split-tender sale lists each tender by its payment label on both paper widths.
const sale = {
    invoice: "INV-1",
    created_at: "2026-11-28T10:00:00Z",
    payment_method: "split",
    payment_status: "paid",
    grand_total: 30000,
    details: [],
    tenders: [
        { id: 1, method: "cash", amount: 10000 },
        { id: 2, method: "bank_transfer", amount: 20000 },
    ],
};

describe("thermal receipts of a split-tender sale", () => {
    it.each([
        { paper: "58mm", receipt: (transaction) => <ThermalReceipt58mm transaction={transaction} /> },
        { paper: "80mm", receipt: (transaction) => <ThermalReceipt transaction={transaction} /> },
    ])("renders a split-tender sale at $paper", ({ receipt }) => {
        render(receipt(sale));

        expect(screen.getByText("TUNAI").nextElementSibling).toHaveTextContent(/10\.000/);
        expect(screen.getByText("TRANSFER BANK").nextElementSibling).toHaveTextContent(/20\.000/);
    });
});

// #497: the cash part of a split sale shows what was received and the change, as a cash sale does.
describe("thermal receipts of a split sale paid partly in cash", () => {
    it.each([
        { paper: "58mm", receipt: (transaction) => <ThermalReceipt58mm transaction={transaction} /> },
        { paper: "80mm", receipt: (transaction) => <ThermalReceipt transaction={transaction} /> },
    ])("prints cash received and change at $paper", ({ receipt }) => {
        const [cash, transfer] = sale.tenders;
        render(receipt({ ...sale, tenders: [{ ...cash, cash_received: 15000, change: 5000 }, transfer] }));

        expect(screen.getByText("Diterima").nextElementSibling).toHaveTextContent(/15\.000/);
        expect(screen.getByText("Kembali").nextElementSibling).toHaveTextContent(/5\.000/);
    });
});
