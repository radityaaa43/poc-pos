import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { router } from "@inertiajs/react";
import i18n from "@/i18n";
import KitchenUnprinted from "./KitchenUnprinted";

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
const ticket = (over = {}) => ({
    key: "t1-s0",
    date: "2026-11-10",
    time: "2026-11-10T10:15:00+07:00",
    number: "Meja 5",
    type: "dine_in",
    station: "Dapur",
    devices: ["Dapur 1"],
    items: 2,
    ...over,
});
const props = (over = {}) => ({
    filters,
    outlets,
    tickets: [ticket()],
    days: [{ date: "2026-11-10", count: 1 }],
    total: 1,
    truncated: false,
    ...over,
});

describe("Kitchen unprinted report page", () => {
    beforeEach(async () => {
        window.route = (name) => `/${name}`;
        vi.spyOn(router, "get").mockImplementation(() => {});
        await i18n.changeLanguage("id");
    });
    afterEach(() => vi.restoreAllMocks());

    it("lists the day counts and each ticket's time, order, station, device and item count", () => {
        render(<KitchenUnprinted {...props()} />);

        const daily = screen.getByRole("table", { name: "Jumlah per hari" });
        expect(within(daily).getByRole("row", { name: /2026-11-10/ })).toHaveTextContent("1");

        const list = screen.getByRole("table", { name: "Tiket tidak tercetak" });
        const headers = within(list).getAllByRole("columnheader");
        expect(headers.map((h) => h.textContent)).toEqual([
            "Waktu",
            "Pesanan",
            "Stasiun",
            "Perangkat cetak",
            "Item",
        ]);
        headers.forEach((h) => expect(h).toHaveAttribute("scope", "col"));

        const row = within(list).getByRole("row", { name: /Meja 5/ });
        expect(within(row).getByRole("rowheader")).toHaveTextContent("2026-11-10 10:15");
        expect(within(row).getAllByRole("cell").map((c) => c.textContent)).toEqual([
            "Meja 5",
            "Dapur",
            "Dapur 1",
            "2",
        ]);
    });

    it("says every ticket printed instead of showing tables when nothing is unprinted", () => {
        render(<KitchenUnprinted {...props({ tickets: [], days: [], total: 0 })} />);

        expect(screen.getByText("Semua tiket pada periode ini tercetak")).toBeInTheDocument();
        expect(screen.queryByRole("table")).not.toBeInTheDocument();
    });

    it("warns when the list is capped", () => {
        render(<KitchenUnprinted {...props({ truncated: true, total: 700 })} />);

        expect(screen.getByText("Menampilkan 1 tiket terbaru dari 700")).toBeInTheDocument();
    });

    it("shows a date validation error next to its field and keeps the end date after the start", () => {
        render(<KitchenUnprinted {...props({ errors: { end_date: "The end date must be a date after or equal to start date." } })} />);

        const end = screen.getByLabelText("Tanggal Akhir");
        expect(end).toHaveAttribute("aria-invalid", "true");
        expect(screen.getByText("The end date must be a date after or equal to start date.")).toBeInTheDocument();
        expect(end).toHaveAttribute("min", "2026-11-01");
    });

    it("sends the chosen outlet and dates as the query", async () => {
        render(<KitchenUnprinted {...props()} />);

        await userEvent.selectOptions(screen.getByLabelText("Outlet"), "2");
        await userEvent.clear(screen.getByLabelText("Tanggal Mulai"));
        await userEvent.type(screen.getByLabelText("Tanggal Mulai"), "2026-11-10");
        await userEvent.click(screen.getByRole("button", { name: "Terapkan" }));

        expect(router.get).toHaveBeenCalledWith(
            "/reports.kitchen-unprinted.index",
            { outlet_id: "2", start_date: "2026-11-10", end_date: "2026-11-30" },
            expect.any(Object),
        );
    });

    it("reads the texts in English too", async () => {
        await i18n.changeLanguage("en");
        render(<KitchenUnprinted {...props()} />);

        expect(screen.getByRole("heading", { name: "Unprinted Kitchen Tickets" })).toBeInTheDocument();
        expect(screen.getByRole("columnheader", { name: "Print device" })).toBeInTheDocument();
        expect(screen.getByText("A ticket is listed when no print device of the outlet recorded it as printed after 30 seconds.")).toBeInTheDocument();
    });
});
