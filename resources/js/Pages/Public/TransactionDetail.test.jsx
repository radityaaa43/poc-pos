import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import TransactionDetail from "./TransactionDetail";

vi.mock("@inertiajs/react", () => ({ Head: () => null }));

const transaction = (overrides = {}) => ({
    invoice: "INV-1",
    created_at: "2026-11-25T10:00:00Z",
    payment_method: "pay_later",
    payment_status: "unpaid",
    grand_total: 25000,
    discount: 0,
    shipping_cost: 0,
    tax_total: 0,
    cash: 0,
    change: 0,
    details: [],
    receivable: { id: 7, total: 25000, paid: 0, remaining: 25000, status: "unpaid", due_date: null },
    ...overrides,
});

describe("Public TransactionDetail", () => {
    // #124: the pay endpoint flashes an error that used to be dropped.
    it("shows the error when the payment could not start", () => {
        render(<TransactionDetail transaction={transaction()} token="t" error="Gateway pembayaran belum dikonfigurasi." />);

        expect(screen.getByRole("alert")).toHaveTextContent("Gateway pembayaran belum dikonfigurasi.");
    });

    it("labels a failed payment instead of printing the raw status", () => {
        render(<TransactionDetail transaction={transaction({ payment_status: "failed" })} token="t" />);

        expect(screen.getByText("Gagal")).toBeInTheDocument();
        expect(screen.queryByText("failed")).not.toBeInTheDocument();
    });
});
