import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import i18n from "@/i18n";

const post = vi.fn();
vi.mock("@inertiajs/react", () => ({ router: { post: (...args) => post(...args) } }));
vi.mock("react-hot-toast", () => ({ default: { error: vi.fn(), success: vi.fn() } }));

import ShiftCloseBlockedList from "./ShiftCloseBlockedList";

beforeAll(() => i18n.changeLanguage("id"));
beforeEach(() => {
    post.mockReset();
    vi.restoreAllMocks();
});

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

describe("a discount waiting for approval (#542)", () => {
    const row = { type: "discount", id: 42, order: "TRX-9", label: "Budi", amount: 5000 };
    const approve = () => screen.getByRole("button", { name: "Setujui TRX-9" });
    const deny = () => screen.getByRole("button", { name: "Tolak TRX-9" });

    it.each([
        ["id", /^Diskon menunggu persetujuan — Rp\s5\.000$/],
        ["en", /^Discount awaiting approval — Rp\s5\.000$/],
    ])("says in %s what the discount is, once, and decides nothing without the permission", async (language, wording) => {
        await i18n.changeLanguage(language);
        render(<ShiftCloseBlockedList blockers={[row]} />);

        const item = screen.getByRole("listitem");
        expect(item).toHaveTextContent("TRX-9");
        expect(item).toHaveTextContent("Budi");
        expect(within(item).getByText(wording)).toBeInTheDocument();
        expect(item.textContent.match(/5\.000/g)).toHaveLength(1);
        expect(within(item).queryByRole("button")).not.toBeInTheDocument();
        await i18n.changeLanguage("id");
    });

    it("approves from the list after a confirm, disabling both buttons while the request runs", async () => {
        globalThis.route = (name, id) => `/${name}/${id}`;
        vi.spyOn(window, "confirm").mockReturnValue(true);
        render(<ShiftCloseBlockedList blockers={[row]} canDecide />);

        await userEvent.setup().click(approve());

        expect(window.confirm).toHaveBeenCalledWith("Setujui TRX-9?");
        expect(post).toHaveBeenCalledWith("/discount-approvals.approve/42", {}, expect.objectContaining({ preserveScroll: true }));
        expect(approve()).toBeDisabled();
        expect(deny()).toBeDisabled();
    });

    it("denies after a confirm, and posts nothing when the confirm is cancelled", async () => {
        globalThis.route = (name, id) => `/${name}/${id}`;
        const confirm = vi.spyOn(window, "confirm").mockReturnValue(false);
        render(<ShiftCloseBlockedList blockers={[row]} canDecide />);

        await userEvent.setup().click(deny());
        expect(post).not.toHaveBeenCalled();

        confirm.mockReturnValue(true);
        await userEvent.setup().click(deny());
        expect(post).toHaveBeenCalledWith("/discount-approvals.deny/42", {}, expect.objectContaining({ preserveScroll: true }));
    });
});
