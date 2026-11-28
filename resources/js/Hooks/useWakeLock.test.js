import { act, renderHook } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { useWakeLock } from "./useWakeLock";

let visibility;
let sentinels;
let request;

const makeSentinel = () => {
    const listeners = new Set();
    const sentinel = {
        released: false,
        release: vi.fn(async () => {
            sentinel.released = true;
        }),
        addEventListener: (_type, fn) => listeners.add(fn),
        // What the browser does when it takes the lock away: mark it released, then fire "release".
        browserReleases: () => {
            sentinel.released = true;
            listeners.forEach((fn) => fn());
        },
    };
    sentinels.push(sentinel);
    return sentinel;
};

const setVisibility = (state) => {
    visibility = state;
    act(() => {
        document.dispatchEvent(new Event("visibilitychange"));
    });
};
const settle = () => act(() => Promise.resolve());

beforeEach(() => {
    visibility = "visible";
    sentinels = [];
    request = vi.fn(async () => makeSentinel());
    vi.spyOn(document, "visibilityState", "get").mockImplementation(() => visibility);
    Object.defineProperty(navigator, "wakeLock", { value: { request }, configurable: true });
});

afterEach(() => {
    delete navigator.wakeLock;
    vi.restoreAllMocks();
});

describe("useWakeLock", () => {
    it("requests a screen lock while enabled and visible, and reports held", async () => {
        const { result } = renderHook(() => useWakeLock(true));
        expect(result.current).toBe("pending");
        await settle();

        expect(request).toHaveBeenCalledWith("screen");
        expect(request).toHaveBeenCalledTimes(1);
        expect(result.current).toBe("held");
    });

    it("does nothing while disabled", async () => {
        const { result } = renderHook(() => useWakeLock(false));
        await settle();

        expect(request).not.toHaveBeenCalled();
        expect(result.current).toBe("released");
    });

    it("waits for the page to be visible before it asks", async () => {
        visibility = "hidden";
        const { result } = renderHook(() => useWakeLock(true));
        await settle();
        expect(request).not.toHaveBeenCalled();

        setVisibility("visible");
        await settle();
        expect(request).toHaveBeenCalledTimes(1);
        expect(result.current).toBe("held");
    });

    it("asks again when the page becomes visible after the browser took the lock away", async () => {
        const { result } = renderHook(() => useWakeLock(true));
        await settle();

        setVisibility("hidden");
        sentinels[0].browserReleases();
        expect(result.current).toBe("held");

        setVisibility("visible");
        await settle();
        expect(request).toHaveBeenCalledTimes(2);
        expect(result.current).toBe("held");
    });

    it("does not ask again while the lock is still held", async () => {
        renderHook(() => useWakeLock(true));
        await settle();

        setVisibility("visible");
        await settle();

        expect(request).toHaveBeenCalledTimes(1);
    });

    it("reports released when the lock is lost while the page is visible", async () => {
        const { result } = renderHook(() => useWakeLock(true));
        await settle();

        act(() => sentinels[0].browserReleases());

        expect(result.current).toBe("released");
    });

    it("releases the lock when disabled and asks again when enabled again", async () => {
        const { result, rerender } = renderHook(({ on }) => useWakeLock(on), { initialProps: { on: true } });
        await settle();

        rerender({ on: false });
        await settle();
        expect(sentinels[0].release).toHaveBeenCalled();
        expect(result.current).toBe("released");

        rerender({ on: true });
        await settle();
        expect(request).toHaveBeenCalledTimes(2);
        expect(result.current).toBe("held");
    });

    it("releases the lock on unmount", async () => {
        const { unmount } = renderHook(() => useWakeLock(true));
        await settle();

        unmount();

        expect(sentinels[0].release).toHaveBeenCalled();
    });

    it("releases a lock that arrives after the hook was disabled", async () => {
        let grant;
        request.mockImplementation(() => new Promise((resolve) => (grant = resolve)));
        const { rerender } = renderHook(({ on }) => useWakeLock(on), { initialProps: { on: true } });
        rerender({ on: false });

        const sentinel = makeSentinel();
        grant(sentinel);
        await settle();

        expect(sentinel.release).toHaveBeenCalled();
    });

    it.each([
        ["refused", "the browser says no", () => request.mockRejectedValue(new DOMException("denied", "NotAllowedError"))],
        ["unsupported", "there is no Wake Lock API", () => delete navigator.wakeLock],
    ])("reports %s when %s", async (state, _why, arrange) => {
        arrange();
        const { result } = renderHook(() => useWakeLock(true));
        await settle();

        expect(result.current).toBe(state);
    });

    it("stops listening to visibility changes on unmount", async () => {
        const { unmount } = renderHook(() => useWakeLock(true));
        await settle();
        unmount();

        setVisibility("visible");
        await settle();

        expect(request).toHaveBeenCalledTimes(1);
    });
});
