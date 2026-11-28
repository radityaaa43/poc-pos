import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { router } from "@inertiajs/react";
import i18n from "@/i18n";
import Waste from "./Waste";

vi.mock("@inertiajs/react", async (importActual) => ({
    ...(await importActual()),
    Head: () => null,
}));
vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));

const item = (over = {}) => ({
    product_id: 1,
    title: "Beras",
    is_composite: false,
    qty: 8,
    value: 1600,
    ...over,
});
const reason = (over = {}) => ({ reason: "expired", records: 1, value: 1600, ...over });
const props = (over = {}) => ({
    filters: { outlet_id: 1, start_date: "2026-11-01", end_date: "2026-11-30" },
    outlets: [{ id: 1, name: "Outlet A" }],
    items: [item()],
    reasons: [reason()],
    total_value: 1600,
    errors: {},
    ...over,
});

describe("Waste report page", () => {
    beforeEach(async () => {
        window.route = (name) => `/${name}`;
        vi.spyOn(router, "get").mockImplementation(() => {});
        await i18n.changeLanguage("id");
    });
    afterEach(() => vi.restoreAllMocks());

    it("lists the quantity and value per item and the count and value per reason, with the total", () => {
        render(<Waste {...props()} />);

        const row = screen.getByRole("row", { name: /Beras/ });
        expect(row).toHaveTextContent(/8/);
        expect(row).toHaveTextContent(/Rp\s?1\.600/);

        const reasonRow = screen.getByRole("row", { name: /Kedaluwarsa/ });
        expect(reasonRow).toHaveTextContent(/1/);
        expect(reasonRow).toHaveTextContent(/Rp\s?1\.600/);

        expect(within(screen.getByRole("region", { name: "Total biaya waste" })).getByText(/Rp\s?1\.600/)).toBeInTheDocument();
    });

    it("tags a recipe menu in the item row", () => {
        render(<Waste {...props({ items: [item({ title: "Kopi Susu", is_composite: true })] })} />);

        expect(screen.getByRole("row", { name: /Kopi Susu/ })).toHaveTextContent(/Menu/);
    });

    it("says the period is empty instead of showing an empty box", () => {
        render(<Waste {...props({ items: [], reasons: [], total_value: 0 })} />);

        expect(
            within(screen.getByRole("region", { name: "Waste per Item" })).getByText("Tidak ada waste pada periode ini"),
        ).toBeInTheDocument();
        expect(screen.queryByRole("row", { name: /Kedaluwarsa/ })).not.toBeInTheDocument();
        expect(within(screen.getByRole("region", { name: "Total biaya waste" })).getByText(/Rp\s?0/)).toBeInTheDocument();
    });

    it("shows a negative net value with its sign", () => {
        render(
            <Waste
                {...props({ items: [item({ value: -400 })], reasons: [reason({ records: -1, value: -400 })], total_value: -400 })}
            />,
        );

        expect(screen.getByRole("row", { name: /Beras/ })).toHaveTextContent(/-Rp\s?400/);
    });

    it("sends the chosen outlet and dates as the query", async () => {
        render(<Waste {...props({ outlets: [{ id: 1, name: "Outlet A" }, { id: 2, name: "Outlet B" }] })} />);

        await userEvent.selectOptions(screen.getByLabelText("Outlet"), "2");
        await userEvent.click(screen.getByRole("button", { name: "Terapkan" }));

        expect(router.get).toHaveBeenCalledWith(
            "/reports.waste.index",
            { outlet_id: "2", start_date: "2026-11-01", end_date: "2026-11-30" },
            expect.any(Object),
        );
    });

    it("shows a date validation error next to its field", () => {
        render(<Waste {...props({ errors: { end_date: "The end date must be a date after or equal to start date." } })} />);

        expect(screen.getByText("The end date must be a date after or equal to start date.")).toBeInTheDocument();
    });

    it("shows waste as a percent of sales cost with one decimal", () => {
        render(<Waste {...props({ waste_percent: 150 })} />);

        expect(
            within(screen.getByRole("region", { name: "Waste terhadap HPP penjualan" })).getByText("150,0%"),
        ).toBeInTheDocument();
    });

    it("shows a dash and a note when there are no sales in the period", () => {
        render(<Waste {...props({ waste_percent: null })} />);

        const card = screen.getByRole("region", { name: "Waste terhadap HPP penjualan" });
        expect(within(card).getByText("—")).toBeInTheDocument();
        expect(within(card).getByText("Belum ada penjualan pada periode ini")).toBeInTheDocument();
    });

    it("mounts the per-recorder and per-day table", () => {
        render(
            <Waste
                {...props({
                    recorder_days: [
                        { date: "2026-05-10", user_id: 7, user_name: "Budi", records: 1, value: 1600, near_limit: 1 },
                    ],
                    approval_limit: 100000,
                })}
            />,
        );

        expect(screen.getByRole("region", { name: "Waste per Pencatat dan Hari" })).toBeInTheDocument();
    });
});
