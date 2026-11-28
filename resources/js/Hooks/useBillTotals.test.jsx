import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, renderHook } from "@testing-library/react";
import useBillTotals from "./useBillTotals";

let online = true;
vi.mock("@/Context/OnlineStatusContext", () => ({ useOnlineStatus: () => online }));

// 2 x 10,000 with 11 % PPN, previewed online as a takeaway at an outlet with a 5 % service charge.
const summary = {
    service_charge: 0, tax_kind: "ppn", tax_total: 2200, tax_rate: 11, grand_total: 22200,
    bill_basis: { after_discount: 20000, shipping: 0, line_tax_total: 2200, shipping_tax: 0, effective_rate: 11 },
};
const settings = { service_charge_rate: 5, tax_kind: "ppn", pb1_rate: 10 };
const outlet = { id: 7, bill_settings: settings };
const props = { orderType: "in_store", shipping: 0, outlet };
const mount = (initial = props, preview = summary) =>
    renderHook((current) => useBillTotals(preview, current), { initialProps: initial });

beforeEach(() => {
    online = true;
    localStorage.clear();
});

describe("useBillTotals", () => {
    it("shows the server's preview while online and caches the outlet's bill settings", () => {
        const { result } = mount();

        expect(result.current.grand_total).toBe(22200);
        expect(result.current.service_charge).toBe(0);
        expect(JSON.parse(localStorage.getItem("pos.billSettings.7"))).toEqual(settings);
    });

    it("recomputes from the basis when the order type changes offline", () => {
        const { result, rerender } = mount();
        online = false;

        rerender(props);
        expect(result.current).toMatchObject({ service_charge: 1000, tax_total: 2310, grand_total: 23310 });

        rerender({ ...props, orderType: "takeaway" });
        expect(result.current).toMatchObject({ service_charge: 0, tax_total: 2200, grand_total: 22200 });
    });

    it("takes the shipping typed offline, not the one of the last preview", () => {
        online = false;

        const { result } = mount({ ...props, orderType: "delivery", shipping: 10000 });

        expect(result.current).toMatchObject({ service_charge: 0, tax_total: 3300, grand_total: 33300 });
    });

    it("uses the cached settings after an offline reload, over the ones of a stale cached page", () => {
        localStorage.setItem("pos.billSettings.7", JSON.stringify({ ...settings, tax_kind: "ppn", service_charge_rate: 10 }));
        online = false;

        const { result } = mount({ ...props, outlet: { id: 7 } });
        expect(result.current.service_charge).toBe(2000);
        expect(mount().result.current.service_charge).toBe(2000);
    });

    it("keeps the preview when it has no basis or no settings are known", () => {
        online = false;

        expect(mount({ ...props, outlet: null }).result.current.grand_total).toBe(22200);
        expect(mount(props, { grand_total: 0 }).result.current).toMatchObject({ service_charge: 0, tax_total: 0, grand_total: 0 });
        expect(mount(props, null).result.current.grand_total).toBe(0);
    });

    it("falls back to the page's settings when the cache is unreadable", () => {
        localStorage.setItem("pos.billSettings.7", "{broken");
        online = false;

        expect(mount().result.current.service_charge).toBe(1000);
    });

    it("counts reconnects so the page previews again when the network is back", () => {
        const { result } = mount();
        expect(result.current.reconnects).toBe(0);

        act(() => window.dispatchEvent(new Event("online")));

        expect(result.current.reconnects).toBe(1);
    });
});
