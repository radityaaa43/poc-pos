import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { act, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { router } from "@inertiajs/react";
import i18n from "@/i18n";
import CustomerCreditPanel from "./CustomerCreditPanel";

let online = true;
vi.mock("@/Context/OnlineStatusContext", () => ({ useOnlineStatus: () => online }));
vi.mock("@inertiajs/react", () => ({ router: { on: vi.fn(() => () => {}) } }));

const budi = { id: 5, may_buy_on_credit: true };
const sari = { id: 6, may_buy_on_credit: true };
const summary = (overrides = {}) => ({
    may_buy_on_credit: true,
    credit_limit: 500000,
    used: 300000,
    remaining: 200000,
    has_overdue: false,
    ...overrides,
});
const ok = (body) => ({ ok: true, json: () => Promise.resolve(body) });
const panel = () => screen.getByRole("status");
// The amount printed under a term.
const figure = (term) => screen.getByText(term).nextElementSibling;

describe("CustomerCreditPanel", () => {
    beforeAll(() => i18n.changeLanguage("id"));
    beforeEach(() => {
        online = true;
        globalThis.route = (name, id) => `/${name}/${id}`;
        vi.stubGlobal("fetch", vi.fn().mockResolvedValue(ok(summary())));
    });
    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it.each([
        ["no customer", null, true],
        ["a customer who may not buy on credit", { id: 5, may_buy_on_credit: false }, true],
        ["offline", budi, false],
    ])("renders nothing and asks nothing for %s", (_, customer, isOnline) => {
        online = isOnline;
        const { container } = render(<CustomerCreditPanel customer={customer} />);

        expect(container).toBeEmptyDOMElement();
        expect(fetch).not.toHaveBeenCalled();
    });

    it("is busy while loading, then shows Batas, Terpakai and Sisa", async () => {
        render(<CustomerCreditPanel customer={budi} />);

        expect(panel()).toHaveAttribute("aria-busy", "true");
        expect(await screen.findByText("Sisa")).toBeInTheDocument();
        expect(panel()).toHaveAttribute("aria-busy", "false");
        expect(figure("Batas")).toHaveTextContent("Rp 500.000");
        expect(figure("Terpakai")).toHaveTextContent("Rp 300.000");
        expect(figure("Sisa")).toHaveTextContent("Rp 200.000");
        expect(screen.queryByText("Ada piutang lewat jatuh tempo")).not.toBeInTheDocument();
        expect(fetch).toHaveBeenCalledWith("/customers.credit-summary/5", expect.anything());
    });

    it("shows Tanpa batas with the usage, and warns about an overdue receivable", async () => {
        fetch.mockResolvedValue(ok(summary({ credit_limit: null, remaining: null, used: 0, has_overdue: true })));
        render(<CustomerCreditPanel customer={budi} />);

        expect(await screen.findByText("Tanpa batas")).toBeInTheDocument();
        expect(figure("Terpakai")).toHaveTextContent("Rp 0");
        expect(screen.queryByText("Sisa")).not.toBeInTheDocument();
        expect(screen.getByText("Ada piutang lewat jatuh tempo")).toBeInTheDocument();
    });

    it("hides when the permission was revoked after the page loaded", async () => {
        fetch.mockResolvedValue(ok(summary({ may_buy_on_credit: false })));
        const { container } = render(<CustomerCreditPanel customer={budi} />);

        await vi.waitFor(() => expect(container).toBeEmptyDOMElement());
    });

    it("says it could not load, and tries again", async () => {
        vi.spyOn(console, "error").mockImplementation(() => {});
        fetch.mockResolvedValueOnce({ ok: false, status: 500 });
        render(<CustomerCreditPanel customer={budi} />);

        expect(await screen.findByText("Gagal memuat batas piutang")).toBeInTheDocument();
        await userEvent.click(screen.getByRole("button", { name: "Coba lagi" }));

        expect(await screen.findByText("Sisa")).toBeInTheDocument();
        expect(fetch).toHaveBeenCalledTimes(2);
    });

    it("drops a slow answer for the customer picked before", async () => {
        let answerFirst;
        fetch.mockImplementationOnce(() => new Promise((resolve) => (answerFirst = resolve)));
        fetch.mockResolvedValueOnce(ok(summary({ used: 100000 })));
        const { rerender } = render(<CustomerCreditPanel customer={budi} />);

        rerender(<CustomerCreditPanel customer={sari} />);
        await screen.findByText("Sisa");
        await act(async () => answerFirst(ok(summary({ used: 999000 }))));

        expect(fetch.mock.calls[0][1].signal.aborted).toBe(true);
        expect(figure("Terpakai")).toHaveTextContent("Rp 100.000");
    });

    it("speaks English and still formats rupiah", async () => {
        await i18n.changeLanguage("en");
        render(<CustomerCreditPanel customer={budi} />);

        expect(await screen.findByText("Remaining")).toBeInTheDocument();
        expect(figure("Limit")).toHaveTextContent("Rp 500.000");
        await act(() => i18n.changeLanguage("id"));
    });

    it("reads the figures again after a credit refusal, not after other errors", async () => {
        render(<CustomerCreditPanel customer={budi} />);
        await screen.findByText("Sisa");
        const [event, listener] = router.on.mock.lastCall;
        expect(event).toBe("error");

        act(() => listener({ detail: { errors: { note: "Catatan wajib" } } }));
        expect(fetch).toHaveBeenCalledTimes(1);

        fetch.mockResolvedValue(ok(summary({ used: 450000, remaining: 50000 })));
        act(() => listener({ detail: { errors: { credit: "Melebihi batas piutang" } } }));
        expect(await screen.findByText(/Rp\s*50\.000/)).toBeInTheDocument();
        expect(fetch).toHaveBeenCalledTimes(2);
    });
});
