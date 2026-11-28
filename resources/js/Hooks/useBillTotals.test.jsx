import { describe, expect, it } from "vitest";
import { act, renderHook } from "@testing-library/react";
import useBillTotals from "./useBillTotals";

const summary = { service_charge: 1000, tax_kind: "ppn", tax_total: 2310, tax_rate: 11, grand_total: 23310 };

describe("useBillTotals", () => {
    it("shows the preview's bill, and zeroes before there is one", () => {
        expect(renderHook(() => useBillTotals(summary)).result.current).toEqual({ ...summary, reconnects: 0 });
        expect(renderHook(() => useBillTotals(undefined)).result.current).toMatchObject({ service_charge: 0, tax_total: 0, grand_total: 0 });
    });

    it("counts reconnects so the page previews again when the network is back", () => {
        const { result } = renderHook(() => useBillTotals(summary));

        act(() => window.dispatchEvent(new Event("online")));

        expect(result.current.reconnects).toBe(1);
    });
});
