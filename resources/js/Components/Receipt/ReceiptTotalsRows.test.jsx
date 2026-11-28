import { beforeAll, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import i18n from "@/i18n";
import TransactionDetail from "@/Pages/Public/TransactionDetail";
import ReceiptTotalsRows from "./ReceiptTotalsRows";
import ThermalReceipt, { ThermalReceipt58mm } from "./ThermalReceipt";

vi.mock("@inertiajs/react", () => ({ Head: () => null }));
beforeAll(() => i18n.changeLanguage("id"));

const sale = {
    invoice: "INV-1",
    created_at: "2026-11-28T10:00:00Z",
    payment_method: "cash",
    payment_status: "paid",
    discount: 0,
    shipping_cost: 0,
    service_charge: 1000,
    tax_kind: "pb1",
    tax_rate: "10.00",
    tax_total: 2100,
    grand_total: 23100,
    details: [],
};
// Before release: no tax kind and no service charge.
const oldSale = { ...sale, service_charge: 0, tax_kind: null, tax_rate: "11.00", tax_total: 2200, grand_total: 22200 };
const value = (label) => screen.getByText(label, { exact: true }).nextElementSibling;

describe("receipts of a sale with a service charge", () => {
    it.each([
        { surface: "80mm", receipt: (t) => <ThermalReceipt transaction={t} />, ppn: "PPN 11%" },
        { surface: "58mm", receipt: (t) => <ThermalReceipt58mm transaction={t} />, ppn: "PPN" },
        { surface: "public page", receipt: (t) => <TransactionDetail transaction={t} token="t" />, ppn: "PPN" },
    ])("$surface: subtotal without the charge, its own row, the tax named by its kind", ({ receipt, ppn }) => {
        const { rerender } = render(receipt(sale));

        expect(value("Subtotal")).toHaveTextContent(/20\.000/);
        expect(value("Service charge")).toHaveTextContent(/1\.000/);
        expect(value("PB1 10%")).toHaveTextContent(/2\.100/);

        rerender(receipt(oldSale));
        expect(value("Subtotal")).toHaveTextContent(/20\.000/);
        expect(value(ppn)).toHaveTextContent(/2\.200/);
        expect(screen.queryByText("Service charge")).not.toBeInTheDocument();
    });

    it("invoice summary: shipping, service charge and tax rows", () => {
        const rows = (t) => <ReceiptTotalsRows transaction={t} format={(amount) => `Rp${amount}`} />;
        const { rerender, container } = render(rows({ ...sale, shipping_cost: 5000 }));

        expect(value("Ongkos Kirim")).toHaveTextContent("+ Rp5000");
        expect(value("Service charge")).toHaveTextContent("+ Rp1000");
        expect(value("PB1 10%")).toHaveTextContent("+ Rp2100");

        rerender(rows(oldSale));
        expect(value("PPN 11%")).toHaveTextContent("+ Rp2200");
        expect(container).not.toHaveTextContent(/Service charge|Ongkos Kirim/);
    });

    it("an inclusive sale shows its PPN on its own line, never added again (#576)", () => {
        const inclusive = { ...sale, service_charge: 0, tax_total: 16500, tax_included: 16500, tax_rate: "11.00", grand_total: 166500 };
        const { container } = render(<ReceiptTotalsRows transaction={inclusive} format={(amount) => `Rp${amount}`} />);

        expect(value("Termasuk PPN 11%")).toHaveTextContent("Rp16500");
        expect(container).not.toHaveTextContent(/\+ Rp16500/);
        expect(screen.queryByText("PPN 11%")).not.toBeInTheDocument();
    });
});
