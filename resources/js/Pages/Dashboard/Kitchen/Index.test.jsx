import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, within, act } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import toast from "react-hot-toast";
import { usePoll, router } from "@inertiajs/react";
import i18n from "@/i18n";
import { useKitchenPrintDevice } from "@/Hooks/useKitchenPrintDevice";
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
vi.mock("@/Hooks/useKitchenPrintDevice", () => ({ useKitchenPrintDevice: vi.fn(() => ({ station: null })) }));
vi.mock("./PrintDevicePanel", () => ({ default: () => <div data-testid="print-panel" /> }));

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
    localStorage.clear();
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

        expect(usePoll).toHaveBeenCalledWith(5000, expect.objectContaining({ only: ["orders", "stations"] }));
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

    it("runs the print device hook for the page and toggles its panel with a button", async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(<Index orders={orders} outlet={outlet} />);
        expect(useKitchenPrintDevice).toHaveBeenCalledWith(outlet);
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

describe("Kitchen/Index stations", () => {
    const KEY = "kitchenStations";
    const stations = [
        { id: 1, name: "Dapur", is_default: true },
        { id: 2, name: "Bar", is_default: false },
    ];
    const item = (id, title, station_id, station, status = "accepted") => ({
        id, title, qty: 1, note: null, status, station_id, station,
    });
    const stationOrders = [
        {
            id: 21, number: "Meja 1", type: "dine_in", unpaid: false, since: minutesAgo(5),
            items: [item(11, "Kopi", 2, "Bar", "ready"), item(12, "Nasi Goreng", 1, "Dapur", "preparing")],
        },
        {
            id: 22, number: "Meja 2", type: "dine_in", unpaid: false, since: minutesAgo(4),
            items: [item(13, "Mie Ayam", 1, "Dapur")],
        },
        {
            id: 23, number: "Meja 3", type: "takeaway", unpaid: false, since: minutesAgo(3),
            items: [item(14, "Teh", 2, "Bar")],
        },
    ];
    const cards = () => screen.queryAllByRole("article");
    const setup = () => userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    const chip = (name) => screen.getByRole("button", { name });
    const renderIndex = (props = {}) =>
        render(<Index orders={stationOrders} outlet={outlet} stations={stations} {...props} />);

    it("starts on Semua stasiun with every order and item when nothing is saved", () => {
        renderIndex();

        expect(screen.getByRole("group", { name: "Stasiun" })).toBeInTheDocument();
        expect(chip("Semua stasiun")).toHaveAttribute("aria-pressed", "true");
        expect(chip("Bar")).toHaveAttribute("aria-pressed", "false");
        expect(chip("Dapur")).toHaveAttribute("aria-pressed", "false");
        expect(cards()).toHaveLength(3);
        expect(screen.getByText(/Kopi/)).toBeInTheDocument();
        expect(screen.getByText(/Nasi Goreng/)).toBeInTheDocument();
    });

    it("filters the items on a chip tap and drops orders with nothing left", async () => {
        const user = setup();
        renderIndex();

        await user.click(chip("Bar"));

        expect(chip("Bar")).toHaveAttribute("aria-pressed", "true");
        expect(chip("Semua stasiun")).toHaveAttribute("aria-pressed", "false");
        expect(cards()).toHaveLength(2);
        expect(screen.getByText(/Kopi/)).toBeInTheDocument();
        expect(screen.getByText(/Teh/)).toBeInTheDocument();
        expect(screen.queryByText(/Nasi Goreng/)).not.toBeInTheDocument();
        expect(screen.queryByText(/Mie Ayam/)).not.toBeInTheDocument();
        expect(screen.queryByText("Meja 2")).not.toBeInTheDocument();
        expect(screen.queryByText(/dari \d+ siap/)).not.toBeInTheDocument();
    });

    it("shows the union of two chosen stations and returns to Semua when the last chip is untapped", async () => {
        const user = setup();
        renderIndex();

        await user.click(chip("Bar"));
        await user.click(chip("Dapur"));
        expect(cards()).toHaveLength(3);
        expect(chip("Bar")).toHaveAttribute("aria-pressed", "true");
        expect(chip("Dapur")).toHaveAttribute("aria-pressed", "true");
        expect(screen.queryByText(/dari \d+ siap/)).not.toBeInTheDocument();

        await user.click(chip("Bar"));
        await user.click(chip("Dapur"));
        expect(chip("Semua stasiun")).toHaveAttribute("aria-pressed", "true");
        expect(localStorage.getItem(KEY)).toBeNull();
    });

    it("saves the choice and a remount reads it back; Semua stasiun clears the key", async () => {
        const user = setup();
        const first = renderIndex();

        await user.click(chip("Bar"));
        expect(JSON.parse(localStorage.getItem(KEY))).toEqual([2]);
        first.unmount();

        renderIndex();
        expect(chip("Bar")).toHaveAttribute("aria-pressed", "true");
        expect(cards()).toHaveLength(2);

        await user.click(chip("Semua stasiun"));
        expect(localStorage.getItem(KEY)).toBeNull();
        expect(cards()).toHaveLength(3);
    });

    it.each([
        ["bad JSON", "{nope"],
        ["a non-array", '{"a":1}'],
        ["a non-integer array", '[1,"2"]'],
        ["a fractional array", "[1.5]"],
    ])("treats %s in storage as Semua stasiun", (_, saved) => {
        localStorage.setItem(KEY, saved);
        renderIndex();

        expect(chip("Semua stasiun")).toHaveAttribute("aria-pressed", "true");
        expect(cards()).toHaveLength(3);
        expect(screen.queryByRole("status")).not.toBeInTheDocument();
    });

    it("does not crash when storage is blocked", async () => {
        vi.spyOn(Storage.prototype, "getItem").mockImplementation(() => {
            throw new Error("blocked");
        });
        vi.spyOn(Storage.prototype, "setItem").mockImplementation(() => {
            throw new Error("blocked");
        });
        const user = setup();
        renderIndex();

        expect(chip("Semua stasiun")).toHaveAttribute("aria-pressed", "true");
        await user.click(chip("Bar"));
        expect(cards()).toHaveLength(2);
    });

    it("falls back to Semua stasiun with a notice when every saved station is gone, writing nothing", () => {
        localStorage.setItem(KEY, "[9]");
        renderIndex();

        expect(screen.getByRole("status")).toHaveTextContent("Stasiun yang dipilih sudah dihapus. Menampilkan Semua stasiun.");
        expect(chip("Semua stasiun")).toHaveAttribute("aria-pressed", "true");
        expect(cards()).toHaveLength(3);
        expect(localStorage.getItem(KEY)).toBe("[9]");
    });

    it("filters by the remaining saved station without a notice when another one was deleted", () => {
        localStorage.setItem(KEY, "[2,9]");
        renderIndex();

        expect(screen.queryByRole("status")).not.toBeInTheDocument();
        expect(chip("Bar")).toHaveAttribute("aria-pressed", "true");
        expect(cards()).toHaveLength(2);
    });

    it("says so when orders exist but none belongs to the chosen station", async () => {
        const user = setup();
        renderIndex({ orders: [stationOrders[1]] });

        await user.click(chip("Bar"));

        expect(screen.getByText("Belum ada pesanan untuk stasiun ini")).toBeInTheDocument();
        expect(screen.queryByText("Belum ada pesanan")).not.toBeInTheDocument();
        expect(cards()).toHaveLength(0);
    });

    it("shows the station on each item and the ready count in the order header on Semua stasiun", () => {
        renderIndex();

        const first = cards()[0];
        const rows = within(first).getAllByRole("listitem");
        expect(within(rows[0]).getByText("Bar")).toBeInTheDocument();
        expect(within(rows[1]).getByText("Dapur")).toBeInTheDocument();
        expect(within(first).getByText("1 dari 2 siap")).toBeInTheDocument();
        expect(within(cards()[1]).getByText("0 dari 1 siap")).toBeInTheDocument();
    });

    it("shows the English wording", async () => {
        await i18n.changeLanguage("en");
        renderIndex();

        expect(screen.getByRole("group", { name: "Station" })).toBeInTheDocument();
        expect(chip("All stations")).toHaveAttribute("aria-pressed", "true");
        expect(within(cards()[0]).getByText("1 of 2 ready")).toBeInTheDocument();
    });

    it.each([
        ["no stations prop", { stations: undefined }],
        ["one station", { stations: [stations[0]] }],
    ])("changes nothing on screen with %s", (_, props) => {
        renderIndex(props);

        expect(screen.queryByRole("group")).not.toBeInTheDocument();
        expect(screen.queryByText("Semua stasiun")).not.toBeInTheDocument();
        expect(screen.queryByText("Bar")).not.toBeInTheDocument();
        expect(screen.queryByText("Dapur")).not.toBeInTheDocument();
        expect(screen.queryByText(/dari \d+ siap/)).not.toBeInTheDocument();
        expect(cards()).toHaveLength(3);
    });

    describe("item of a deleted station or without one", () => {
        const gone = { id: 31, number: "Meja 7", type: "dine_in", unpaid: false, since: minutesAgo(2),
            items: [item(21, "Es Kopi", 5, "Kopi Lama")] };
        const noStation = { id: 32, number: "Meja 8", type: "dine_in", unpaid: false, since: minutesAgo(2),
            items: [item(22, "Bakso", null, null)] };

        it("is filtered as Dapur, keeps its old label on Semua and is hidden on a Bar filter", async () => {
            const user = setup();
            renderIndex({ orders: [gone] });

            expect(within(cards()[0]).getByText("Kopi Lama")).toBeInTheDocument();

            await user.click(chip("Bar"));
            expect(cards()).toHaveLength(0);

            await user.click(chip("Bar"));
            await user.click(chip("Dapur"));
            expect(cards()).toHaveLength(1);
            expect(within(cards()[0]).getByText(/Es Kopi/)).toBeInTheDocument();
            // On the Dapur device it still says where the item came from.
            expect(within(cards()[0]).getByText("Kopi Lama")).toBeInTheDocument();
        });

        it("is treated as Dapur when it has no station at all", async () => {
            const user = setup();
            renderIndex({ orders: [noStation] });

            await user.click(chip("Bar"));
            expect(cards()).toHaveLength(0);

            await user.click(chip("Dapur"));
            expect(cards()).toHaveLength(1);
        });
    });

    describe("Semua siap", () => {
        const allReadyBody = () => router.post.mock.calls.at(-1)[1];
        const allReadyButton = (card) => within(card).queryByRole("button", { name: "Semua siap" });

        it("posts an empty body on Semua stasiun", async () => {
            const user = setup();
            renderIndex();

            await user.click(allReadyButton(cards()[0]));

            expect(router.post).toHaveBeenLastCalledWith("/kitchen.orders.all-ready", {}, expect.anything());
        });

        it("posts the chosen station ids when the display is filtered", async () => {
            const user = setup();
            renderIndex();

            await user.click(chip("Dapur"));
            await user.click(chip("Bar"));
            await user.click(allReadyButton(cards()[0]));

            expect(allReadyBody()).toEqual({ station_ids: [1, 2] });
        });

        it("hides when every shown item is ready, even if the order has other unfinished items", async () => {
            const user = setup();
            renderIndex();
            expect(allReadyButton(cards()[0])).toBeInTheDocument();

            await user.click(chip("Bar"));

            expect(allReadyButton(cards()[0])).not.toBeInTheDocument();
            expect(allReadyButton(cards()[1])).toBeInTheDocument();
        });
    });

    describe("Serahkan semua", () => {
        const handOverAll = (card) => within(card).getByRole("button", { name: "Serahkan semua" });

        it("posts an empty body on Semua stasiun", async () => {
            permissions = { "dine-orders-handover": true };
            const user = setup();
            renderIndex();

            await user.click(handOverAll(cards()[0]));

            expect(router.post).toHaveBeenLastCalledWith("/kitchen.orders.hand-over-all", {}, expect.anything());
        });

        it("posts the chosen station ids when the display is filtered, like Semua siap", async () => {
            permissions = { "dine-orders-handover": true };
            const user = setup();
            renderIndex();

            await user.click(chip("Bar"));
            await user.click(handOverAll(cards()[0]));

            expect(router.post).toHaveBeenLastCalledWith("/kitchen.orders.hand-over-all", { station_ids: [2] }, expect.anything());
        });
    });

    it("keeps the chips but shows no step buttons for a handover-only user", async () => {
        permissions = {};
        const user = setup();
        renderIndex();

        await user.click(chip("Bar"));

        expect(chip("Bar")).toHaveAttribute("aria-pressed", "true");
        expect(screen.queryByRole("button", { name: /Mulai buat|Semua siap/ })).not.toBeInTheDocument();
        expect(within(cards()[0]).queryByRole("button")).not.toBeInTheDocument();
    });

    it("updates the chips and labels when a poll brings a renamed or added station", () => {
        const view = renderIndex();
        expect(screen.queryByRole("button", { name: "Pastry" })).not.toBeInTheDocument();

        const renamed = [stations[0], { id: 2, name: "Barista", is_default: false }, { id: 3, name: "Pastry", is_default: false }];
        const renamedOrders = stationOrders.map((o) => ({
            ...o,
            items: o.items.map((i) => (i.station_id === 2 ? { ...i, station: "Barista" } : i)),
        }));
        view.rerender(<Index orders={renamedOrders} outlet={outlet} stations={renamed} />);

        expect(chip("Barista")).toBeInTheDocument();
        expect(chip("Pastry")).toBeInTheDocument();
        expect(screen.queryByRole("button", { name: "Bar" })).not.toBeInTheDocument();
        expect(within(cards()[0]).getAllByText("Barista").length).toBeGreaterThan(0);
    });
});

describe("Kitchen/Index hand over", () => {
    const handOverOrders = [
        {
            id: 11,
            number: "Meja 9",
            type: "dine_in",
            unpaid: false,
            since: minutesAgo(1),
            items: [
                { id: 4, title: "Kopi", qty: 1, note: null, status: "ready" },
                { id: 5, title: "Roti", qty: 1, note: null, status: "preparing" },
                { id: 6, title: "Teh", qty: 1, note: null, status: "cancelled" },
            ],
        },
        {
            id: 12,
            number: "Meja 2",
            type: "dine_in",
            unpaid: false,
            since: minutesAgo(2),
            items: [{ id: 7, title: "Soto", qty: 1, note: null, status: "preparing" }],
        },
    ];
    const cards = () => screen.getAllByRole("article");
    const lastOptions = () => router.post.mock.calls.at(-1)[2];
    const setup = () => userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

    beforeEach(() => {
        permissions = { "dine-orders-handover": true };
    });

    it("offers Serahkan on ready items only", () => {
        render(<Index orders={handOverOrders} outlet={outlet} />);

        const rows = within(cards()[0]).getAllByRole("listitem");
        expect(within(rows[0]).getByRole("button", { name: "Serahkan" })).toBeInTheDocument();
        expect(within(rows[1]).queryByRole("button", { name: "Serahkan" })).not.toBeInTheDocument();
        expect(within(rows[2]).queryByRole("button", { name: "Serahkan" })).not.toBeInTheDocument();
        expect(within(cards()[1]).queryByRole("button", { name: "Serahkan" })).not.toBeInTheDocument();
    });

    it("shows Serahkan semua on an order while any item is ready", () => {
        render(<Index orders={handOverOrders} outlet={outlet} />);

        expect(within(cards()[0]).getByRole("button", { name: "Serahkan semua" })).toBeInTheDocument();
        expect(within(cards()[1]).queryByRole("button", { name: "Serahkan semua" })).not.toBeInTheDocument();
    });

    it("gives a hand-over-only role no Mulai buat, Siap or Semua siap", () => {
        render(<Index orders={[...orders, ...handOverOrders]} outlet={outlet} />);

        expect(screen.queryByRole("button", { name: /Mulai buat|^Siap$|Semua siap/ })).not.toBeInTheDocument();
        expect(screen.getAllByRole("button", { name: "Serahkan" })).toHaveLength(1);
    });

    it("gives a prepare-only role no Serahkan or Serahkan semua", () => {
        permissions = { "dine-orders-prepare": true };
        render(<Index orders={handOverOrders} outlet={outlet} />);

        expect(screen.queryByRole("button", { name: /Serahkan/ })).not.toBeInTheDocument();
        expect(screen.getAllByRole("button", { name: "Siap" })).toHaveLength(2);
    });

    it("posts each hand-over to its route and keeps the scroll position", async () => {
        const user = setup();
        render(<Index orders={handOverOrders} outlet={outlet} />);
        const [first] = cards();

        await user.click(within(first).getByRole("button", { name: "Serahkan" }));
        expect(router.post).toHaveBeenLastCalledWith("/kitchen.items.hand-over", {}, expect.objectContaining({ preserveScroll: true }));
        act(() => lastOptions().onFinish());

        await user.click(within(first).getByRole("button", { name: "Serahkan semua" }));
        expect(router.post).toHaveBeenLastCalledWith("/kitchen.orders.hand-over-all", {}, expect.objectContaining({ preserveScroll: true }));
    });

    it("disables the tapped Serahkan while its request runs and frees it afterwards", async () => {
        const user = setup();
        render(<Index orders={handOverOrders} outlet={outlet} />);
        const button = within(cards()[0]).getByRole("button", { name: "Serahkan" });

        await user.click(button);
        expect(button).toBeDisabled();
        await user.click(button);
        expect(router.post).toHaveBeenCalledTimes(1);

        act(() => lastOptions().onFinish());
        expect(button).toBeEnabled();
    });

    it("shows the server message in an error toast", async () => {
        const user = setup();
        render(<Index orders={handOverOrders} outlet={outlet} />);

        await user.click(within(cards()[0]).getByRole("button", { name: "Serahkan" }));
        act(() => lastOptions().onError({ item: "Sudah diproses oleh Sari" }));

        expect(toast.error).toHaveBeenCalledWith("Sudah diproses oleh Sari");
    });

    it("shows the save error on a network failure", async () => {
        const user = setup();
        render(<Index orders={handOverOrders} outlet={outlet} />);

        await user.click(within(cards()[0]).getByRole("button", { name: "Serahkan semua" }));
        act(() => lastOptions().onNetworkError(new Error("Network Error")));

        expect(toast.error).toHaveBeenCalledWith("Gagal menyimpan — periksa koneksi, lalu coba lagi");
    });

    it("posts nothing and shows the save error when offline", async () => {
        vi.spyOn(navigator, "onLine", "get").mockReturnValue(false);
        const user = setup();
        render(<Index orders={handOverOrders} outlet={outlet} />);

        await user.click(within(cards()[0]).getByRole("button", { name: "Serahkan" }));

        expect(router.post).not.toHaveBeenCalled();
        expect(toast.error).toHaveBeenCalledWith("Gagal menyimpan — periksa koneksi, lalu coba lagi");
    });
});

describe("Kitchen/Index: printer status, Belum tercetak and reprint (REQ-004 K2)", () => {
    const secondsAgo = (s) => new Date(NOW.getTime() - s * 1000).toISOString();
    const device = { id: 5, name: "Dapur 1" };
    const printer = (over = {}) => ({
        device,
        printerKey: "1:2:abc",
        autoPrint: true,
        status: null,
        unprinted: [],
        retry: vi.fn(),
        // Every item of both cards printed here, unless a test says otherwise.
        printedIds: [1, 2, 3],
        headFailed: false,
        reprinting: [],
        reprintFailed: null,
        reprint: vi.fn(),
        ...over,
    });
    const use = (over) => {
        const value = printer(over);
        useKitchenPrintDevice.mockReturnValue(value);
        return value;
    };
    const cards = () => screen.getAllByRole("article");
    afterEach(() => useKitchenPrintDevice.mockImplementation(() => ({ station: null })));
    // Order 7 is "Meja 4" (first card), order 9 is "INV-0009" (second card).
    const entry = (id, over = {}) => ({ key: `t${id}`, since: secondsAgo(12 * 60), state: "queued", ...over });

    it("stays quiet when this display has no print device", () => {
        useKitchenPrintDevice.mockReturnValue({ device: null, unprinted: [], status: "failed" });
        render(<Index orders={orders} outlet={outlet} />);

        expect(screen.queryByRole("alert")).not.toBeInTheDocument();
        expect(screen.queryByRole("button", { name: /Cetak ulang|Coba lagi/ })).not.toBeInTheDocument();
        expect(screen.queryByText("Belum tercetak")).not.toBeInTheDocument();
    });

    it("stays quiet when there is no outlet", () => {
        use();
        render(<Index orders={[]} outlet={null} />);

        expect(screen.queryByRole("alert")).not.toBeInTheDocument();
    });

    it("stays quiet with auto-print off and no printer connected", () => {
        use({ autoPrint: false, printerKey: "", unprinted: [entry(7)] });
        render(<Index orders={orders} outlet={outlet} />);

        expect(screen.queryByRole("button", { name: /Cetak ulang|Coba lagi/ })).not.toBeInTheDocument();
    });

    describe("banner", () => {
        it.each([
            ["failed", "Gagal mencetak — coba lagi"],
            ["not_connected", "Printer dapur tidak terhubung"],
            ["stale", "Printer dapur offline atau tab tidur"],
            ["wake_lock_off", "Layar bisa tidur — jaga perangkat tetap menyala"],
            ["driver_locked", "Printer tidak bisa dipakai dari browser — ganti driver ke WinUSB"],
        ])("shows the %s banner with the REQ wording", (status, text) => {
            use({ status });
            render(<Index orders={orders} outlet={outlet} />);

            expect(screen.getByRole("alert")).toHaveTextContent(text);
        });

        it("shows no banner for unsupported, with auto-print off, or when all is well", () => {
            use({ status: "unsupported" });
            const first = render(<Index orders={orders} outlet={outlet} />);
            expect(screen.queryByRole("alert")).not.toBeInTheDocument();
            first.unmount();

            use({ status: "failed", autoPrint: false });
            const second = render(<Index orders={orders} outlet={outlet} />);
            expect(screen.queryByRole("alert")).not.toBeInTheDocument();
            second.unmount();

            use({ status: null });
            render(<Index orders={orders} outlet={outlet} />);
            expect(screen.queryByRole("alert")).not.toBeInTheDocument();
        });

        it("the banner button retries the print", async () => {
            const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
            const hook = use({ status: "failed" });
            render(<Index orders={orders} outlet={outlet} />);

            await user.click(within(screen.getByRole("alert")).getByRole("button", { name: "Coba lagi" }));

            expect(hook.retry).toHaveBeenCalledTimes(1);
        });

        it("keeps every card on the display whatever the print state", () => {
            use({ status: "failed", unprinted: [entry(7, { state: "failed" }), entry(9)] });
            render(<Index orders={orders} outlet={outlet} />);

            expect(cards()).toHaveLength(2);
        });
    });

    describe("Belum tercetak chip", () => {
        it("marks the card whose ticket has waited longer than 30 s, as text, and only that card", () => {
            use({ unprinted: [entry(7, { since: secondsAgo(31) })] });
            render(<Index orders={orders} outlet={outlet} />);

            expect(within(cards()[0]).getByText("Belum tercetak")).toBeInTheDocument();
            expect(within(cards()[1]).queryByText("Belum tercetak")).not.toBeInTheDocument();
        });

        it("waits 30 s before it shows for a ticket that is still queued", () => {
            use({ unprinted: [entry(7, { since: secondsAgo(29) })] });
            render(<Index orders={orders} outlet={outlet} />);

            expect(screen.queryByText("Belum tercetak")).not.toBeInTheDocument();
        });

        it("shows at once for a ticket whose print failed", () => {
            use({ unprinted: [entry(9, { since: secondsAgo(2), state: "failed" })] });
            render(<Index orders={orders} outlet={outlet} />);

            expect(within(cards()[1]).getByText("Belum tercetak")).toBeInTheDocument();
        });

        it("matches the card by its ticket key t<order.id>", () => {
            use({ unprinted: [{ key: "c7t1", since: secondsAgo(600), state: "queued" }, entry(8)] });
            render(<Index orders={orders} outlet={outlet} />);

            expect(screen.queryByText("Belum tercetak")).not.toBeInTheDocument();
        });

        it("says Not printed in English", async () => {
            await i18n.changeLanguage("en");
            use({ unprinted: [entry(7)] });
            render(<Index orders={orders} outlet={outlet} />);

            expect(within(cards()[0]).getByText("Not printed")).toBeInTheDocument();
            expect(within(cards()[0]).getByRole("button", { name: "Try again" })).toBeInTheDocument();
            expect(within(cards()[1]).getByRole("button", { name: "Reprint" })).toBeInTheDocument();
        });
    });

    describe("one button per card", () => {
        const user = () => userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        const reprintIn = (card) => within(card).queryByRole("button", { name: "Cetak ulang" });

        it("a chipped card offers Coba lagi, 44 px tall, not Cetak ulang, and it retries the original ticket", async () => {
            const hook = use({ unprinted: [entry(7)] });
            render(<Index orders={orders} outlet={outlet} />);

            expect(reprintIn(cards()[0])).not.toBeInTheDocument();
            expect(within(cards()[1]).queryByRole("button", { name: "Coba lagi" })).not.toBeInTheDocument();
            const retry = within(cards()[0]).getByRole("button", { name: "Coba lagi" });
            expect(retry).toHaveClass("min-h-[44px]");
            await user().click(retry);

            expect(hook.retry).toHaveBeenCalledTimes(1);
            expect(hook.reprint).not.toHaveBeenCalled();
        });

        it("a printed card offers Cetak ulang, 44 px tall, which reprints that order", async () => {
            const hook = use();
            render(<Index orders={orders} outlet={outlet} />);

            const reprint = reprintIn(cards()[1]);
            expect(reprint).toHaveClass("min-h-[44px]");
            await user().click(reprint);

            expect(hook.reprint).toHaveBeenCalledTimes(1);
            expect(hook.reprint).toHaveBeenCalledWith(orders[1]);
        });

        it.each([true, false])("offers Cetak ulang only for an order this device printed (auto-print %s)", (autoPrint) => {
            use({ autoPrint, printedIds: [1, 3] }); // order 7 has an item this device never printed
            render(<Index orders={orders} outlet={outlet} />);

            expect(reprintIn(cards()[0])).not.toBeInTheDocument();
            expect(reprintIn(cards()[1])).toBeInTheDocument();
        });

        it("ignores a cancelled line, and offers nothing for an order that is all cancelled", () => {
            const cancelled = (item) => ({ ...item, status: "cancelled" });
            const changed = [
                { ...orders[0], items: [orders[0].items[0], cancelled(orders[0].items[1])] },
                { ...orders[1], items: orders[1].items.map(cancelled) },
            ];
            use({ printedIds: [1, 3] });
            render(<Index orders={changed} outlet={outlet} />);

            expect(reprintIn(cards()[0])).toBeInTheDocument();
            expect(reprintIn(cards()[1])).not.toBeInTheDocument();
        });

        it("reprints the whole order when the station filter hides some of its lines", async () => {
            localStorage.setItem("kitchenStations", "[1]");
            const stations = [{ id: 1, name: "Dapur", is_default: true }, { id: 2, name: "Bar", is_default: false }];
            const mixed = [{ ...orders[0], items: orders[0].items.map((item, i) => ({ ...item, station_id: i + 1 })) }];
            const hook = use();
            render(<Index orders={mixed} outlet={outlet} stations={stations} />);
            expect(screen.queryByText(/Es Teh/)).not.toBeInTheDocument();

            await user().click(reprintIn(cards()[0]));

            expect(hook.reprint).toHaveBeenCalledWith(mixed[0]);
        });

        it("a ticket still queued and young has no button yet", () => {
            use({ unprinted: [entry(7, { since: secondsAgo(5) })] });
            render(<Index orders={orders} outlet={outlet} />);

            expect(within(cards()[0]).queryByRole("button", { name: /Cetak ulang|Coba lagi/ })).not.toBeInTheDocument();
            expect(reprintIn(cards()[1])).toBeInTheDocument();
        });

        it("with auto-print off a printed card keeps Cetak ulang, and no Coba lagi, chip or banner shows", () => {
            use({ autoPrint: false, status: "failed", unprinted: [entry(7)] });
            render(<Index orders={orders} outlet={outlet} />);

            expect(screen.getAllByRole("button", { name: "Cetak ulang" })).toHaveLength(2);
            expect(screen.queryByRole("button", { name: "Coba lagi" })).not.toBeInTheDocument();
            expect(screen.queryByText("Belum tercetak")).not.toBeInTheDocument();
            expect(screen.queryByRole("alert")).not.toBeInTheDocument();
        });

        it("Cetak ulang is disabled while the queue head is failed", () => {
            use({ headFailed: true, unprinted: [entry(7, { state: "failed" })] });
            render(<Index orders={orders} outlet={outlet} />);

            expect(reprintIn(cards()[1])).toBeDisabled();
        });

        it("Cetak ulang is disabled while its own reprint is queued, and only on that card", () => {
            use({ reprinting: [9] });
            render(<Index orders={orders} outlet={outlet} />);

            expect(reprintIn(cards()[1])).toBeDisabled();
            expect(reprintIn(cards()[0])).toBeEnabled();
        });

        it("shows an inline error in the card whose reprint failed", () => {
            use({ reprintFailed: 9 });
            render(<Index orders={orders} outlet={outlet} />);

            expect(within(cards()[1]).getByText("Gagal mencetak ulang")).toBeInTheDocument();
            expect(within(cards()[0]).queryByText("Gagal mencetak ulang")).not.toBeInTheDocument();
            expect(screen.queryByRole("alert")).not.toBeInTheDocument();
        });
    });
});
