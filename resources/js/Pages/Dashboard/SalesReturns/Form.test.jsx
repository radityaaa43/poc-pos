import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import i18n from "@/i18n";
import fixture from "../../../../../tests/fixtures/return-refund.json";
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

describe("SalesReturns/Form service and tax share", () => {
    const shareTransaction = (details, billShare) => ({
        ...transaction(details),
        bill_share: billShare,
    });

    const renderShare = (billShare) =>
        render(
            <Form
                title="Retur"
                transaction={shareTransaction([detail(1, "Nasi Goreng", "served", true)], billShare)}
                submitRoute="/x"
            />
        );

    const row = (label) => screen.getByText(label, { exact: true }).closest("div");

    // 5 % service and 10 % PB1 on Rp 20.000: returning 1 of 2 units refunds 10.000 + 500 + 1.050.
    const billShare = { base: 20000, net_base: 20000, service_charge: 1000, refundable_tax: 2100, returned_value: 0, returned_total: 0, paid: 23100 };

    it("shows the proportional service and tax rows and folds them into the refund", async () => {
        await i18n.changeLanguage("id");
        renderShare(billShare);

        await userEvent.clear(screen.getByRole("spinbutton"));
        await userEvent.type(screen.getByRole("spinbutton"), "1");

        expect(row("Service charge dikembalikan")).toHaveTextContent(/500/);
        expect(row("Pajak dikembalikan")).toHaveTextContent(/1\.050/);
        expect(row("Refund")).toHaveTextContent(/11\.550/);
        expect(row("Nominal retur")).toHaveTextContent(/11\.550/);
        expect(screen.getByText("Pembulatan tunai tidak dikembalikan.")).toBeInTheDocument();
    });

    it("shows the stored amounts of a completed return instead of recomputing the share", async () => {
        await i18n.changeLanguage("id");
        render(
            <Form
                title="Retur"
                transaction={transaction([detail(1, "Nasi Goreng", "served", true)])}
                salesReturn={{
                    status: "completed",
                    return_type: "refund_cash",
                    refund_amount: 23100,
                    credited_amount: 0,
                    service_refunded: 1000,
                    tax_refunded: 2100,
                    total_return_amount: 23100,
                    notes: "",
                }}
                submitRoute="/x"
                canEdit={false}
            />
        );

        expect(row("Service charge dikembalikan")).toHaveTextContent(/1\.000/);
        expect(row("Pajak dikembalikan")).toHaveTextContent(/2\.100/);
        expect(row("Nominal retur")).toHaveTextContent(/23\.100/);
        expect(screen.getByText("Nominal Retur").closest("div")).toHaveTextContent(/23\.100/);
    });

    it("shows a discounted full return as what was paid: the net items plus service and tax", async () => {
        await i18n.changeLanguage("id");
        render(
            <Form
                title="Retur"
                transaction={{
                    ...transaction([
                        { ...detail(1, "Nasi Goreng", "served", true), qty: 1, price: 100000 },
                    ]),
                    bill_share: {
                        base: 100000,
                        net_base: 90000,
                        service_charge: 4500,
                        refundable_tax: 9450,
                        returned_value: 0,
                        returned_total: 0,
                        paid: 103950,
                    },
                }}
                submitRoute="/x"
            />
        );

        await userEvent.clear(screen.getByRole("spinbutton"));
        await userEvent.type(screen.getByRole("spinbutton"), "1");

        expect(row("Diskon dipotong")).toHaveTextContent(/10\.000/);
        expect(row("Refund")).toHaveTextContent(/103\.950/);
        expect(row("Nominal retur")).toHaveTextContent(/103\.950/);
    });

    it("shows no service or tax rows when the sale has no bill share", async () => {
        await i18n.changeLanguage("id");
        renderForm([detail(1, "Nasi Goreng", "served", true)]);

        await userEvent.clear(screen.getByRole("spinbutton"));
        await userEvent.type(screen.getByRole("spinbutton"), "1");

        expect(screen.queryByText("Service charge dikembalikan")).not.toBeInTheDocument();
        expect(screen.queryByText("Pajak dikembalikan")).not.toBeInTheDocument();
        expect(screen.queryByText("Diskon dipotong")).not.toBeInTheDocument();
        expect(screen.queryByText("Poin kembali ke pelanggan")).not.toBeInTheDocument();
        expect(screen.queryByText("Pembulatan tunai tidak dikembalikan.")).not.toBeInTheDocument();
        expect(row("Refund")).toHaveTextContent(/10\.000/);
    });

    it("uses the English labels", async () => {
        await i18n.changeLanguage("en");
        renderShare(billShare);

        await userEvent.clear(screen.getByRole("spinbutton"));
        await userEvent.type(screen.getByRole("spinbutton"), "1");

        expect(screen.getByText("Service charge refunded")).toBeInTheDocument();
        expect(screen.getByText("Tax refunded")).toBeInTheDocument();
        expect(screen.getByText("Cash rounding is not refunded.")).toBeInTheDocument();
    });
});

describe("SalesReturns/Form refund net of discount and points", () => {
    // The same fixture ReturnNetOfDiscountTest reads in PHPUnit: the preview of the first return of each case.
    const amount = (value) =>
        new RegExp(String(value).replace(/\B(?=(\d{3})+(?!\d))/g, "\\."));
    const row = (label) => screen.getByText(label, { exact: true }).closest("div");

    const billShare = (bill) => ({
        base: bill.base,
        net_base:
            bill.base -
            bill.discount -
            bill.loyalty_discount_total -
            bill.customer_voucher_discount,
        service_charge: bill.service_charge,
        refundable_tax: bill.refundable_tax,
        points_redeemed: bill.points_redeemed,
        points_earned: bill.points_earned,
        returned_value: 0,
        returned_total: 0,
        paid: bill.grand_total - bill.shipping_cost,
    });

    it.each(fixture.cases.map((testCase) => [testCase.name, testCase]))(
        "previews %s",
        async (_name, testCase) => {
            await i18n.changeLanguage("id");
            const first = testCase.steps[0];
            const base = testCase.bill.base;

            render(
                <Form
                    title="Retur"
                    transaction={{
                        ...transaction([
                            {
                                ...detail(1, "Nasi Goreng", "served", true),
                                qty: base / first.gross,
                                price: base,
                            },
                        ]),
                        bill_share: billShare(testCase.bill),
                    }}
                    submitRoute="/x"
                />
            );

            await userEvent.clear(screen.getByRole("spinbutton"));
            await userEvent.type(screen.getByRole("spinbutton"), "1");

            if (first.discount_deducted > 0) {
                expect(row("Diskon dipotong")).toHaveTextContent(amount(first.discount_deducted));
            }
            if (first.points_restored > 0) {
                expect(row("Poin kembali ke pelanggan")).toHaveTextContent(amount(first.points_restored));
            }
            if (first.points_reversed > 0) {
                expect(row("Poin ditarik kembali")).toHaveTextContent(amount(first.points_reversed));
            }
            expect(row("Refund")).toHaveTextContent(amount(first.total));
            expect(row("Nominal retur")).toHaveTextContent(amount(first.total));
        }
    );
});
