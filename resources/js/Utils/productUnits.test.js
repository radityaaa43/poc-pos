import { describe, expect, it } from "vitest";
import { updateUnitRows } from "./productUnits";

const pcs = { unit_id: 1, is_base: true, is_purchase_default: false, conversion_factor: 1 };
const box = { unit_id: 2, is_base: false, is_purchase_default: true, conversion_factor: 12 };

describe("updateUnitRows", () => {
    it("sets the content to 1 on the row ticked as base, whose content field is locked (#71)", () => {
        const rows = updateUnitRows([pcs, box], 1, "is_base", true);

        expect(rows[1]).toMatchObject({ is_base: true, conversion_factor: 1 });
        expect(rows[0]).toMatchObject({ is_base: false, conversion_factor: 1 });
    });

    it("keeps one purchase default and leaves other fields as typed", () => {
        const rows = updateUnitRows([pcs, box], 0, "is_purchase_default", true);
        expect(rows.map((r) => r.is_purchase_default)).toEqual([true, false]);

        expect(updateUnitRows([pcs, box], 1, "conversion_factor", "24")[1].conversion_factor).toBe("24");
        expect(updateUnitRows([pcs, box], 1, "is_base", false)[1]).toMatchObject({ is_base: false, conversion_factor: 12 });
    });

    it("does not mutate the rows it was given", () => {
        updateUnitRows([pcs, box], 1, "is_base", true);
        expect(pcs.is_base).toBe(true);
        expect(box.conversion_factor).toBe(12);
    });
});
