import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import CustomerHistoryPanel, { CustomerHistoryButton } from "./CustomerHistoryPanel";

const okResponse = (payload) => ({
    ok: true,
    json: () => Promise.resolve(payload),
});

const fullPayload = {
    success: true,
    stats: {
        total_transactions: 3,
        total_spent: 150000,
        last_visit: "2026-11-20",
    },
    loyalty: { is_member: true, tier: "gold", points: 40 },
    eligible_vouchers: [
        { id: 1, code: "V1", name: "Hemat", minimum_order: 50000 },
    ],
    frequent_products: [{ id: 7, title: "Kopi Susu", total_qty: 4 }],
    recent_transactions: [
        { id: 3, invoice: "TRX-1", date: "2026-11-20", total: 50000 },
    ],
    loyalty_history: [
        {
            id: 1,
            reference: "TRX-1",
            type: "earn",
            points_delta: 50,
            created_at: "2026-11-20",
        },
        {
            id: 2,
            reference: null,
            type: "redeem",
            points_delta: -20,
            created_at: "2026-11-21",
        },
    ],
};

describe("CustomerHistoryPanel", () => {
    let fetchMock;

    beforeEach(() => {
        fetchMock = vi.fn();
        vi.stubGlobal("fetch", fetchMock);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it("fetches the customer's history", async () => {
        const routeSpy = vi.spyOn(globalThis, "route");
        fetchMock.mockResolvedValue(okResponse(fullPayload));

        render(<CustomerHistoryPanel customerId={5} customerName="Budi" />);

        expect(await screen.findByText("Budi")).toBeInTheDocument();
        expect(routeSpy).toHaveBeenCalledWith("customers.history", 5);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock).toHaveBeenCalledWith("/customers.history", {
            headers: {
                Accept: "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
        });
    });

    it("renders stats and sections", async () => {
        fetchMock.mockResolvedValue(okResponse(fullPayload));

        render(<CustomerHistoryPanel customerId={5} customerName="Budi" />);

        expect(await screen.findByText("Rp 150.000")).toBeInTheDocument();
        expect(screen.getByText("gold")).toBeInTheDocument();
        expect(screen.getByText("40")).toBeInTheDocument();
        expect(screen.getByText("V1 - Hemat")).toBeInTheDocument();
        expect(screen.getByText("Min belanja Rp 50.000")).toBeInTheDocument();
        expect(screen.getByText("Kopi Susu")).toBeInTheDocument();
        expect(screen.getByText("×4")).toBeInTheDocument();
        // TRX-1 is both the recent transaction invoice and a loyalty reference.
        expect(screen.getAllByText("TRX-1")).toHaveLength(2);
        expect(screen.getByText("+50 poin")).toBeInTheDocument();
        expect(screen.getByText("redeem")).toBeInTheDocument();
        expect(screen.getByText("-20 poin")).toBeInTheDocument();
        expect(screen.queryByText("Belum ada transaksi")).not.toBeInTheDocument();
    });

    it("shows the empty state when the customer has no transactions", async () => {
        fetchMock.mockResolvedValue(
            okResponse({
                success: true,
                stats: { total_transactions: 0, total_spent: 0, last_visit: null },
                loyalty: { is_member: false },
                eligible_vouchers: [],
                frequent_products: [],
                recent_transactions: [],
                loyalty_history: [],
            })
        );

        render(<CustomerHistoryPanel customerId={5} customerName="Budi" />);

        expect(await screen.findByText("Belum ada transaksi")).toBeInTheDocument();
    });

    describe("errors", () => {
        beforeEach(() => {
            vi.spyOn(console, "error").mockImplementation(() => {});
        });

        it("shows a generic error when the response is not ok", async () => {
            fetchMock.mockResolvedValue({ ok: false, status: 500 });

            render(<CustomerHistoryPanel customerId={5} customerName="Budi" />);

            expect(
                await screen.findByText("Gagal memuat data pelanggan")
            ).toBeInTheDocument();
        });

        it("shows the server message when success is false", async () => {
            fetchMock.mockResolvedValue(
                okResponse({ success: false, message: "Tidak ditemukan" })
            );

            render(<CustomerHistoryPanel customerId={5} customerName="Budi" />);

            expect(await screen.findByText("Tidak ditemukan")).toBeInTheDocument();
        });

        it("shows a generic error when the request is rejected", async () => {
            fetchMock.mockRejectedValue(new Error("network down"));

            render(<CustomerHistoryPanel customerId={5} customerName="Budi" />);

            expect(
                await screen.findByText("Gagal memuat data pelanggan")
            ).toBeInTheDocument();
        });
    });
});

describe("CustomerHistoryButton", () => {
    beforeEach(() => {
        vi.stubGlobal(
            "fetch",
            vi.fn().mockResolvedValue(okResponse(fullPayload))
        );
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it("renders nothing without a customerId", () => {
        const { container } = render(<CustomerHistoryButton customerName="Budi" />);

        expect(container).toBeEmptyDOMElement();
    });

    it("opens the panel on click and closes it with the close button", async () => {
        const user = userEvent.setup();
        render(<CustomerHistoryButton customerId={5} customerName="Budi" />);

        expect(screen.queryByText("Riwayat Pelanggan")).not.toBeInTheDocument();

        await user.click(screen.getByTitle("Lihat riwayat"));

        expect(await screen.findByText("Riwayat Pelanggan")).toBeInTheDocument();

        // The close button has no title or text; it is the only button without a title (#45).
        const closeButton = screen
            .getAllByRole("button")
            .find((button) => !button.hasAttribute("title"));
        await user.click(closeButton);

        expect(screen.queryByText("Riwayat Pelanggan")).not.toBeInTheDocument();
    });
});
