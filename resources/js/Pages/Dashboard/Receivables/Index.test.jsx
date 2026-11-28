import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { router } from "@inertiajs/react";
import i18n from "@/i18n";
import ReceivablesIndex from "./Index";

vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));
vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    Link: ({ children, href, className }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
    router: { get: vi.fn(), visit: vi.fn() },
    usePage: () => ({ props: { flash: {} } }),
}));

const row = {
    id: 1,
    invoice: "TRX-1",
    customer: { name: "Budi" },
    total: 800000,
    remaining: 800000,
    status: "unpaid",
    credit: { limit: 1000000, used: 800000, percent: 80, state: "near" },
};
const renderPage = (filters = {}) => render(<ReceivablesIndex receivables={{ data: [row], total: 1, links: [] }} filters={filters} />);
const creditFilter = () => screen.getByRole("combobox", { name: "Batas piutang" });

describe("Receivables list: credit limit usage", () => {
    beforeAll(() => i18n.changeLanguage("id"));
    beforeEach(() => {
        vi.clearAllMocks();
        globalThis.route = (name, id) => `/${name}/${id ?? ""}`;
    });

    it("shows the Batas and Terpakai % columns, saying usage counts every outlet", () => {
        renderPage();

        expect(screen.getByText("Batas")).toBeInTheDocument();
        expect(screen.getByText("Terpakai (di semua outlet)")).toBeInTheDocument();
        expect(screen.getByTitle("Terpakai (di semua outlet)")).toHaveTextContent("Terpakai %");
        const listed = screen.getByRole("link", { name: /TRX-1/ });
        expect(listed).toHaveTextContent("Rp 1.000.000");
        expect(listed).toHaveTextContent("80%Mendekati batas (≥ 80%)");
    });

    it("offers the three credit filters and applies the chosen one with the others", async () => {
        renderPage();

        expect(within(creditFilter()).getAllByRole("option").map((option) => option.textContent)).toEqual([
            "Semua batas piutang",
            "Mendekati batas (≥ 80%)",
            "Melewati batas",
            "Tanpa batas",
        ]);
        await userEvent.selectOptions(creditFilter(), "near");
        await userEvent.click(screen.getByRole("button", { name: "Terapkan" }));

        expect(router.get).toHaveBeenCalledWith("/receivables.index/", { invoice: "", status: "", credit: "near" }, expect.anything());
    });

    it("keeps the applied filter selected", () => {
        renderPage({ credit: "over" });

        expect(creditFilter()).toHaveValue("over");
    });
});
