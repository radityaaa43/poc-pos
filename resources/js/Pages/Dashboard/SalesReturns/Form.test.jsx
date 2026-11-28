import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import i18n from "@/i18n";
import Form from "./Form";

// A stateful useForm so ticking the box re-renders the page like the real one.
vi.mock("@inertiajs/react", async () => {
    const { useState } = await import("react");
    return {
        Head: () => null,
        Link: ({ children, href }) => <a href={href}>{children}</a>,
        router: { post: vi.fn() },
        useForm: (initial) => {
            const [data, setData] = useState(initial);
            return {
                data,
                setData: (key, value) => setData(typeof key === "string" ? { ...data, [key]: value } : key),
                post: vi.fn(),
                put: vi.fn(),
                processing: false,
                errors: {},
            };
        },
    };
});
vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));

const HINT = "Sudah dibuat di dapur: default tidak dikembalikan ke stok";

const detail = (id, title, kitchen_status, restock_default, draft_item = null) => ({
    id,
    qty: 2,
    price: 20000,
    returned_completed_qty: 0,
    remaining_returnable_qty: 2,
    product: { title, barcode: `B${id}` },
    kitchen_status,
    restock_default,
    draft_item,
});

const transaction = (details) => ({
    invoice: "INV-1",
    created_at: "2026-11-25T10:00:00Z",
    grand_total: 20000,
    payment_status: "paid",
    payment_method: "cash",
    customer: null,
    receivable: null,
    details,
});

const renderForm = (details) =>
    render(<Form title="Retur" transaction={transaction(details)} submitRoute="/x" />);

const restockBox = (title) =>
    within(screen.getByText(title).closest("tr")).getByRole("checkbox");

it("lists each line's sold options and note, so two lines of one menu can be told apart", () => {
    renderForm([
        {
            ...detail(1, "Kopi Susu", null, true),
            modifiers: [{ option_name: "Large", price_delta: 5000 }],
            note: "es sedikit",
        },
        { ...detail(2, "Kopi Susu", null, true), modifiers: [], note: null },
    ]);
    const [large, plain] = screen.getAllByText("Kopi Susu").map((title) => within(title.closest("tr")));

    expect(large.getByText("+ Large 5.000")).toBeInTheDocument();
    expect(large.getByText("Catatan: es sedikit")).toBeInTheDocument();
    expect(plain.queryByText(/Large|Catatan/)).not.toBeInTheDocument();
});

beforeEach(async () => {
    globalThis.route = (name) => `/${name}`;
    await i18n.changeLanguage("id");
});

describe("SalesReturns/Form restock default", () => {
    it("follows restock_default per kitchen status", () => {
        renderForm([
            detail(1, "Nasi Goreng", "accepted", true),
            detail(2, "Es Teh", "preparing", false),
            detail(3, "Kopi", "ready", false),
            detail(4, "Roti", "served", true),
            detail(5, "Air Mineral", null, true),
        ]);

        expect(restockBox("Nasi Goreng")).toBeChecked();
        expect(restockBox("Es Teh")).not.toBeChecked();
        expect(restockBox("Kopi")).not.toBeChecked();
        expect(restockBox("Roti")).toBeChecked();
        expect(restockBox("Air Mineral")).toBeChecked();
    });

    it("shows the hint only on preparing and ready items, tied to the checkbox", () => {
        renderForm([
            detail(1, "Nasi Goreng", "accepted", true),
            detail(2, "Es Teh", "preparing", false),
            detail(3, "Kopi", "ready", false),
            detail(4, "Roti", "served", true),
        ]);

        expect(screen.getAllByText(HINT)).toHaveLength(2);
        expect(restockBox("Es Teh")).toHaveAccessibleDescription(HINT);
        expect(restockBox("Kopi")).toHaveAccessibleDescription(HINT);
        expect(restockBox("Nasi Goreng")).not.toHaveAccessibleDescription();
        expect(restockBox("Roti")).not.toHaveAccessibleDescription();
    });

    it("shows no hint on a form that can no longer be edited (a completed return)", () => {
        render(
            <Form
                title="Retur"
                transaction={transaction([detail(2, "Es Teh", "preparing", false)])}
                submitRoute="/x"
                canEdit={false}
            />
        );

        expect(screen.queryByText(HINT)).not.toBeInTheDocument();
    });

    it("lets the person tick the box on a preparing item", async () => {
        renderForm([detail(2, "Es Teh", "preparing", false)]);

        await userEvent.click(restockBox("Es Teh"));

        expect(restockBox("Es Teh")).toBeChecked();
    });

    it("keeps the saved draft choice over the default", () => {
        renderForm([
            detail(1, "Es Teh", "preparing", false, { qty_return: 1, return_reason: "", restock_to_inventory: true }),
            detail(2, "Nasi Goreng", "accepted", true, { qty_return: 1, return_reason: "", restock_to_inventory: false }),
        ]);

        expect(restockBox("Es Teh")).toBeChecked();
        expect(restockBox("Nasi Goreng")).not.toBeChecked();
    });

    it("uses the English hint", async () => {
        await i18n.changeLanguage("en");
        renderForm([detail(2, "Es Teh", "preparing", false)]);

        expect(screen.getByText("Already being made in the kitchen: not restocked by default")).toBeInTheDocument();
    });
});
