import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import i18n from "@/i18n";
import Index from "./Index";

vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    usePoll: vi.fn(),
    router: { post: vi.fn() },
    usePage: () => ({ props: { auth: { permissions: {} } } }),
}));
vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));
vi.mock("@/Hooks/useKitchenPrintDevice", () => ({ useKitchenPrintDevice: () => ({}) }));

const stations = [{ id: 1, name: "Dapur", is_default: true }, { id: 2, name: "Bar", is_default: false }];
const ago = (m) => new Date(Date.now() - m * 60000).toISOString();
const item = (id, status, ready_at = null, station_id = 2) => ({ id, title: `Menu ${id}`, qty: 1, status, station_id, ready_at });
const order = (id, items, since = ago(60)) => ({ id, number: `Meja ${id}`, type: "dine_in", since, items });
const done = (id, m) => order(id, [item(id, "ready", ago(m))]);
// Queue order 1..n; order n has waited longest since it became ready.
const waiting = (n) => Array.from({ length: n }, (_, i) => done(i + 1, i + 1));

const page = (orders) => <Index orders={orders} outlet={{ id: 1, name: "Pusat" }} stations={stations} />;
const onStation = (id, orders) => {
    localStorage.setItem("kitchenStations", `[${id}]`);
    return render(page(orders));
};
const pickup = () => screen.queryByRole("region", { name: /Siap diambil|Ready for pickup/ });
const numbers = (scope = screen) => scope.queryAllByRole("article").map((card) => within(card).getByRole("heading").textContent);
const inPickup = () => numbers(within(pickup()));
const click = (name) => userEvent.click(screen.getByRole("button", { name }));

describe("Kitchen/Index pickup section (#361)", () => {
    beforeEach(async () => {
        localStorage.clear();
        await i18n.changeLanguage("id");
    });

    it("semua stasiun keeps every order in the grid", () => {
        render(page([done(1, 5)]));
        expect(pickup()).not.toBeInTheDocument();
        expect(numbers()).toEqual(["Meja 1"]);
    });

    it("an order with an unmade station item stays in the grid", () => {
        onStation(2, [order(1, [item(1, "ready", ago(9)), item(3, "preparing")]), done(2, 1)]);
        expect(inPickup()).toEqual(["Meja 2"]);
        expect(numbers()).toEqual(["Meja 1", "Meja 2"]);
    });

    it("an order with a cancelled line and the rest ready moves to Siap diambil", () => {
        onStation(2, [order(1, [item(1, "ready", ago(9)), item(3, "cancelled")]), order(2, [item(2, "cancelled")])]);
        expect(inPickup()).toEqual(["Meja 1"]);
        expect(numbers()).toEqual(["Meja 2", "Meja 1"]);
    });

    it("shows the 10 longest waiting, oldest first", () => {
        onStation(2, waiting(12));

        expect(screen.getByRole("heading", { name: "Siap diambil (12)" })).toBeInTheDocument();
        expect(inPickup()).toEqual([12, 11, 10, 9, 8, 7, 6, 5, 4, 3].map((n) => `Meja ${n}`));
        const toggle = screen.getByRole("button", { name: "Lihat semua (12)" });
        expect(toggle).toHaveAttribute("aria-expanded", "false");
        expect(document.getElementById(toggle.getAttribute("aria-controls"))).toContainElement(screen.getAllByRole("article")[0]);
    });

    it("expands and collapses", async () => {
        onStation(2, waiting(12));

        await click("Lihat semua (12)");
        expect(inPickup()).toHaveLength(12);
        expect(screen.getByRole("button", { name: "Tampilkan lebih sedikit" })).toHaveAttribute("aria-expanded", "true");

        await click("Tampilkan lebih sedikit");
        expect(inPickup()).toHaveLength(10);
    });

    it("keeps the expanded choice across a poll", async () => {
        const view = onStation(2, waiting(11));
        await click("Lihat semua (11)");

        view.rerender(page(waiting(12)));

        expect(screen.getByRole("heading", { name: "Siap diambil (12)" })).toBeInTheDocument();
        expect(inPickup()).toHaveLength(12);
        expect(screen.getByRole("button", { name: "Tampilkan lebih sedikit" })).toBeInTheDocument();
    });

    it("drops a handed-over order, hides the toggle at 10 and the section when empty", () => {
        const view = onStation(2, waiting(11));

        view.rerender(page(waiting(10)));
        expect(inPickup()).toHaveLength(10);
        expect(inPickup()).not.toContain("Meja 11");
        expect(screen.queryByRole("button", { name: /Lihat semua|Tampilkan/ })).not.toBeInTheDocument();

        view.rerender(page([order(1, [item(1, "preparing")])]));
        expect(pickup()).not.toBeInTheDocument();
    });

    it("orders by the latest ready_at, and falls back to since without ready_at", () => {
        onStation(2, [
            order(1, [item(1, "ready", ago(30)), item(2, "ready", ago(1))]),
            done(3, 10),
            order(4, [item(4, "ready")], ago(20)),
        ]);
        expect(inPickup()).toEqual(["Meja 4", "Meja 3", "Meja 1"]);
    });

    it.each([[2, true], [1, false]])("splits per selected station: station %i", (station, ready) => {
        onStation(station, [order(1, [item(1, "ready", ago(2)), item(2, "preparing", null, 1)])]);
        expect(Boolean(pickup())).toBe(ready);
        expect(numbers()).toEqual(["Meja 1"]);
    });

    it("uses the English wording", async () => {
        await i18n.changeLanguage("en");
        onStation(2, waiting(11));

        expect(screen.getByRole("heading", { name: "Ready for pickup (11)" })).toBeInTheDocument();
        await click("Show all (11)");
        expect(screen.getByRole("button", { name: "Show less" })).toBeInTheDocument();
    });
});
