import { act, cleanup, renderHook } from "@testing-library/react";
import axios from "axios";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { driver } from "driver.js";
import { usePage } from "@inertiajs/react";
import { useTour } from "./useTour";

vi.mock("axios", () => ({ default: { post: vi.fn() } }));
vi.mock("@inertiajs/react", () => ({ usePage: vi.fn() }));
vi.mock("driver.js", () => ({
    driver: vi.fn((cfg) => ({
        drive: vi.fn(),
        destroy: vi.fn(() => cfg.onDestroyed?.()),
    })),
}));

// Selectors of the "pos" tour in Utils/tours.js
const POS_TARGETS = ["pos-search", "pos-products", "pos-customer", "pos-cart", "pos-payment"];

let targets = [];

function renderTargets(names) {
    targets = names.map((name) => {
        const el = document.createElement("div");
        el.setAttribute("data-tour", name);
        document.body.appendChild(el);
        return el;
    });
}

function setCompletedTours(completedTours) {
    // Stable object so the hook's effect does not re-run on every render
    const page = { props: { auth: { completedTours } } };
    vi.mocked(usePage).mockReturnValue(page);
}

function advance(ms) {
    act(() => {
        vi.advanceTimersByTime(ms);
    });
}

describe("useTour", () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.mocked(axios.post).mockResolvedValue({});
        setCompletedTours([]);
        renderTargets(POS_TARGETS);
    });

    afterEach(() => {
        // Unmount first: the hook destroys a running tour on unmount, which posts completion
        cleanup();
        targets.forEach((el) => el.remove());
        targets = [];
        vi.useRealTimers();
        vi.restoreAllMocks();
        vi.clearAllMocks();
    });

    it("auto-starts an unseen tour after 600 ms", () => {
        const { result } = renderHook(() => useTour("pos"));

        advance(599);
        expect(driver).not.toHaveBeenCalled();
        expect(result.current.isActive).toBe(false);

        advance(1);
        expect(driver).toHaveBeenCalledTimes(1);
        const instance = vi.mocked(driver).mock.results[0].value;
        expect(instance.drive).toHaveBeenCalledTimes(1);
        expect(result.current.isActive).toBe(true);
    });

    it("never auto-starts a completed tour", () => {
        setCompletedTours(["pos"]);
        const { result } = renderHook(() => useTour("pos"));

        advance(5000);

        expect(driver).not.toHaveBeenCalled();
        expect(result.current.isActive).toBe(false);
    });

    it("skips when no step target is on the page", () => {
        targets.forEach((el) => el.remove());
        targets = [];
        const { result } = renderHook(() => useTour("pos"));

        advance(600);

        expect(driver).not.toHaveBeenCalled();
        expect(result.current.isActive).toBe(false);
    });

    it("only includes the steps whose target is on the page", () => {
        targets.forEach((el) => el.remove());
        renderTargets(["pos-search", "pos-cart"]);
        renderHook(() => useTour("pos"));

        advance(600);

        const cfg = vi.mocked(driver).mock.calls[0][0];
        expect(cfg.steps.map((s) => s.element)).toEqual([
            "[data-tour='pos-search']",
            "[data-tour='pos-cart']",
        ]);
    });

    it("unknown tour name does nothing", () => {
        const { result } = renderHook(() => useTour("nope"));

        advance(600);

        expect(driver).not.toHaveBeenCalled();
        expect(result.current.isActive).toBe(false);
    });

    it("finishing the tour records completion", () => {
        const routeSpy = vi.spyOn(globalThis, "route");
        const { result } = renderHook(() => useTour("pos"));
        advance(600);
        expect(result.current.isActive).toBe(true);

        const { onDestroyed } = vi.mocked(driver).mock.calls[0][0];
        act(() => {
            onDestroyed();
        });

        expect(routeSpy).toHaveBeenCalledWith("tours.complete", { tour: "pos" });
        expect(axios.post).toHaveBeenCalledTimes(1);
        expect(axios.post).toHaveBeenCalledWith(
            "/tours.complete",
            {},
            { headers: { Accept: "application/json" } },
        );
        expect(result.current.isActive).toBe(false);
    });

    it("start() is idempotent while running", () => {
        const { result } = renderHook(() => useTour("pos"));
        advance(600);
        expect(driver).toHaveBeenCalledTimes(1);

        act(() => {
            result.current.start();
        });

        expect(driver).toHaveBeenCalledTimes(1);
        expect(result.current.isActive).toBe(true);
    });
});
