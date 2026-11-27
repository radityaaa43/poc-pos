import { useState } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import i18n from "@/i18n";
import GroupForm from "./GroupForm";

const post = vi.fn();
const put = vi.fn();
let errors;
let data; // what the form would send

vi.mock("@inertiajs/react", () => ({
    useForm: (initial) => {
        const [state, setState] = useState(initial);
        data = state;
        const setData = (key, value) => setState((prev) => ({ ...prev, [key]: value }));
        return { data: state, setData, post, put, errors, processing: false };
    },
}));
vi.mock("react-hot-toast", () => ({ default: { success: vi.fn(), error: vi.fn() } }));

const group = {
    id: 7,
    name: "Ukuran",
    is_required: true,
    min_select: 1,
    max_select: 1,
    options: [
        { id: 1, name: "Regular", price_delta: 0, is_default: true, components: [], disabled_outlet_ids: [] },
        { id: 2, name: "Large", price_delta: 5000, is_default: false, components: [], disabled_outlet_ids: [] },
    ],
};

const mount = (props = {}) => render(<GroupForm outlets={[]} ingredients={[]} onClose={vi.fn()} {...props} />);
const defaults = () => screen.getAllByRole("checkbox", { name: "Opsi default" });

beforeEach(async () => {
    vi.clearAllMocks();
    errors = {};
    await i18n.changeLanguage("id");
});

describe("GroupForm", () => {
    it("says what minimum and maximum a required and an optional group take", async () => {
        mount();
        expect(screen.getByRole("radio", { name: "Opsional" })).toBeChecked();
        expect(screen.getByText("Grup opsional: minimum boleh 0.")).toBeInTheDocument();

        await userEvent.click(screen.getByRole("radio", { name: "Wajib" }));

        expect(screen.getByText("Grup wajib: minimum 1 dan tidak lebih dari maksimum.")).toBeInTheDocument();
        expect(data.is_required).toBe(true);
    });

    it("keeps one default option: ticking one unticks the other, and it can be cleared", async () => {
        mount({ group });

        await userEvent.click(defaults()[1]);
        expect(defaults()[0]).not.toBeChecked();
        expect(defaults()[1]).toBeChecked();

        await userEvent.click(defaults()[1]);
        expect(data.options.map((o) => o.is_default)).toEqual([false, false]);
    });

    it("adds and removes options, and posts a new group", async () => {
        mount();
        await userEvent.type(screen.getByLabelText("Nama grup"), "Topping");
        await userEvent.type(screen.getByLabelText("Nama opsi"), "Boba");
        await userEvent.click(screen.getByRole("button", { name: "Tambah opsi" }));
        expect(screen.getAllByLabelText("Nama opsi")).toHaveLength(2);
        await userEvent.click(screen.getAllByRole("button", { name: "Hapus opsi" })[1]);
        await userEvent.click(screen.getByRole("button", { name: "Simpan" }));

        expect(data).toMatchObject({ name: "Topping", options: [{ id: null, name: "Boba", price_delta: 0 }] });
        expect(post).toHaveBeenCalledWith("/settings.modifiers.store", expect.anything());
        expect(put).not.toHaveBeenCalled();
    });

    it("updates an existing group and shows the server messages where they belong", async () => {
        errors = {
            min_select: "Pesan minimum",
            options: "Pesan default",
            "options.1.price_delta": "Pesan harga",
        };
        mount({ group });
        await userEvent.click(screen.getByRole("button", { name: "Simpan" }));

        expect(put).toHaveBeenCalledWith("/settings.modifiers.update", expect.anything());
        expect(screen.getByLabelText("Minimum pilihan")).toHaveAccessibleDescription("Pesan minimum");
        expect(screen.getByRole("alert")).toHaveTextContent("Pesan default");
        expect(screen.getAllByLabelText("Tambahan harga (Rp)")[1]).toHaveAccessibleDescription("Pesan harga");
    });

    it("reads in English", async () => {
        await i18n.changeLanguage("en");
        mount();

        expect(screen.getByRole("radio", { name: "Required" })).toBeInTheDocument();
        expect(screen.getByRole("button", { name: "Add option" })).toBeInTheDocument();
        expect(screen.getByLabelText("Extra price (Rp)")).toBeInTheDocument();
    });
});
