import { useState } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import i18n from "@/i18n";
import OptionRow from "./OptionRow";

const outlets = [{ id: 1, name: "Pusat" }, { id: 2, name: "Cabang" }];
const ingredients = [{ id: 9, title: "Susu", unit_symbol: "ml" }];
let option;

function Harness() {
    const [value, setValue] = useState({ name: "Large", price_delta: 0, is_default: false, components: [], disabled_outlet_ids: [] });
    option = value;
    return (
        <OptionRow
            prefix="options.0"
            number={1}
            option={value}
            outlets={outlets}
            ingredients={ingredients}
            errors={{}}
            onChange={setValue}
            onRemove={vi.fn()}
        />
    );
}

beforeEach(() => i18n.changeLanguage("id"));

describe("OptionRow", () => {
    it("is offered at every outlet until switched off, and can be switched back on", async () => {
        render(<Harness />);
        const branch = screen.getByRole("switch", { name: "Cabang" });
        expect(branch).toBeChecked();
        expect(screen.getByRole("switch", { name: "Pusat" })).toBeChecked();

        await userEvent.click(branch);
        expect(branch).not.toBeChecked();
        expect(option.disabled_outlet_ids).toEqual([2]);

        await userEvent.click(branch);
        expect(option.disabled_outlet_ids).toEqual([]);
    });

    it("builds a recipe: an ingredient, its quantity in the ingredient's unit, and removal", async () => {
        render(<Harness />);

        await userEvent.click(screen.getByRole("button", { name: "Tambah bahan" }));
        await userEvent.selectOptions(screen.getByLabelText("Bahan"), "Susu");
        await userEvent.type(screen.getByLabelText("Jumlah (ml)"), "50");
        expect(option.components).toEqual([{ product_id: "9", qty: "50" }]);

        await userEvent.click(screen.getByRole("button", { name: "Hapus bahan 1" }));
        expect(option.components).toEqual([]);
    });
});
