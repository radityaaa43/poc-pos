import { describe, expect, it } from "vitest";
import { defaultPurchaseUnit, defaultUnitPrice, stockEquivalent } from "./purchaseUnits";

const gram = { id: 1, symbol: "g", pivot: { is_base: true, is_purchase_default: false, conversion_factor: "1.0000", buy_price: 250 } };
const kg = { id: 2, symbol: "kg", pivot: { is_base: false, is_purchase_default: true, conversion_factor: "1000.0000", buy_price: 250000 } };
const product = { buy_price: 250, units: [gram, kg] };

describe("purchaseUnits", () => {
    it("defaults to the purchase default unit, then the base unit, then none", () => {
        expect(defaultPurchaseUnit(product)).toBe(kg);
        expect(defaultPurchaseUnit({ units: [gram] })).toBe(gram);
        expect(defaultPurchaseUnit({ units: [] })).toBeNull();
    });

    it("prices a line with the unit's own buy price, else buy_price x factor", () => {
        expect(defaultUnitPrice(product, kg)).toBe(250000);
        const noPrice = { ...kg, pivot: { ...kg.pivot, buy_price: 0 } };
        expect(defaultUnitPrice(product, noPrice)).toBe(250000);
        expect(defaultUnitPrice({ buy_price: 7000 }, null)).toBe(7000);
    });

    it("shows the stock-unit equivalent only for converted units", () => {
        expect(stockEquivalent(5, 1000, "g")).toBe("5.000 g");
        expect(stockEquivalent(5, 1, "g")).toBeNull();
    });
});
