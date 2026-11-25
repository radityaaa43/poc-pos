// Lives outside Pages/ because the Inertia page glob would bundle a test placed there.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";

vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    Link: ({ href, children, ...rest }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: { get: vi.fn(), post: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: { auth: { super: true }, storeProfile: {}, printerSettings: {} } }),
}));
vi.mock("react-hot-toast", () => ({ default: { success: vi.fn(), error: vi.fn() }, Toaster: () => null }));

import Print from "@/Pages/Dashboard/Transactions/Print";

const transaction = {
    id: 1,
    invoice: "INV-1",
    access_token: "tok-123",
    created_at: "2026-11-25T12:00:28.000000Z",
    cashier: { name: "Kasir" },
    customer: null,
    details: [],
    grand_total: 10000,
    payment_method: "cash",
    payment_status: "paid",
};

const originalRoute = globalThis.route;

beforeEach(() => {
    // Ziggy's route() already returns an absolute URL.
    globalThis.route = (name, params = []) => {
        const [invoice, query] = Array.isArray(params) ? params : [params];
        return `http://host/${name === "portal.transaction" ? "portal/transactions" : name}/${invoice}?token=${query?.token}`;
    };
    vi.spyOn(window, "alert").mockImplementation(() => {});
    Object.defineProperty(navigator, "clipboard", {
        value: { writeText: vi.fn() },
        configurable: true,
    });
});

afterEach(() => {
    globalThis.route = originalRoute;
    vi.restoreAllMocks();
});

describe("Print", () => {
    it("Share copies the customer portal link as it is, with the origin once (#111)", () => {
        render(<Print transaction={transaction} />);

        fireEvent.click(screen.getByRole("button", { name: /Share/ }));

        expect(navigator.clipboard.writeText).toHaveBeenCalledWith(
            "http://host/portal/transactions/INV-1?token=tok-123"
        );
    });
});
