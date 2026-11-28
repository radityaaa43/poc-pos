import React from "react";
import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import ModifierPanel from "./ModifierPanel";

const option = (id, name, price_delta = 0, available = true) => ({ id, name, price_delta, available });

const group = (id, name, min_select, max_select, options) => ({ id, name, is_required: min_select > 0, min_select, max_select, options });
const groups = [
    group(1, "Size", 1, 1, [option(1, "Regular"), option(2, "Large", 5000)]),
    group(2, "Topping", 0, 2, [option(3, "Boba", 3000), option(4, "Jelly", 2000), option(5, "Cheese", 4000), option(6, "Oreo", 1000, false)]),
];

const labels = {
    required: "Wajib",
    optional: "Opsional",
    range: (group) => `Pilih ${group.min_select}-${group.max_select}`,
    soldOut: "Habis",
    note: "Catatan",
    noteCount: (count) => `${count}/100`,
    portionPrice: "Harga porsi",
    incomplete: (name) => `Lengkapi: ${name}`,
    add: "Tambah",
    cancel: "Batal",
};

const open = (props) => {
    const onAdd = vi.fn();
    render(
        <ModifierPanel title="Kopi Susu" basePrice={20000} groups={groups} selected={[1]} labels={labels} onAdd={onAdd} onClose={vi.fn()} {...props} />
    );

    return onAdd;
};
const box = (name) => screen.getByRole("checkbox", { name: new RegExp(name) });

describe("ModifierPanel", () => {
    it("opens with defaults and price, follows the choice and locks at the maximum", async () => {
        open();

        expect(screen.getByRole("radio", { name: /Regular/ })).toBeChecked();
        expect(screen.getByText("Rp 20.000")).toBeInTheDocument();

        await userEvent.click(screen.getByRole("radio", { name: /Large/ }));
        await userEvent.click(box("Boba"));

        expect(screen.getByRole("radio", { name: /Regular/ })).not.toBeChecked();
        expect(screen.getByText("Rp 28.000")).toBeInTheDocument();

        await userEvent.click(box("Jelly"));

        expect(box("Cheese")).toBeDisabled();
        expect(box("Boba")).toBeEnabled();
    });

    it("shows a sold out option as Habis and unpickable", () => {
        open();

        expect(box("Oreo")).toBeDisabled();
        expect(box("Oreo").closest("label")).toHaveTextContent("Habis");
    });

    it("after a reload drops a gone option and lets a sold out tick be cleared", async () => {
        const props = { title: "Kopi Susu", basePrice: 20000, selected: [1, 3, 4], labels, onClose: vi.fn(), onAdd: vi.fn() };
        const { rerender } = render(<ModifierPanel {...props} groups={groups} />);
        rerender(<ModifierPanel {...props} groups={[groups[0], group(2, "Topping", 0, 2, [option(4, "Jelly", 2000, false)])]} />);
        await userEvent.click(screen.getByRole("button", { name: "Tambah" }));
        expect(props.onAdd).toHaveBeenCalledWith({ optionIds: [1, 4], note: null });
        await userEvent.click(box("Jelly"));
        expect(box("Jelly")).not.toBeChecked();
        expect(box("Jelly")).toBeDisabled();
    });

    it("disables Add and names the group under its minimum", () => {
        open({ selected: [] });

        expect(screen.getByRole("button", { name: "Tambah" })).toBeDisabled();
        expect(screen.getByRole("status")).toHaveTextContent("Lengkapi: Size");
    });

    it("hands back the option ids and a trimmed note of at most 100 characters", async () => {
        const onAdd = open();
        const note = screen.getByRole("textbox", { name: "Catatan" });

        expect(note).toHaveAttribute("maxlength", "100");
        await userEvent.type(note, " less ice ");
        expect(screen.getByText("10/100")).toBeInTheDocument();
        await userEvent.click(screen.getByRole("button", { name: "Tambah" }));

        expect(onAdd).toHaveBeenCalledWith({ optionIds: [1], note: "less ice" });
    });
});
