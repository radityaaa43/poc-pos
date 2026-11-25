import { useState } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import i18n from "@/i18n";
import Create from "./Create";
import Edit from "./Edit";

vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));
vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    Link: ({ children }) => children,
    usePage: () => ({ props: { errors: {} } }),
    // Just enough of Inertia's useForm: state plus setData(key, value) / setData(object).
    useForm: (initial) => {
        const [data, setAll] = useState(initial);
        const setData = (key, value) =>
            setAll((prev) => (typeof key === "string" ? { ...prev, [key]: value } : { ...prev, ...key }));
        return { data, setData, post: vi.fn(), put: vi.fn(), processing: false, errors: {} };
    },
}));

const kitchenStations = [
    { id: 1, name: "Dapur", is_default: true },
    { id: 2, name: "Bar", is_default: false },
];
const product = { id: 1, barcode: "B1", sku: "S1", title: "Kopi", buy_price: 1000, sell_price: 2000, units: [] };

const mountCreate = () => render(<Create categories={[]} products={[]} kitchen_stations={kitchenStations} />);
const mountEdit = (extra = {}) =>
    render(
        <Edit
            categories={[]}
            products={[]}
            kitchen_stations={kitchenStations}
            product={{ ...product, ...extra }}
        />,
    );

const flag = () => screen.getByRole("checkbox", { name: "Perlu dibuat di dapur" });
const select = () => screen.queryByLabelText("Stasiun dapur");

beforeEach(async () => {
    await i18n.changeLanguage("id");
});

describe.each([
    ["Create", mountCreate],
    ["Edit", mountEdit],
])("%s product page: kitchen station select", (_name, mount) => {
    it("is hidden until the product is flagged as needing preparation", async () => {
        mount();
        expect(select()).not.toBeInTheDocument();

        await userEvent.click(flag());

        expect(select()).toBeInTheDocument();
    });

    it("lists the active stations and preselects Dapur", async () => {
        mount();
        await userEvent.click(flag());

        expect(select()).toHaveDisplayValue("Dapur");
        expect([...select().options].map((o) => o.text)).toEqual(["Dapur", "Bar"]);
    });

    it("clears the choice when the flag is switched off and on again", async () => {
        mount();
        await userEvent.click(flag());
        await userEvent.selectOptions(select(), "Bar");
        expect(select()).toHaveDisplayValue("Bar");

        await userEvent.click(flag());
        expect(select()).not.toBeInTheDocument();
        await userEvent.click(flag());

        expect(select()).toHaveDisplayValue("Dapur");
    });

    it("renders its label in English", async () => {
        await i18n.changeLanguage("en");
        mount();
        await userEvent.click(screen.getByRole("checkbox", { name: /Needs preparation/ }));

        expect(screen.getByLabelText("Kitchen station")).toBeInTheDocument();
    });
});

describe("Edit product page: kitchen station select", () => {
    it("shows the saved station of a flagged product", () => {
        mountEdit({ needs_preparation: true, kitchen_station_id: 2 });

        expect(select()).toHaveDisplayValue("Bar");
    });

    it("shows Dapur for a flagged product that was never mapped", () => {
        mountEdit({ needs_preparation: true, kitchen_station_id: null });

        expect(select()).toHaveDisplayValue("Dapur");
    });

    it("shows no select for an unflagged product", () => {
        mountEdit({ needs_preparation: false, kitchen_station_id: null });

        expect(select()).not.toBeInTheDocument();
    });
});
