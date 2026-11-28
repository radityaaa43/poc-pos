import { beforeEach, describe, expect, it } from "vitest";
import { render, screen, within } from "@testing-library/react";
import i18n from "@/i18n";
import OpnameVarianceByMonth from "./OpnameVarianceByMonth";

const month = (over = {}) => ({
    month: "2026-03",
    opnames: 1,
    avg_value: 3000,
    avg_items_off: 1,
    phase: "before",
    estimated: false,
    ...over,
});

const variance = (over = {}) => ({
    waste_started_month: "2026-05",
    months: [
        month(),
        month({ month: "2026-04", opnames: 0, avg_value: null, avg_items_off: null }),
        month({ month: "2026-05", avg_value: 1500, avg_items_off: 1.5, phase: "after" }),
    ],
    ...over,
});

const section = () => screen.getByRole("region", { name: "Selisih opname per bulan" });

describe("Opname variance by month", () => {
    beforeEach(async () => {
        await i18n.changeLanguage("id");
    });

    it("lists the value and items off per month and marks the waste start month", () => {
        render(<OpnameVarianceByMonth variance={variance()} />);

        const before = within(section()).getByRole("row", { name: /Maret 2026/ });
        expect(before).toHaveTextContent("Sebelum");
        expect(before).toHaveTextContent(/Rp\s?3\.000/);
        expect(before).toHaveTextContent("1");

        const start = within(section()).getByRole("row", { name: /Mei 2026/ });
        expect(start).toHaveTextContent("Sesudah");
        expect(start).toHaveTextContent("Pencatatan waste dimulai");
        expect(start).toHaveTextContent(/Rp\s?1\.500/);
        expect(start).toHaveTextContent("1.5");

        const empty = within(section()).getByRole("row", { name: /April 2026/ });
        expect(empty).toHaveTextContent("Sebelum");
        expect(within(empty).getAllByRole("cell")[2]).toHaveTextContent("0");
        expect(within(empty).getAllByRole("cell")[3]).toHaveTextContent("-");
        expect(within(empty).getAllByRole("cell")[4]).toHaveTextContent("-");
    });

    it("says when no opname is finalized and when no waste exists yet", () => {
        render(
            <OpnameVarianceByMonth
                variance={variance({
                    waste_started_month: null,
                    months: [month({ opnames: 0, avg_value: null, avg_items_off: null })],
                })}
            />,
        );

        expect(screen.getByText("Belum ada stock opname final dalam 12 bulan terakhir.")).toBeInTheDocument();
        expect(screen.getByText("Belum ada waste tercatat: semua bulan dihitung sebagai sebelum.")).toBeInTheDocument();
    });

    it("names the start month when it is older than the window", () => {
        render(<OpnameVarianceByMonth variance={variance({ waste_started_month: "2024-01" })} />);

        expect(screen.getByText("Pencatatan waste dimulai pada Januari 2024")).toBeInTheDocument();
    });

    it("labels a month valued with today's cost as estimated", () => {
        render(
            <OpnameVarianceByMonth variance={variance({ months: [month({ estimated: true })] })} />,
        );

        expect(within(section()).getByRole("row", { name: /Maret 2026/ })).toHaveTextContent("Estimasi");
        expect(screen.getByText(/memakai HPP rata-rata hari ini/)).toBeInTheDocument();
    });

    it("switches the phase text, headings and estimate label to English", async () => {
        await i18n.changeLanguage("en");
        const data = variance();
        data.months[2] = { ...data.months[2], estimated: true };
        render(<OpnameVarianceByMonth variance={data} />);

        const en = screen.getByRole("region", { name: "Opname variance by month" });
        expect(within(en).getByRole("row", { name: /March 2026/ })).toHaveTextContent("Before");
        expect(within(en).getByRole("row", { name: /May 2026/ })).toHaveTextContent("After");
        expect(within(en).getByRole("row", { name: /May 2026/ })).toHaveTextContent("Waste recording started");
        expect(within(en).getByRole("row", { name: /May 2026/ })).toHaveTextContent("Estimated");
    });
});
