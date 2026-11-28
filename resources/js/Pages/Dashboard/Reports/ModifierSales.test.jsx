import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { router } from "@inertiajs/react";
import i18n from "@/i18n";
import ModifierSales from "./ModifierSales";

vi.mock("@inertiajs/react", async (importActual) => ({
    ...(await importActual()),
    Head: () => null,
}));
vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));

const rows = (data) => ({ data, links: [], per_page: 50 });
const large = { modifier_option_id: 7, group_name: "Size", option_name: "Large", qty: 1200, revenue: 6000000 };
const renderPage = (data, filters = { start_date: null, end_date: null }) =>
    render(<ModifierSales rows={rows(data)} filters={filters} />);

describe("Sales per option report page", () => {
    beforeEach(async () => {
        window.route = (name) => `/${name}`;
        vi.spyOn(router, "get").mockImplementation(() => {});
        await i18n.changeLanguage("id");
    });
    afterEach(() => vi.restoreAllMocks());

    it("lists group, option, quantity and revenue under the before-discounts heading", () => {
        renderPage([large]);
        const [head, row] = screen.getAllByRole("row");

        expect(within(head).getAllByRole("columnheader").map((cell) => cell.textContent)).toEqual([
            "Grup",
            "Opsi",
            "Terjual",
            "Omzet opsi (sebelum diskon)",
        ]);
        expect(row.textContent.replace(/\s/g, "")).toBe("SizeLarge1.200Rp6.000.000");
    });

    it("reads in English", async () => {
        await i18n.changeLanguage("en");
        renderPage([large]);

        expect(screen.getByRole("heading", { name: "Sales per Option" })).toBeInTheDocument();
        expect(screen.getByRole("columnheader", { name: "Option revenue (before discounts)" })).toBeInTheDocument();
    });

    it("says so when the period has no option sales", () => {
        renderPage([]);

        expect(screen.getByText("Belum ada opsi terjual pada periode ini.")).toBeInTheDocument();
        expect(screen.queryByRole("table")).not.toBeInTheDocument();
    });

    it("sends only the filled dates", async () => {
        renderPage([], { start_date: "2026-11-01", end_date: null });

        await userEvent.click(screen.getByRole("button", { name: "Terapkan" }));

        expect(router.get).toHaveBeenCalledWith("/reports.modifiers.index", { start_date: "2026-11-01" }, expect.anything());
    });
});
