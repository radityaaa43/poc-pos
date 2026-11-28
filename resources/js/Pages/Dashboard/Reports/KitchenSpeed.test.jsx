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

const filters = { outlet_id: 1, station_id: null, start_date: "2026-11-01", end_date: "2026-11-30" };
const stations = [
    { id: 1, name: "Dapur", deleted: false },
    { id: 2, name: "Bar", deleted: false },
    { id: 3, name: "Bar", deleted: true },
];
const outlets = [
    { id: 1, name: "Outlet Pusat" },
    { id: 2, name: "Outlet Cabang" },
];
const row = (over = {}) => ({
    product_id: 1,
    title: "Kopi Susu",
    station_id: 2,
    station: "Bar",
    station_deleted: false,
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
    stations,
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
            "Stasiun",
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
            "Bar",
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

    it("shows a date validation error next to its field and keeps the end date from before the start", () => {
        render(<KitchenSpeed {...props({ errors: { end_date: "The end date must be a date after or equal to start date." } })} />);

        const end = screen.getByLabelText("Tanggal Akhir");
        expect(end).toHaveAttribute("aria-invalid", "true");
        expect(screen.getByText("The end date must be a date after or equal to start date.")).toBeInTheDocument();
        expect(end).toHaveAttribute("min", "2026-11-01");
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

    it("lists Semua stasiun first and the deleted stations last, labelled", () => {
        render(<KitchenSpeed {...props()} />);

        const select = screen.getByLabelText("Stasiun");
        expect(select).toHaveValue("");
        expect(within(select).getAllByRole("option").map((o) => o.textContent)).toEqual([
            "Semua stasiun",
            "Dapur",
            "Bar",
            "Bar (dihapus)",
        ]);
    });

    it("preselects the station from the filters", () => {
        render(<KitchenSpeed {...props({ filters: { ...filters, station_id: 3 } })} />);

        expect(screen.getByLabelText("Stasiun")).toHaveValue("3");
    });

    it("sends the chosen station in the query and leaves it out for Semua stasiun", async () => {
        render(<KitchenSpeed {...props()} />);

        await userEvent.click(screen.getByRole("button", { name: "Terapkan" }));
        expect(router.get.mock.calls[0][1]).not.toHaveProperty("station_id");

        await userEvent.selectOptions(screen.getByLabelText("Stasiun"), "3");
        await userEvent.click(screen.getByRole("button", { name: "Terapkan" }));
        expect(router.get).toHaveBeenLastCalledWith(
            "/reports.kitchen-speed.index",
            { outlet_id: "1", station_id: "3", start_date: "2026-11-01", end_date: "2026-11-30" },
            expect.any(Object),
        );

        await userEvent.selectOptions(screen.getByLabelText("Stasiun"), "");
        await userEvent.click(screen.getByRole("button", { name: "Terapkan" }));
        expect(router.get.mock.calls[2][1]).not.toHaveProperty("station_id");
    });

    it("marks a deleted station in its cell with text, not colour only", () => {
        render(
            <KitchenSpeed
                {...props({
                    rows: [
                        row({ station_id: 2 }),
                        row({ title: "Roti", station_id: 3, station_deleted: true }),
                    ],
                })}
            />,
        );

        const live = within(screen.getByRole("row", { name: /Kopi Susu/ })).getAllByRole("cell")[0];
        expect(live).toHaveTextContent(/^Bar$/);
        const deleted = within(screen.getByRole("row", { name: /Roti/ })).getAllByRole("cell")[0];
        expect(deleted).toHaveTextContent("Bar (dihapus)");
    });

    it("keeps one row per menu and station", () => {
        const error = vi.spyOn(console, "error").mockImplementation(() => {});
        render(
            <KitchenSpeed
                {...props({ rows: [row(), row({ station_id: 3, station: "Bar", station_deleted: true })] })}
            />,
        );

        expect(screen.getAllByRole("row", { name: /Kopi Susu/ })).toHaveLength(2);
        expect(error).not.toHaveBeenCalled();
    });

    it("captions the handover figure only when a station is chosen", () => {
        const caption = "Semua pesanan dari semua stasiun";
        const { unmount } = render(<KitchenSpeed {...props()} />);
        expect(screen.queryByText(caption)).not.toBeInTheDocument();
        unmount();

        render(<KitchenSpeed {...props({ filters: { ...filters, station_id: 2 } })} />);
        const card = screen.getByRole("region", { name: "Waktu bayar sampai diserahkan" });
        expect(within(card).getByText(caption)).toBeInTheDocument();
    });

    it("shows a station validation error next to the select", () => {
        render(<KitchenSpeed {...props({ errors: { station_id: "The selected station id is invalid." } })} />);

        const select = screen.getByLabelText("Stasiun");
        expect(select).toHaveAttribute("aria-invalid", "true");
        expect(screen.getByText("The selected station id is invalid.")).toBeInTheDocument();
    });

    it("reads the station texts in English too", async () => {
        await i18n.changeLanguage("en");
        render(<KitchenSpeed {...props({ filters: { ...filters, station_id: 2 }, rows: [row({ station_id: 3, station_deleted: true })] })} />);

        const select = screen.getByLabelText("Station");
        expect(within(select).getAllByRole("option").map((o) => o.textContent)).toEqual([
            "All stations",
            "Dapur",
            "Bar",
            "Bar (deleted)",
        ]);
        expect(screen.getByRole("columnheader", { name: "Station" })).toBeInTheDocument();
        expect(screen.getByText("All orders from all stations")).toBeInTheDocument();
        expect(screen.getByRole("row", { name: /Kopi Susu/ })).toHaveTextContent("Bar (deleted)");
    });

    it("shows the share of kitchen items with complete times", () => {
        render(
            <KitchenSpeed
                {...props({
                    completeness: { items: 20, complete: 19, percent: 95, below_target: false, target: 95 },
                })}
            />,
        );

        const card = screen.getByRole("region", { name: "Kelengkapan data" });
        expect(within(card).getByText("95,0%")).toBeInTheDocument();
        expect(within(card).getByText("19 dari 20 item dapur punya waktu lengkap sampai siap")).toBeInTheDocument();
        expect(within(card).queryByText("Di bawah target 95%")).not.toBeInTheDocument();
        expect(within(card).getByText("Item yang masih berjalan ikut dihitung; angkanya naik saat item selesai.")).toBeInTheDocument();
    });

    it("marks a share below the 95% target, in both languages", async () => {
        const completeness = { items: 20, complete: 18, percent: 90, below_target: true, target: 95 };
        const { unmount } = render(<KitchenSpeed {...props({ completeness })} />);
        expect(screen.getByText("Di bawah target 95%")).toBeInTheDocument();
        unmount();

        await i18n.changeLanguage("en");
        render(<KitchenSpeed {...props({ completeness })} />);
        expect(screen.getByText("Below the 95% target")).toBeInTheDocument();
        expect(screen.getByText("18 of 20 kitchen items have times up to ready")).toBeInTheDocument();
    });

    it("shows the card on a period whose items are all still open", () => {
        render(
            <KitchenSpeed
                {...props({
                    rows: [],
                    orders: { orders: 0, avg_minutes: null },
                    empty: true,
                    completeness: { items: 4, complete: 0, percent: 0, below_target: true, target: 95 },
                })}
            />,
        );

        expect(screen.getByText("Belum ada data dapur untuk periode ini")).toBeInTheDocument();
        expect(screen.getByRole("region", { name: "Kelengkapan data" })).toBeInTheDocument();
        expect(screen.getByText("0 dari 4 item dapur punya waktu lengkap sampai siap")).toBeInTheDocument();
    });

    it("hides the card when there are no kitchen items", () => {
        render(
            <KitchenSpeed
                {...props({ completeness: { items: 0, complete: 0, percent: null, below_target: false, target: 95 } })}
            />,
        );

        expect(screen.queryByRole("region", { name: "Kelengkapan data" })).not.toBeInTheDocument();
    });
});
