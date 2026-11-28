import { beforeAll, describe, expect, it } from "vitest";
import i18n from "@/i18n";
import { billSubtotal, receiptBillData, taxLabel } from "./billTotals";

beforeAll(() => i18n.changeLanguage("id"));

describe("billSubtotal", () => {
    it("old_sale: a sale from before the bill rules derives the same subtotal", () => {
        expect(billSubtotal({ grand_total: 22200, discount: 1000, shipping_cost: 2000, tax_total: 2200 })).toBe(19000);
    });

    it("takes the service charge out and adds the discounts the caller shows", () => {
        const sale = { grand_total: 23100, discount: 0, shipping_cost: 0, tax_total: 2100, service_charge: 1000 };

        expect(billSubtotal(sale)).toBe(20000);
        expect(billSubtotal(sale, { promo: 1, loyalty: 2, voucher: 3 })).toBe(20006);
    });
});

describe("taxLabel", () => {
    const t = i18n.t.bind(i18n);

    it("names a PB1 sale with its rate", () => {
        expect(taxLabel({ tax_kind: "pb1", tax_rate: "10.00" }, t)).toBe("PB1 10%");
        expect(taxLabel({ tax_kind: "pb1", tax_rate: "7.50" }, t, { withPpnRate: true })).toBe("PB1 7.5%");
    });

    it("reads a null kind as PPN, with the rate only where a surface showed one", () => {
        expect(taxLabel({ tax_kind: null, tax_rate: "11.00" }, t)).toBe("PPN");
        expect(taxLabel({ tax_rate: "11.00" }, t, { withPpnRate: true })).toBe("PPN 11%");
        expect(taxLabel({ tax_kind: "ppn" }, t, { withPpnRate: true })).toBe("PPN 11%");
    });

    it("hands the thermal printer the service charge with translated labels", () => {
        expect(receiptBillData({ service_charge: 1000, tax_kind: "pb1", tax_rate: 10 }, t)).toEqual({
            service_charge: 1000,
            service_charge_label: "Service charge",
            tax_label: "PB1 10%",
        });
    });
});
