import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { router } from "@inertiajs/react";
import Index from "./Index";

vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    Link: ({ children }) => <a>{children}</a>,
    router: { get: vi.fn(), post: vi.fn() },
    usePage: () => ({ props: { flash: {} } }),
}));
vi.mock("react-i18next", () => ({ useTranslation: () => ({ t: (key) => key, i18n: { language: "id" } }) }));
vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));
vi.mock("@/Components/Dashboard/Pagination", () => ({ default: () => null }));
vi.mock("@/Components/Dashboard/Skeleton", () => ({ SkeletonTable: () => null }));
vi.mock("@/Components/Dashboard/Select", () => ({ default: ({ label, children }) => <div><span>{label}</span>{children}</div> }));
vi.mock("@/Components/Dashboard/Modal", () => ({ default: ({ show, title, children }) => (show ? <div role="dialog" aria-label={title}>{children}</div> : null) }));
vi.mock("@/Utils/authorization", () => ({ useAuthorization: () => ({ can: () => true }) }));

const row = (extra = {}) => ({
    id: 7, created_at: "2026-11-29T08:00:00Z", warehouse: { name: "Gudang A" }, reason: "damaged",
    total_value: 2500, status: "pending", creator: { name: "Sari" }, lines: [], moves: [],
    can_approve: false, can_reject: false, can_cancel: false, awaiting_other: false, ...extra,
});

const mount = (waste) => render(<Index wastes={{ data: [waste], links: [] }} filters={{}} warehouses={[]} />);

beforeEach(() => {
    vi.clearAllMocks();
    globalThis.route = (name, id) => `/route/${name}${id === undefined ? "" : `/${id}`}`;
});

describe("StockWaste Index actions", () => {
    it("approves a pending row after a confirm", async () => {
        vi.stubGlobal("confirm", () => true);
        mount(row({ can_approve: true }));

        await userEvent.click(screen.getByRole("button", { name: "waste.actions.approve" }));

        expect(router.post).toHaveBeenCalledWith("/route/stock-waste.approve/7", {}, { preserveScroll: true });
        vi.unstubAllGlobals();
    });

    it("opens the reason modal for a reject and posts the reason", async () => {
        mount(row({ can_reject: true }));

        await userEvent.click(screen.getByRole("button", { name: "waste.actions.reject" }));
        const dialog = screen.getByRole("dialog", { name: "waste.reasonModal.reject" });
        await userEvent.type(within(dialog).getByRole("textbox"), "Salah input");
        await userEvent.click(within(dialog).getByRole("button", { name: "waste.reasonModal.confirm" }));

        expect(router.post).toHaveBeenCalledWith("/route/stock-waste.reject/7", { reason: "Salah input" }, expect.any(Object));
    });

    it("tells the creator who can approve that another approver must", () => {
        mount(row({ awaiting_other: true }));

        expect(screen.getByText("waste.awaitingOther")).toBeInTheDocument();
        expect(screen.queryByRole("button", { name: "waste.actions.approve" })).not.toBeInTheDocument();
    });
});
