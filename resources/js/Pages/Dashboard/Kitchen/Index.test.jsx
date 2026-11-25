import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, within, act } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import toast from "react-hot-toast";
import { usePoll, router } from "@inertiajs/react";
import i18n from "@/i18n";
import { useKitchenPrintStation } from "@/Hooks/useKitchenPrintStation";
import Index from "./Index";

let permissions = { "dine-orders-prepare": true };

vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    usePoll: vi.fn(),
    router: { post: vi.fn() },
    usePage: () => ({ props: { auth: { permissions } } }),
}));
vi.mock("react-hot-toast", () => ({ default: { success: vi.fn(), error: vi.fn() } }));
vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));
// Printing has its own tests; this file only checks the display and the panel toggle.
vi.mock("@/Hooks/useKitchenPrintStation", () => ({ useKitchenPrintStation: vi.fn(() => ({ station: null })) }));
vi.mock("./PrintStationPanel", () => ({ default: () => <div data-testid="print-panel" /> }));

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
    permissions = { "dine-orders-prepare": true };
    vi.spyOn(navigator, "onLine", "get").mockReturnValue(true);
    await i18n.changeLanguage("id");
});

afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
});

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

    it("runs the print station hook for the page and toggles its panel with a button", async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(<Index orders={orders} outlet={outlet} />);
        expect(useKitchenPrintStation).toHaveBeenCalledWith(outlet);
        expect(screen.queryByTestId("print-panel")).not.toBeInTheDocument();

        const toggle = screen.getByRole("button", { name: "Pengaturan printer dapur" });
        expect(toggle).toHaveAttribute("aria-expanded", "false");

        await user.click(toggle);
        expect(screen.getByTestId("print-panel")).toBeInTheDocument();
        expect(toggle).toHaveAttribute("aria-expanded", "true");

        await user.click(toggle);
        expect(screen.queryByTestId("print-panel")).not.toBeInTheDocument();
    });

    it("keeps the panel open when the poll brings new orders", async () => {
        const user = userEvent.setup();
        const { rerender } = render(<Index orders={orders} outlet={outlet} />);
        await user.click(screen.getByRole("button", { name: "Pengaturan printer dapur" }));
        expect(screen.getByTestId("print-panel")).toBeInTheDocument();

        rerender(<Index orders={[{ ...orders[0], id: 99 }]} outlet={outlet} />);

        expect(screen.getByTestId("print-panel")).toBeInTheDocument();
    });

    it("has no printer settings button without an outlet", () => {
        render(<Index orders={[]} outlet={null} />);

        expect(screen.queryByRole("button", { name: "Pengaturan printer dapur" })).not.toBeInTheDocument();
    });
});

