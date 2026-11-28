import { beforeAll, describe, expect, it } from "vitest";
import { act, render, screen } from "@testing-library/react";
import i18n from "@/i18n";
import CreditCells from "./CreditCells";

const renderCells = (credit) => render(<CreditCells credit={credit} />);
const cells = (container) => [...container.children].map((cell) => cell.textContent.replace(/\s+/g, " "));

describe("CreditCells", () => {
    beforeAll(() => i18n.changeLanguage("id"));

    it("shows the limit and the percentage, labelled for screen readers", () => {
        const { container } = renderCells({ limit: 2000000, used: 1300000, percent: 65, state: "ok" });

        expect(cells(container)).toEqual(["Rp 2.000.000", "65%"]);
        expect(screen.getByRole("img").getAttribute("aria-label").replace(/\s/g, " ")).toBe("Terpakai 65% dari batas Rp 2.000.000");
    });

    it("shows Tanpa batas and no percentage without a limit", () => {
        const { container } = renderCells({ limit: null, used: 300000, percent: null, state: "none" });

        expect(cells(container)).toEqual(["Tanpa batas", "—"]);
    });

    it("shows dashes when there is nothing to say", () => {
        const { container } = renderCells(null);

        expect(cells(container)).toEqual(["—", "—"]);
    });

    it.each([
        ["near", 80, "Mendekati batas (≥ 80%)"],
        ["over", 120, "Melewati batas"],
    ])("labels the %s state in words", (state, percent, label) => {
        renderCells({ limit: 100000, used: percent * 1000, percent, state });

        expect(screen.getByText(label)).toBeInTheDocument();
        expect(screen.getByRole("img")).toHaveTextContent(`${percent}%`);
    });

    it("shows a dash for a limit of 0, still over it", () => {
        const { container } = renderCells({ limit: 0, used: 1000, percent: null, state: "over" });

        expect(cells(container)).toEqual(["Rp 0", "—Melewati batas"]);
    });

    it("speaks English and still formats rupiah", async () => {
        await act(() => i18n.changeLanguage("en"));
        const { container } = renderCells({ limit: 100000, used: 90000, percent: 90, state: "near" });

        expect(cells(container)).toEqual(["Rp 100.000", "90%Near limit (≥ 80%)"]);
        await act(() => i18n.changeLanguage("id"));
    });
});
