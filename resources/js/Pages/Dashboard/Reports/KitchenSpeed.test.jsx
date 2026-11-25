import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { router } from "@inertiajs/react";
import i18n from "@/i18n";
import KitchenSpeed from "./KitchenSpeed";

vi.mock("@inertiajs/react", async (importActual) => ({
    ...(await importActual()),
    Head: () => null,
}));
vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));

const filters = { outlet_id: 1, start_date: "2026-11-01", end_date: "2026-11-30" };
const outlets = [
    { id: 1, name: "Outlet Pusat" },
    { id: 2, name: "Outlet Cabang" },
];
const row = (over = {}) => ({
    product_id: 1,
    title: "Kopi Susu",
    count_rows: 4,
    count_portions: 9,
    make_median: 4.5,
    make_avg: 5,
    wait_make_median: 6.5,
    wait_make_avg: 7.2,
    target: 5,
    within_target_pct: 75,
    ...over,
});
const props = (over = {}) => ({
    filters,
    outlets,
    rows: [row()],
    orders: { orders: 3, avg_minutes: 12.3 },
    empty: false,
    ...over,
});

describe("Kitchen speed report page", () => {
    beforeEach(async () => {
        window.route = (name) => `/${name}`;
        vi.spyOn(router, "get").mockImplementation(() => {});
        await i18n.changeLanguage("id");
    });
    afterEach(() => vi.restoreAllMocks());

    it("renders a column for both counts and each timing, with scoped headers", () => {
        render(<KitchenSpeed {...props()} />);

        const headers = screen.getAllByRole("columnheader");
        expect(headers.map((h) => h.textContent)).toEqual([
            "Menu",
            "Jumlah pesanan",
            "Jumlah porsi",
            "Median buat",
            "Rata-rata buat",
            "Median tunggu + buat",
            "Rata-rata tunggu + buat",
            "Target",
            "Dalam target",
        ]);
        headers.forEach((h) => expect(h).toHaveAttribute("scope", "col"));

        const cells = within(screen.getByRole("row", { name: /Kopi Susu/ })).getAllByRole("cell");
        expect(cells.map((c) => c.textContent)).toEqual([
            "4",
            "9",
            "4,5 mnt",
            "5,0 mnt",
            "6,5 mnt",
            "7,2 mnt",
            "5,0 mnt",
            "75,0%",
        ]);
    });

    it("shows a dash for Target and Dalam target when the item has no target", () => {
        render(
            <KitchenSpeed {...props({ rows: [row({ target: null, within_target_pct: null })] })} />,
        );

        const cells = within(screen.getByRole("row", { name: /Kopi Susu/ })).getAllByRole("cell");
        expect(cells.slice(-2).map((c) => c.textContent)).toEqual(["-", "-"]);
    });

    it("formats minutes with one decimal in the page language", async () => {
        render(<KitchenSpeed {...props({ rows: [row({ make_median: 4.5 })] })} />);
        expect(screen.getAllByText("4,5 mnt").length).toBeGreaterThan(0);

        await i18n.changeLanguage("en");
        render(<KitchenSpeed {...props({ rows: [row({ make_median: 4.5 })] })} />);
        expect(screen.getAllByText("4.5 min").length).toBeGreaterThan(0);
    });

    it("shows the average payment to handover and its order count", () => {
        render(<KitchenSpeed {...props()} />);

        const card = screen.getByRole("region", { name: "Waktu bayar sampai diserahkan" });
        expect(within(card).getByText("12,3 mnt")).toBeInTheDocument();
        expect(within(card).getByText("3")).toBeInTheDocument();
    });

    it("says the period is empty instead of showing zeros", () => {
        render(
            <KitchenSpeed
                {...props({ rows: [], orders: { orders: 0, avg_minutes: null }, empty: true })}
            />,
        );

        expect(screen.getByText("Belum ada data dapur untuk periode ini")).toBeInTheDocument();
        expect(screen.queryByRole("table")).not.toBeInTheDocument();
    });

    it("lists only the given outlets and hides the select for a single outlet", () => {
        const { unmount } = render(<KitchenSpeed {...props()} />);
        const select = screen.getByLabelText("Outlet");
        expect(within(select).getAllByRole("option").map((o) => o.textContent)).toEqual([
            "Outlet Pusat",
            "Outlet Cabang",
        ]);
        unmount();

        render(<KitchenSpeed {...props({ outlets: [outlets[0]] })} />);
        expect(screen.queryByLabelText("Outlet")).not.toBeInTheDocument();
    });

    it("sends the chosen outlet and dates as the query", async () => {
        render(<KitchenSpeed {...props()} />);

        await userEvent.selectOptions(screen.getByLabelText("Outlet"), "2");
        await userEvent.clear(screen.getByLabelText("Tanggal Mulai"));
        await userEvent.type(screen.getByLabelText("Tanggal Mulai"), "2026-11-10");
        await userEvent.click(screen.getByRole("button", { name: "Terapkan" }));

        expect(router.get).toHaveBeenCalledWith(
            "/reports.kitchen-speed.index",
            { outlet_id: "2", start_date: "2026-11-10", end_date: "2026-11-30" },
            expect.any(Object),
        );
    });
});
