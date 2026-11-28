import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { router } from "@inertiajs/react";
import Create from "./Create";

const form = vi.hoisted(() => ({ post: vi.fn(), sent: [], errors: {}, processing: false }));

vi.mock("@inertiajs/react", async () => {
    const { useState } = await import("react");

    return {
        Head: () => null,
        Link: ({ children }) => <a>{children}</a>,
        router: { reload: vi.fn() },
        useForm: (initial) => {
            const [data, set] = useState(initial);

            return {
                data,
                setData: (key, value) => set((old) => ({ ...old, [key]: value })),
                post: () => form.sent.push(data),
                processing: form.processing,
                errors: form.errors,
            };
        },
    };
});
vi.mock("react-i18next", () => ({ useTranslation: () => ({ t: (key, values) => [key, ...Object.values(values ?? {})].join(" ") }) }));
vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));

const props = {
    warehouses: [{ id: 1, code: "A", name: "Gudang A" }, { id: 2, code: "B", name: "Gudang B" }],
    warehouseId: 1,
    reasons: ["damaged"],
    // 0.4 of a Rp 1,000 ingredient per menu: one menu takes nothing, three take 1 unit, as on the server.
    items: [{ id: 5, title: "Latte", unit_symbol: null, stock: null, parts: [[250.5, 18], [1000, 0.4]] }],
};

const pickLatte = async (qty) => {
    await userEvent.click(screen.getByRole("button", { name: "waste.chooseItem" }));
    await userEvent.click(screen.getByRole("option", { name: "Latte" }));
    await userEvent.type(screen.getByRole("spinbutton"), String(qty));
};

beforeEach(() => {
    vi.clearAllMocks();
    Object.assign(form, { sent: [], errors: {}, processing: false });
    vi.stubGlobal("route", (name) => name);
    // jsdom has none; the headless listbox asks for it.
    vi.stubGlobal("ResizeObserver", class { observe() {} unobserve() {} disconnect() {} });
});

describe("StockWaste/Create", () => {
    it("shows the value before saving, rounded as the server takes the stock", async () => {
        render(<Create {...props} />);

        await pickLatte(3);

        // round(250.5 x 54) + round(1000 x round(1.2))
        expect(screen.getByText(/waste\.total/)).toHaveTextContent("14.527");
    });

    it("sends the same client key when the form is submitted again", async () => {
        render(<Create {...props} />);
        await pickLatte(1);

        await userEvent.click(screen.getByRole("button", { name: "waste.save" }));
        await userEvent.click(screen.getByRole("button", { name: "waste.save" }));

        expect(form.sent).toHaveLength(2);
        expect(form.sent[0].client_key).toMatch(/^[0-9a-f-]{36}$/);
        expect(form.sent[1].client_key).toBe(form.sent[0].client_key);
        expect(form.sent[0].lines[0]).toMatchObject({ product_id: 5, qty: "1" });
    });

    it("disables saving while the request runs and shows a short stock at the top", () => {
        Object.assign(form, { processing: true, errors: { stock: "Stok Kopi tidak mencukupi. Tersedia: 5." } });

        render(<Create {...props} />);

        expect(screen.getByRole("button", { name: "waste.save" })).toBeDisabled();
        expect(screen.getByRole("alert")).toHaveTextContent("Tersedia: 5");
    });

    it("reloads the items of the chosen warehouse", async () => {
        render(<Create {...props} />);

        await userEvent.selectOptions(screen.getByLabelText("waste.warehouse"), "2");

        expect(router.reload).toHaveBeenCalledWith({ only: ["items"], data: { warehouse_id: "2" } });
    });
});
