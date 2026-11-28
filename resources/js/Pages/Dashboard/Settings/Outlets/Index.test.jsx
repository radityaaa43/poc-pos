import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import i18n from "@/i18n";
import Outlets from "./Index";

const router = vi.hoisted(() => ({ put: vi.fn(), post: vi.fn(), delete: vi.fn() }));
vi.mock("@inertiajs/react", () => ({ Head: () => null, router, usePage: () => ({ props: { flash: {} } }) }));
vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock("@/Utils/authorization", () => ({ useAuthorization: () => ({ can: () => true }) }));
globalThis.route = (name) => name;

const outlet = {
    id: 1, name: "Cabang", code: "CBG", is_active: true, is_sales_enabled: true,
    bill_settings: { service_charge_rate: 0, tax_kind: "ppn", pb1_rate: 10, cash_rounding_unit: 0 },
};

describe("outlet edit form", () => {
    beforeEach(() => {
        router.put.mockClear();
        return i18n.changeLanguage("id");
    });

    const openAndSave = async (change = async () => {}) => {
        const { container } = render(<Outlets outlets={[outlet]} />);
        await userEvent.click(container.querySelector(".tabler-icon-pencil").closest("button"));
        await change();
        await userEvent.click(screen.getByRole("button", { name: "Simpan Perubahan" }));
        return router.put.mock.calls[0][1];
    };

    // A form opened before someone else changed a bill setting must not write the old value back.
    it("leaves out the bill fields nobody changed in this form", async () => {
        const sent = await openAndSave();

        expect(sent).toMatchObject({ name: "Cabang", code: "CBG" });
        ["service_charge_rate", "tax_kind", "pb1_rate", "cash_rounding_unit"].forEach((key) => expect(sent).not.toHaveProperty(key));
    });

    it("sends the bill fields changed in this form", async () => {
        const sent = await openAndSave(async () => {
            const rate = screen.getByRole("spinbutton", { name: "Service charge (%)" });
            await userEvent.clear(rate);
            await userEvent.type(rate, "5");
            await userEvent.selectOptions(screen.getByRole("combobox", { name: "Pembulatan tunai" }), "500");
        });

        expect(sent).toMatchObject({ service_charge_rate: "5", cash_rounding_unit: "500" });
        expect(sent).not.toHaveProperty("tax_kind");
        expect(sent).not.toHaveProperty("pb1_rate");
    });
});
