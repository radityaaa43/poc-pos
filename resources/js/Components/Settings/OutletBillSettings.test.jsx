import { afterAll, beforeEach, describe, expect, it } from "vitest";
import { useState } from "react";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import i18n from "@/i18n";
import OutletBillSettings from "./OutletBillSettings";

const defaults = { service_charge_rate: 0, tax_kind: "ppn", pb1_rate: 10, cash_rounding_unit: 0 };
let latest;

function Form({ initial = {}, errors }) {
    const [value, setValue] = useState({ ...defaults, ...initial });
    latest = value;
    return <OutletBillSettings value={value} errors={errors} onChange={(key, next) => setValue((current) => ({ ...current, [key]: next }))} />;
}

const number = (name) => screen.getByRole("spinbutton", { name });
const rounding = (name) => screen.getByRole("combobox", { name });

describe("OutletBillSettings", () => {
    beforeEach(() => i18n.changeLanguage("id"));
    afterAll(() => i18n.changeLanguage("id"));

    it("shows today's behaviour by default: no service charge, PPN, no rounding", () => {
        render(<Form />);

        expect(screen.getByRole("heading", { name: "Tagihan" })).toBeInTheDocument();
        expect(screen.getByText("Berlaku untuk tagihan berikutnya; tagihan yang sudah dibuat tidak berubah.")).toBeInTheDocument();
        expect(number("Service charge (%)")).toHaveValue(0);
        expect(number("Service charge (%)")).toHaveAccessibleDescription("0 = nonaktif");
        expect(screen.getByRole("group", { name: "Jenis pajak" })).toBeInTheDocument();
        expect(screen.getByRole("radio", { name: /^PPN/ })).toBeChecked();
        expect(screen.getByRole("radio", { name: /^PB1/ })).not.toBeChecked();
        expect(number("Tarif PB1 (%)")).toBeDisabled();
        expect(number("Tarif PB1 (%)")).toHaveAccessibleDescription("Dipakai saat jenis pajak PB1.");
        expect(rounding("Pembulatan tunai")).toHaveDisplayValue("Tanpa");
        expect(screen.getAllByRole("option").map((option) => option.textContent)).toEqual(["Tanpa", "100", "500"]);
    });

    it("enables the PB1 rate once PB1 is chosen and reports every change", async () => {
        render(<Form />);

        await userEvent.click(screen.getByRole("radio", { name: /^PB1/ }));
        await userEvent.clear(number("Tarif PB1 (%)"));
        await userEvent.type(number("Tarif PB1 (%)"), "7.5");
        await userEvent.clear(number("Service charge (%)"));
        await userEvent.type(number("Service charge (%)"), "5");
        await userEvent.selectOptions(rounding("Pembulatan tunai"), "500");

        expect(latest).toEqual({ service_charge_rate: "5", tax_kind: "pb1", pb1_rate: "7.5", cash_rounding_unit: "500" });
    });

    it("requires both rates, so an emptied field is not silently saved as unchanged", () => {
        render(<Form initial={{ tax_kind: "pb1" }} />);

        expect(number("Service charge (%)")).toBeRequired();
        expect(number("Tarif PB1 (%)")).toBeRequired();
    });

    it("shows each server error on its own field", () => {
        const errors = {
            service_charge_rate: "Service charge salah.",
            tax_kind: "Jenis pajak salah.",
            pb1_rate: "Tarif PB1 salah.",
            cash_rounding_unit: "Pembulatan salah.",
        };
        render(<Form initial={{ tax_kind: "pb1" }} errors={errors} />);

        const controls = [
            [number("Service charge (%)"), errors.service_charge_rate],
            [screen.getByRole("radio", { name: /^PPN/ }), errors.tax_kind],
            [screen.getByRole("radio", { name: /^PB1/ }), errors.tax_kind],
            [number("Tarif PB1 (%)"), errors.pb1_rate],
            [rounding("Pembulatan tunai"), errors.cash_rounding_unit],
        ];
        controls.forEach(([control, message]) => expect(control).toHaveAccessibleDescription(expect.stringContaining(message)));
        [number("Service charge (%)"), number("Tarif PB1 (%)"), rounding("Pembulatan tunai")].forEach((control) =>
            expect(control).toHaveAttribute("aria-invalid", "true")
        );
    });

    it("keeps a refused PB1 rate editable after the tax kind is switched away", () => {
        render(<Form initial={{ tax_kind: "ppn" }} errors={{ pb1_rate: "Tarif PB1 salah." }} />);

        expect(number("Tarif PB1 (%)")).toBeEnabled();
    });

    it("renders in English", async () => {
        await i18n.changeLanguage("en");
        render(<Form />);

        expect(screen.getByRole("heading", { name: "Bill" })).toBeInTheDocument();
        expect(screen.getByText("Applies to new bills only; bills already made do not change.")).toBeInTheDocument();
        expect(number("Service charge (%)")).toHaveAccessibleDescription("0 = off");
        expect(screen.getByRole("group", { name: "Tax type" })).toBeInTheDocument();
        expect(screen.getByRole("radio", { name: /^PPN/ })).toHaveAccessibleName("PPN PPN per product");
        expect(screen.getByRole("radio", { name: /^PB1/ })).toHaveAccessibleName("PB1 One rate for the whole bill");
        expect(number("PB1 rate (%)")).toHaveAccessibleDescription("Used when the tax type is PB1.");
        expect(rounding("Cash rounding")).toHaveDisplayValue("None");
    });
});