describe("Kitchen/Index steps", () => {
    const stepOrders = [
        ...orders,
        {
            id: 11,
            number: "Meja 9",
            type: "dine_in",
            unpaid: false,
            since: minutesAgo(1),
            items: [
                { id: 4, title: "Kopi", qty: 1, note: null, status: "ready" },
                { id: 5, title: "Roti", qty: 1, note: null, status: "cancelled" },
            ],
        },
    ];
    const cards = () => screen.getAllByRole("article");
    const lastOptions = () => router.post.mock.calls.at(-1)[2];
    const setup = () => userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

    it("offers Mulai buat on accepted items and Siap on preparing items only", () => {
        render(<Index orders={stepOrders} outlet={outlet} />);

        const [first, , ready] = cards();
        const rows = within(first).getAllByRole("listitem");
        expect(within(rows[0]).getByRole("button", { name: "Mulai buat" })).toBeInTheDocument();
        expect(within(rows[0]).queryByRole("button", { name: "Siap" })).not.toBeInTheDocument();
        expect(within(rows[1]).getByRole("button", { name: "Siap" })).toBeInTheDocument();
        expect(within(rows[1]).queryByRole("button", { name: "Mulai buat" })).not.toBeInTheDocument();
        expect(within(ready).queryByRole("button", { name: /Mulai buat|^Siap$/ })).not.toBeInTheDocument();
    });

    it("shows Semua siap on an order while any item is not ready", () => {
        render(<Index orders={stepOrders} outlet={outlet} />);

        const [first, second, done] = cards();
        expect(within(first).getByRole("button", { name: "Semua siap" })).toBeInTheDocument();
        expect(within(second).getByRole("button", { name: "Semua siap" })).toBeInTheDocument();
        expect(within(done).queryByRole("button", { name: "Semua siap" })).not.toBeInTheDocument();
    });

    // The printer settings button is always there; only the step buttons depend on the item and the permission.
    const stepButtons = () => screen.queryAllByRole("button").filter((b) => !b.hasAttribute("aria-controls"));

    it("shows a cancelled item struck through with the Dibatalkan badge", () => {
        render(<Index orders={stepOrders} outlet={outlet} />);

        const row = within(cards()[2]).getAllByRole("listitem")[1];
        expect(within(row).getByText("Dibatalkan")).toBeInTheDocument();
        expect(within(row).getByText(/Roti/)).toHaveClass("line-through");
        expect(within(cards()[2]).getAllByRole("listitem")[0].querySelector(".line-through")).toBeNull();
    });

    it("shows the English Cancelled badge", async () => {
        await i18n.changeLanguage("en");
        render(<Index orders={stepOrders} outlet={outlet} />);

        expect(within(cards()[2]).getByText("Cancelled")).toBeInTheDocument();
    });

    it("renders an order whose every item is cancelled with no step buttons", () => {
        const onlyCancelled = [{ ...stepOrders[2], items: [stepOrders[2].items[1]] }];
        render(<Index orders={onlyCancelled} outlet={outlet} />);

        expect(screen.getByText("Meja 9")).toBeInTheDocument();
        expect(screen.getByText("Dibatalkan")).toBeInTheDocument();
        expect(stepButtons()).toHaveLength(0);
    });

    it("shows no buttons without dine-orders-prepare", () => {
        permissions = {};
        render(<Index orders={stepOrders} outlet={outlet} />);

        expect(stepButtons()).toHaveLength(0);
    });

    it("posts each step to its route and keeps the scroll position", async () => {
        const user = setup();
        render(<Index orders={stepOrders} outlet={outlet} />);
        const [first] = cards();

        await user.click(within(first).getByRole("button", { name: "Mulai buat" }));
        expect(router.post).toHaveBeenLastCalledWith("/kitchen.items.start", {}, expect.objectContaining({ preserveScroll: true }));
        act(() => lastOptions().onFinish());

        await user.click(within(first).getByRole("button", { name: "Siap" }));
        expect(router.post).toHaveBeenLastCalledWith("/kitchen.items.ready", {}, expect.objectContaining({ preserveScroll: true }));
        act(() => lastOptions().onFinish());

        await user.click(within(first).getByRole("button", { name: "Semua siap" }));
        expect(router.post).toHaveBeenLastCalledWith("/kitchen.orders.all-ready", {}, expect.objectContaining({ preserveScroll: true }));
    });

    it("disables the tapped button while its request runs and frees it afterwards", async () => {
        const user = setup();
        render(<Index orders={stepOrders} outlet={outlet} />);
        const [first] = cards();
        const button = within(first).getByRole("button", { name: "Mulai buat" });

        await user.click(button);
        expect(button).toBeDisabled();
        await user.click(button);
        expect(router.post).toHaveBeenCalledTimes(1);

        act(() => lastOptions().onFinish());
        expect(button).toBeEnabled();
    });

    it("shows the server message in an error toast", async () => {
        const user = setup();
        render(<Index orders={stepOrders} outlet={outlet} />);

        await user.click(within(cards()[0]).getByRole("button", { name: "Mulai buat" }));
        act(() => lastOptions().onError({ item: "Sudah diproses oleh Sari" }));

        expect(toast.error).toHaveBeenCalledWith("Sudah diproses oleh Sari");
    });

    it("shows Sudah dibatalkan when a tap hits an item the return just cancelled", async () => {
        const user = setup();
        render(<Index orders={stepOrders} outlet={outlet} />);

        await user.click(within(cards()[0]).getByRole("button", { name: "Mulai buat" }));
        act(() => lastOptions().onError({ item: "Sudah dibatalkan" }));

        expect(toast.error).toHaveBeenCalledWith("Sudah dibatalkan");
    });

    it("shows the save error on a network failure", async () => {
        const user = setup();
        render(<Index orders={stepOrders} outlet={outlet} />);

        await user.click(within(cards()[0]).getByRole("button", { name: "Mulai buat" }));
        act(() => lastOptions().onNetworkError(new Error("Network Error")));

        expect(toast.error).toHaveBeenCalledWith("Gagal menyimpan — periksa koneksi, lalu coba lagi");
    });

    it("posts nothing and shows the save error when offline", async () => {
        vi.spyOn(navigator, "onLine", "get").mockReturnValue(false);
        const user = setup();
        render(<Index orders={stepOrders} outlet={outlet} />);

        await user.click(within(cards()[0]).getByRole("button", { name: "Mulai buat" }));

        expect(router.post).not.toHaveBeenCalled();
        expect(toast.error).toHaveBeenCalledWith("Gagal menyimpan — periksa koneksi, lalu coba lagi");
    });
});
