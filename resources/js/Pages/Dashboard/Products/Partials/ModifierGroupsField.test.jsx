import { useRef, useState } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import i18n from "@/i18n";
import ModifierGroupsField from "./ModifierGroupsField";
import Create from "../Create";
import Edit from "../Edit";

let permissions;
let posted; // what a product page sends
vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));
vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    Link: ({ children, href }) => <a href={href}>{children}</a>,
    usePage: () => ({ props: { auth: { permissions }, errors: {} } }),
    useForm: (initial) => {
        const [data, setAll] = useState(initial);
        const transform = useRef((same) => same);
        return {
            data,
            setData: (key, value) => setAll((prev) => ({ ...prev, [key]: value })),
            transform: (callback) => (transform.current = callback),
            post: () => (posted = transform.current(data)),
        };
    },
}));

const groups = [
    { id: 1, name: "Ukuran", is_required: true },
    { id: 2, name: "Gula", is_required: false },
];
let sent;

function Harness({ initial = [], errors = {}, choices = groups }) {
    const [value, setValue] = useState(initial);
    sent = value;
    return <ModifierGroupsField groups={choices} value={value} onChange={setValue} errors={errors} />;
}

const attached = () => screen.queryAllByRole("listitem").map((item) => item.textContent);

beforeEach(async () => {
    permissions = { "modifiers-manage": true };
    await i18n.changeLanguage("id");
});

describe("ModifierGroupsField", () => {
    it("attaches groups and orders them with up and down buttons", async () => {
        render(<Harness />);
        const picker = screen.getByLabelText("Pasang grup modifier");

        await userEvent.selectOptions(picker, "Ukuran");
        await userEvent.selectOptions(picker, "Gula");
        expect(sent).toEqual([1, 2]);
        expect(screen.getByRole("button", { name: "Naikkan Ukuran" })).toBeDisabled();
        expect(screen.getByRole("button", { name: "Turunkan Gula" })).toBeDisabled();

        await userEvent.click(screen.getByRole("button", { name: "Naikkan Gula" }));
        expect(sent).toEqual([2, 1]);
        expect(attached()[0]).toContain("Gula");

        await userEvent.click(screen.getByRole("button", { name: "Turunkan Gula" }));
        expect(sent).toEqual([1, 2]);
    });

    // "" and not []: FormData (a new image) drops an empty array, so the groups would stay attached.
    it('sends "" once the last group is detached', async () => {
        render(<Harness initial={[1]} />);

        await userEvent.click(screen.getByRole("button", { name: "Lepas Ukuran" }));

        expect(sent).toBe("");
        expect(attached()).toEqual([]);
    });

    it("shows a server refusal, and points to the settings page while there is no group", () => {
        render(<Harness choices={[]} errors={{ "modifier_group_ids.0": "Pesan server" }} />);

        expect(screen.getByRole("alert")).toHaveTextContent("Pesan server");
        expect(screen.getByRole("link", { name: "Tambah grup" })).toHaveAttribute("href", "/settings.modifiers.index");
    });
});

describe.each([
    ["Create", <Create categories={[]} products={[]} modifier_groups={groups} />],
    ["Edit", <Edit categories={[]} modifier_groups={groups} product={{ id: 1, units: [], modifier_groups: [groups[0]] }} />],
])("%s product page: the groups it sends", (_name, page) => {
    const tickRawMaterial = () => userEvent.click(screen.getByRole("checkbox", { name: /Bahan baku/ }));
    const save = () => {
        fireEvent.submit(document.querySelector("form"));
        return posted;
    };

    // The server answers 403 to the field without the permission.
    it("hides the field and never sends it without modifiers-manage", async () => {
        permissions = { "products-edit": true };
        render(page);
        expect(screen.queryByLabelText("Pasang grup modifier")).not.toBeInTheDocument();
        expect(save()).not.toHaveProperty("modifier_group_ids");

        await tickRawMaterial();
        expect(save()).not.toHaveProperty("modifier_group_ids");
    });

    it("keeps the groups when raw material is ticked and unticked, and sends none for a raw material", async () => {
        render(page);
        await userEvent.selectOptions(screen.getByLabelText("Pasang grup modifier"), "Gula");
        const chosen = save().modifier_group_ids;

        await tickRawMaterial();
        expect(save()).not.toHaveProperty("modifier_group_ids");

        await tickRawMaterial();
        expect(chosen).toContain(2);
        expect(save().modifier_group_ids).toEqual(chosen);
    });
});
