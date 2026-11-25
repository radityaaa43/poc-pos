import { useState } from "react";
import { describe, expect, it, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
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

const units = [
    { id: 1, code: "pcs" },
    { id: 2, code: "box" },
    { id: 3, code: "kg" },
];
const row = (unit_id, is_base) => ({
    unit_id,
    is_base,
    conversion_factor: 1,
    buy_price: "",
    sell_price: "",
    is_purchase_default: false,
    barcode: "",
});
const product = {
    id: 1,
    barcode: "B1",
    sku: "S1",
    title: "Kopi",
    buy_price: 1000,
    sell_price: 2000,
    units: [
        { id: 1, pivot: { is_base: true, conversion_factor: 1 } },
        { id: 2, pivot: { is_base: false, conversion_factor: 10 } },
    ],
};

describe.each([
    ["Create", () => render(<Create categories={[]} products={[]} units={units} />)],
    ["Edit", () => render(<Edit categories={[]} products={[]} units={units} product={product} />)],
])("%s page: removing a unit row", (name, mount) => {
    it("moves focus to the Tambah Satuan button", async () => {
        mount();
        if (name === "Create") {
            const add = screen.getByRole("button", { name: "+ Tambah Satuan" });
            fireEvent.click(add);
            fireEvent.click(add);
        }
        expect(screen.getAllByRole("button", { name: /^Hapus satuan/ })).toHaveLength(2);

        fireEvent.click(screen.getAllByRole("button", { name: /^Hapus satuan/ })[0]);

        await waitFor(() =>
            expect(document.activeElement).toBe(screen.getByRole("button", { name: "+ Tambah Satuan" }))
        );
    });
});
