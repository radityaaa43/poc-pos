import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import i18n from "@/i18n";
import WasteRecorderDays from "./WasteRecorderDays";

const formatCurrency = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value);

const row = (over = {}) => ({
    date: "2026-05-10",
    user_id: 7,
    user_name: "Budi",
    records: 3,
    value: 240000,
    near_limit: 2,
    ...over,
});

describe("Waste per recorder and day", () => {
    beforeEach(async () => {
        await i18n.changeLanguage("id");
    });
    afterEach(() => vi.restoreAllMocks());

    it("shows one row per recorder and day with its records, value and near-limit count", () => {
        render(<WasteRecorderDays rows={[row()]} approvalLimit={100000} formatCurrency={formatCurrency} />);

        const line = screen.getByRole("row", { name: /Budi/ });
        expect(line).toHaveTextContent("2026-05-10");
        expect(line).toHaveTextContent("3");
        expect(line).toHaveTextContent(/Rp\s?240\.000/);
        expect(line).toHaveTextContent("2");
    });

    it("states in the caption which approval limit today's near-limit uses", () => {
        render(<WasteRecorderDays rows={[row()]} approvalLimit={100000} formatCurrency={formatCurrency} />);

        expect(screen.getByText(/Batas Rp\s?100\.000 per catatan; mendekati = dalam 10%/)).toBeInTheDocument();
    });

    it("marks a near-limit row with text, not colour only", () => {
        render(<WasteRecorderDays rows={[row()]} approvalLimit={100000} formatCurrency={formatCurrency} />);

        const line = screen.getByRole("row", { name: /Budi/ });
        expect(within(line).getByText("Mendekati batas")).toBeInTheDocument();
    });

    it("does not mark a row with no near-limit record", () => {
        render(<WasteRecorderDays rows={[row({ near_limit: 0 })]} approvalLimit={100000} formatCurrency={formatCurrency} />);

        const line = screen.getByRole("row", { name: /Budi/ });
        expect(within(line).queryByText("Mendekati batas")).not.toBeInTheDocument();
    });

    it("names a deleted recorder instead of leaving a blank cell", () => {
        render(<WasteRecorderDays rows={[row({ user_id: null, user_name: null })]} approvalLimit={100000} formatCurrency={formatCurrency} />);

        expect(screen.getByRole("row", { name: /Pengguna dihapus/ })).toBeInTheDocument();
    });

    it("says the period is empty instead of showing an empty table", () => {
        render(<WasteRecorderDays rows={[]} approvalLimit={100000} formatCurrency={formatCurrency} />);

        expect(screen.getByText("Tidak ada waste pada periode ini")).toBeInTheDocument();
    });

    it("falls back to the section title as caption when there is no single outlet limit", () => {
        render(<WasteRecorderDays rows={[row()]} approvalLimit={null} formatCurrency={formatCurrency} />);

        expect(screen.getByRole("table", { name: "Waste per Pencatat dan Hari" })).toBeInTheDocument();
    });
});
