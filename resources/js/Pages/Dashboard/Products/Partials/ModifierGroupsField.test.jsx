import { useState } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import i18n from "@/i18n";
import ModifierGroupsField from "./ModifierGroupsField";

let permissions;
vi.mock("@inertiajs/react", () => ({
    Link: ({ children, href }) => <a href={href}>{children}</a>,
    usePage: () => ({ props: { auth: { permissions } } }),
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
    it("is hidden without modifiers-manage", () => {
        permissions = { "products-edit": true };
        const { container } = render(<Harness />);

        expect(container).toBeEmptyDOMElement();
    });

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
