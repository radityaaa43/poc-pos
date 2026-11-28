import { describe, it, expect, vi, beforeAll, beforeEach } from "vitest";
import { renderHook, act } from "@testing-library/react";
import { router } from "@inertiajs/react";
import i18n from "@/i18n";
import useAddToCart from "./useAddToCart";
import { modifierLines } from "@/Components/Modifiers/LineModifiers";

vi.mock("@inertiajs/react", () => ({ router: { post: vi.fn() } }));
vi.mock("react-hot-toast", () => ({ default: { success: vi.fn(), error: vi.fn() } }));

const option = (id, is_default, available = true) => ({ id, is_default, available });
const groups = {
    7: [
        { id: 1, min_select: 1, max_select: 1, options: [option(11, true), option(12, false)] },
        { id: 2, min_select: 0, max_select: 1, options: [option(21, true, false)] },
    ],
};
const menu = { id: 7, title: "Kopi", menu_price: 20000 };

beforeAll(() => i18n.changeLanguage("id"));
beforeEach(() => {
    vi.clearAllMocks();
    vi.stubGlobal("route", (name) => name);
});

describe("useAddToCart", () => {
    it("sends a menu without groups straight to the cart", () => {
        const { result } = renderHook(() => useAddToCart(groups));

        act(() => expect(result.current.addToCart({ id: 8, title: "Air" })).toBe(true));

        expect(router.post).toHaveBeenCalledWith("transactions.addToCart", { product_id: 8, qty: 1, unit_id: undefined }, expect.anything());
        expect(result.current.panel).toBeNull();
    });

    it("opens the panel with the available defaults, then posts the choice", () => {
        const { result } = renderHook(() => useAddToCart(groups));

        act(() => expect(result.current.addToCart(menu)).toBe(false));

        expect(router.post).not.toHaveBeenCalled();
        expect(result.current.panel).toMatchObject({ title: "Kopi", basePrice: 20000, selected: [11] });

        act(() => result.current.panel.onAdd({ optionIds: [12], note: "less ice" }));

        expect(router.post.mock.calls[0][1]).toMatchObject({ product_id: 7, modifier_option_ids: [12], note: "less ice" });
    });

    it("keeps the panel open when a scan adds a plain product meanwhile", () => {
        const { result } = renderHook(() => useAddToCart(groups));

        act(() => void result.current.addToCart(menu));
        act(() => void result.current.addToCart({ id: 8, title: "Air" }));
        act(() => router.post.mock.calls[0][2].onSuccess());
        expect(result.current.panel).toMatchObject({ title: "Kopi" });
    });

    it("never adds a sold out menu", () => {
        const { result } = renderHook(() => useAddToCart(groups));

        act(() => expect(result.current.addToCart({ ...menu, modifiers_sold_out: true })).toBe(false));

        expect(router.post).not.toHaveBeenCalled();
        expect(result.current.panel).toBeNull();
    });

    it("forwards the pinned add-on order so the new line joins it", () => {
        window.history.replaceState({}, "", "/app/transactions?add_on_to=42");
        const { result } = renderHook(() => useAddToCart(groups));

        act(() => result.current.addToCart({ id: 8, title: "Air" }));

        expect(router.post.mock.calls[0][1]).toMatchObject({ product_id: 8, add_on_to: "42" });
        window.history.replaceState({}, "", "/");
    });
});

describe("modifierLines", () => {
    it("lists a sale line's options with their extra price, then the note", () => {
        const item = { modifiers: [{ option_name: "Large", price_delta: 5000 }, { option_name: "Less sugar", price_delta: 0 }], note: "dingin" };

        expect(modifierLines(item)).toEqual(["+ Large 5.000", "+ Less sugar", "Catatan: dingin"]);
        expect(modifierLines({ options: [{ name: "Boba", price_delta: 3000 }] })).toEqual(["+ Boba 3.000"]);
        expect(modifierLines({})).toEqual([]);
    });
});
