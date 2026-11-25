import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, within, act } from "@testing-library/react";
import { usePoll } from "@inertiajs/react";
import i18n from "@/i18n";
import Index from "./Index";

vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    usePoll: vi.fn(),
}));
vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));

const NOW = new Date("2026-11-25T10:00:00Z");
const minutesAgo = (m) => new Date(NOW.getTime() - m * 60000).toISOString();

const orders = [
    {
        id: 7,
        number: "Meja 4",
        type: "dine_in",
        unpaid: true,
        since: minutesAgo(12),
        items: [
            { id: 1, title: "Nasi Goreng", qty: 2, note: "tanpa pedas", status: "accepted" },
            { id: 2, title: "Es Teh", qty: 1, note: null, status: "preparing" },
        ],
    },
    {
        id: 9,
        number: "INV-0009",
        type: "takeaway",
        unpaid: false,
        since: minutesAgo(3),
        items: [{ id: 3, title: "Mie Ayam", qty: 1, note: null, status: "accepted" }],
    },
];

const outlet = { id: 1, name: "Outlet Pusat" };
const pollOptions = () => usePoll.mock.calls[0][1];

beforeEach(async () => {
    vi.useFakeTimers({ toFake: ["Date", "setInterval", "clearInterval"] });
    vi.setSystemTime(NOW);
    vi.clearAllMocks();
    await i18n.changeLanguage("id");
});

afterEach(() => vi.useRealTimers());

describe("Kitchen/Index", () => {
    it("lists the orders in prop order with number, type, items and note", () => {
        render(<Index orders={orders} outlet={outlet} />);

        const cards = screen.getAllByRole("article");
        expect(cards).toHaveLength(2);
        expect(within(cards[0]).getByText("Meja 4")).toBeInTheDocument();
        expect(within(cards[0]).getByText("Dine-in")).toBeInTheDocument();
        expect(within(cards[0]).getByText("12 menit")).toBeInTheDocument();
        expect(within(cards[0]).getByText(/Nasi Goreng/)).toBeInTheDocument();
        expect(within(cards[0]).getByText("tanpa pedas")).toBeInTheDocument();
        expect(within(cards[0]).getByText(/Es Teh/)).toBeInTheDocument();
        expect(within(cards[1]).getByText("INV-0009")).toBeInTheDocument();
        expect(within(cards[1]).getByText("Bawa pulang")).toBeInTheDocument();
    });

    it("marks only the unpaid order", () => {
        render(<Index orders={orders} outlet={outlet} />);

        const [first, second] = screen.getAllByRole("article");
        expect(within(first).getByText("Belum lunas")).toBeInTheDocument();
        expect(within(second).queryByText("Belum lunas")).not.toBeInTheDocument();
    });

    it("shows the empty state", () => {
        render(<Index orders={[]} outlet={outlet} />);

        expect(screen.getByText("Belum ada pesanan")).toBeInTheDocument();
        expect(screen.queryByRole("article")).not.toBeInTheDocument();
    });

    it("asks to pick an outlet when there is none", () => {
        render(<Index orders={[]} outlet={null} />);

        expect(screen.getByText("Pilih outlet terlebih dahulu")).toBeInTheDocument();
        expect(screen.queryByText("Belum ada pesanan")).not.toBeInTheDocument();
    });

    it("uses the English wording", async () => {
        await i18n.changeLanguage("en");
        render(<Index orders={[]} outlet={outlet} />);

        expect(screen.getByRole("heading", { name: "Kitchen display" })).toBeInTheDocument();
        expect(screen.getByText("No orders yet")).toBeInTheDocument();
    });

    it("polls the orders prop every 5 seconds", () => {
        render(<Index orders={orders} outlet={outlet} />);

        expect(usePoll).toHaveBeenCalledWith(5000, expect.objectContaining({ only: ["orders"] }));
    });

    it("shows the connection notice on the offline event and hides it on the online event", () => {
        render(<Index orders={orders} outlet={outlet} />);
        expect(screen.queryByRole("alert")).not.toBeInTheDocument();

        act(() => window.dispatchEvent(new Event("offline")));
        expect(screen.getByRole("alert")).toHaveTextContent("Koneksi terputus — mencoba lagi…");

        act(() => window.dispatchEvent(new Event("online")));
        expect(screen.queryByRole("alert")).not.toBeInTheDocument();
    });

    it("shows the connection notice after a 15 s stale poll and hides it after a success", () => {
        render(<Index orders={orders} outlet={outlet} />);

        act(() => vi.advanceTimersByTime(14000));
        expect(screen.queryByRole("alert")).not.toBeInTheDocument();

        act(() => vi.advanceTimersByTime(2000));
        expect(screen.getByRole("alert")).toBeInTheDocument();

        act(() => pollOptions().onSuccess());
        expect(screen.queryByRole("alert")).not.toBeInTheDocument();
    });
});
