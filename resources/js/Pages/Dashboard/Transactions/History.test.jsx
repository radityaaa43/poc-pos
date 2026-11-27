import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import "@/i18n";
import History from "./History";

vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    Link: ({ children }) => <a>{children}</a>,
    router: { get: vi.fn() },
    usePage: () => ({ props: {} }),
}));
vi.mock("@/Utils/authorization", () => ({ useAuthorization: () => ({ can: () => true }) }));
vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock("@/Components/POS/OpenBillPayModal", () => ({
    default: ({ bill }) => (bill ? <p>asks {bill.amount}</p> : null),
}));

// A dine bill of 25000 with 5000 returned while unpaid.
const bill = {
    id: 1,
    invoice: "TRX-ABC",
    grand_total: 25000,
    owed_total: 20000,
    payment_status: "unpaid",
    payment_method: "open_bill",
    created_at: "2026-11-28T10:00:00Z",
    dine_order: { id: 7, status: "accepted", table: { name: "Meja 3" } },
};

describe("Transactions History", () => {
    it("opens the pay dialog of an open bill with what it still owes", async () => {
        const user = userEvent.setup();
        render(<History transactions={{ data: [bill], links: [] }} filters={{}} />);

        // the row is drawn twice (table and mobile card)
        await user.click(screen.getAllByRole("button", { name: "Bayar" })[0]);

        expect(screen.getByText("asks 20000")).toBeInTheDocument();
    });
});
