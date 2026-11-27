import { beforeAll, describe, expect, it } from "vitest";
import { render, screen, within } from "@testing-library/react";
import i18n from "@/i18n";
import ShiftCloseBlockedList from "./ShiftCloseBlockedList";

beforeAll(() => i18n.changeLanguage("id"));

describe("ShiftCloseBlockedList", () => {
    it("renders nothing when nothing holds the close", () => {
        const { container } = render(<ShiftCloseBlockedList blockers={[]} />);
        expect(container).toBeEmptyDOMElement();
    });

    it("lists order, label, amount and a chip per type", () => {
        const food = { type: "item", order: "TRX-1", label: "Nasi Goreng × 2", amount: 30000 };
        const unpaid = { type: "bill", order: "TRX-2", label: null, amount: 45000 };
        render(<ShiftCloseBlockedList blockers={[food, unpaid]} />);

        expect(screen.getByRole("heading", { name: "Belum selesai" })).toBeInTheDocument();
        const [item, bill] = screen.getAllByRole("listitem");
        expect(item).toHaveTextContent("TRX-1");
        expect(item).toHaveTextContent("Nasi Goreng × 2");
        expect(item).toHaveTextContent(/Rp\s30\.000/);
        expect(within(item).getByText("Belum diserahkan")).toBeInTheDocument();
        expect(bill).toHaveTextContent("TRX-2");
        expect(bill).toHaveTextContent(/Rp\s45\.000/);
        expect(within(bill).getByText("Belum lunas")).toBeInTheDocument();
    });

    it.each([
        ["id", /^Split pembayaran belum lunas — sisa Rp\s15\.000$/],
        ["en", /^Split payment not fully paid — Rp\s15\.000 still owed$/],
    ])("says in %s what a partly paid split still owes, once", async (language, wording) => {
        await i18n.changeLanguage(language);
        render(<ShiftCloseBlockedList blockers={[{ type: "split", order: "TRX-3", label: "Budi", amount: 15000 }]} />);

        const row = screen.getByRole("listitem");
        expect(row).toHaveTextContent("TRX-3");
        expect(row).toHaveTextContent("Budi");
        expect(within(row).getByText(wording)).toBeInTheDocument();
        expect(row.textContent.match(/15\.000/g)).toHaveLength(1);
        await i18n.changeLanguage("id");
    });
});
