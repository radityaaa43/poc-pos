import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import ShippingLabel from "./ShippingLabel";
import ThermalReceipt, { ThermalReceipt58mm } from "./ThermalReceipt";

// A 25000 sale with 5000 returned before it was paid: what a courier or a customer reads must be 20000.
const sale = {
    invoice: "INV-1",
    created_at: "2026-11-28T10:00:00Z",
    payment_method: "bank_transfer",
    payment_status: "pending",
    grand_total: 25000,
    amount_due: 20000,
    details: [],
};

describe("receipts of a returned sale that is not paid yet", () => {
    it("asks the owed amount on the shipping label", () => {
        render(<ShippingLabel transaction={sale} />);

        expect(screen.getByText("Total Bayar").nextElementSibling).toHaveTextContent(/Rp\s*20\.000/);
    });

    it.each([
        { paper: "80mm", receipt: (transaction) => <ThermalReceipt transaction={transaction} /> },
        { paper: "58mm", receipt: (transaction) => <ThermalReceipt58mm transaction={transaction} /> },
    ])("adds the owed amount to the $paper receipt", ({ receipt }) => {
        const { rerender } = render(receipt(sale));

        expect(screen.getByText("SISA TAGIHAN").nextElementSibling).toHaveTextContent(/20\.000/);

        rerender(receipt({ ...sale, amount_due: null }));
        expect(screen.queryByText("SISA TAGIHAN")).not.toBeInTheDocument();
    });
});
