// Lives outside Pages/ because the Inertia page glob would bundle a test placed there.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";

vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    Link: ({ href, children, ...rest }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: { get: vi.fn(), post: vi.fn() },
    usePage: () => ({ props: { auth: { super: true } } }),
}));
vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));

import History from "@/Pages/Dashboard/Transactions/History";

const transaction = {
    id: 1,
    invoice: "INV-1",
    access_token: "tok-123",
    created_at: "2026-11-25T12:00:28.000000Z",
    cashier: { name: "Kasir" },
    customer: null,
    total_items: 1,
    grand_total: 10000,
    payment_method: "cash",
    payment_status: "paid",
    can_create_sales_return: false,
};

const originalRoute = globalThis.route;

beforeEach(() => {
    // Ziggy's route(name, params, absolute): an array carries positional params and, last, the query;
    // a third argument is the `absolute` flag, never the query.
    globalThis.route = (name, params = [], absolute = true) => {
        const list = Array.isArray(params) ? [...params] : [params];
        const query =
            list.length && typeof list[list.length - 1] === "object" ? list.pop() : {};
        const qs = new URLSearchParams(query).toString();
        const path = `/${name === "transactions.public" ? "share/transactions" : name}${
            list.length ? `/${list[0]}` : ""
        }${qs ? `?${qs}` : ""}`;
        return absolute ? `http://host${path}` : path;
    };
});

afterEach(() => {
    globalThis.route = originalRoute;
});

const renderHistory = () =>
    render(<History transactions={{ data: [transaction], total: 1 }} filters={{}} />);

describe("History", () => {
    it("shows the sale date readably, not as the raw timestamp (#134)", () => {
        renderHistory();

        expect(screen.queryAllByText(transaction.created_at)).toHaveLength(0);
        expect(screen.getAllByText(/25 Nov 2026/).length).toBeGreaterThan(0);
    });
});
