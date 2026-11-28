import { afterEach, describe, expect, it, vi } from "vitest";
import {
    STALE_HOLD_MS,
    STALE_PRINT_MS,
    classifyPrinterError,
    derivePrinterStatus,
    isNotPrinted,
    isStale,
    unprintedFromJobs,
    webUsbSupport,
} from "./printerStatus";

const WINDOWS = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130";
const LINUX = "Mozilla/5.0 (X11; Linux x86_64) Chrome/130";
const ANDROID = "Mozilla/5.0 (Linux; Android 14) Chrome/130";
const coded = (code, cause) => Object.assign(new Error(code, { cause }), { code });
const dom = (name) => new DOMException("x", name);

describe("classifyPrinterError", () => {
    it.each([
        ["unsupported", coded("unsupported"), WINDOWS, "unsupported"],
        ["not_connected", coded("not_connected"), WINDOWS, "not_connected"],
        ["transfer", coded("transfer", dom("NetworkError")), WINDOWS, "transfer"],
        ["transfer on Linux", coded("transfer", new Error("usb")), LINUX, "transfer"],
        ["claim_failed without a cause", coded("claim_failed"), WINDOWS, "claim_failed"],
    ])("keeps the code of %s", (_name, error, ua, expected) => {
        expect(classifyPrinterError(error, ua)).toBe(expected);
    });

    it.each(["NetworkError", "SecurityError"])("%s on claim under Windows is driver_locked", (name) => {
        expect(classifyPrinterError(coded("claim_failed", dom(name)), WINDOWS)).toBe("driver_locked");
    });

    it.each([
        ["Linux", LINUX],
        ["Android", ANDROID],
    ])("the same claim error on %s is claim_failed, not the WinUSB case", (_name, ua) => {
        expect(classifyPrinterError(coded("claim_failed", dom("NetworkError")), ua)).toBe("claim_failed");
    });

    it("Windows with another kind of claim error is claim_failed", () => {
        expect(classifyPrinterError(coded("claim_failed", dom("InvalidStateError")), WINDOWS)).toBe("claim_failed");
    });

    it("a transfer that finds the device gone is not_connected", () => {
        expect(classifyPrinterError(coded("transfer", dom("NotFoundError")), LINUX)).toBe("not_connected");
    });

    it("reads a raw DOMException too (its numeric legacy code is ignored)", () => {
        expect(classifyPrinterError(dom("NetworkError"), WINDOWS)).toBe("driver_locked");
        expect(classifyPrinterError(dom("NetworkError"), LINUX)).toBe("claim_failed");
        expect(classifyPrinterError(dom("NotFoundError"), LINUX)).toBe("not_connected");
    });

    it("anything unknown is a failed transfer", () => {
        expect(classifyPrinterError(new Error("boom"), WINDOWS)).toBe("transfer");
        expect(classifyPrinterError(undefined, WINDOWS)).toBe("transfer");
    });
});

describe("webUsbSupport", () => {
    afterEach(() => {
        delete navigator.usb;
        vi.unstubAllGlobals();
    });

    it("is unsupported without navigator.usb", () => {
        expect(webUsbSupport()).toBe("unsupported");
    });

    it("is insecure on a page that is not a secure context", () => {
        Object.defineProperty(navigator, "usb", { value: {}, configurable: true });
        vi.stubGlobal("isSecureContext", false);
        expect(webUsbSupport()).toBe("insecure");
    });

    it("is ok with navigator.usb", () => {
        Object.defineProperty(navigator, "usb", { value: {}, configurable: true });
        expect(webUsbSupport()).toBe("ok");
    });
});

describe("derivePrinterStatus", () => {
    const NOW = Date.parse("2026-11-26T10:10:00Z");
    const ago = (ms) => new Date(NOW - ms).toISOString();
    const base = { supported: true, autoPrint: true, errorCode: null, disconnected: false, unprinted: [], wakeLock: "held", now: NOW };
    const status = (over) => derivePrinterStatus({ ...base, ...over });
    const old = [{ key: "t1", since: ago(STALE_PRINT_MS + 1000), state: "queued" }];

    it("is null when everything is fine", () => {
        expect(status({})).toBeNull();
    });

    it("is null while Wake Lock is still being requested", () => {
        expect(status({ wakeLock: "pending" })).toBeNull();
    });

    it("unsupported wins over everything, even with auto-print off", () => {
        expect(status({ supported: false, errorCode: "driver_locked", autoPrint: false })).toBe("unsupported");
    });

    it("follows the priority table, highest first", () => {
        const all = { errorCode: "driver_locked", disconnected: true, unprinted: old, wakeLock: "refused" };
        expect(status(all)).toBe("driver_locked");
        expect(status({ ...all, errorCode: "transfer" })).toBe("not_connected");
        expect(status({ ...all, errorCode: "transfer", disconnected: false })).toBe("failed");
        expect(status({ ...all, errorCode: null, disconnected: false })).toBe("stale");
        expect(status({ ...all, errorCode: null, disconnected: false, unprinted: [] })).toBe("wake_lock_off");
    });

    it("a head job that failed with not_connected is not_connected", () => {
        expect(status({ errorCode: "not_connected" })).toBe("not_connected");
    });

    it.each(["transfer", "claim_failed"])("a %s failure is failed", (errorCode) => {
        expect(status({ errorCode })).toBe("failed");
    });

    it.each(["unsupported", "refused", "released"])("Wake Lock %s shows wake_lock_off", (wakeLock) => {
        expect(status({ wakeLock })).toBe("wake_lock_off");
    });

    it("stays quiet with auto-print off, except the driver lock a connect attempt found", () => {
        const noisy = { autoPrint: false, errorCode: "transfer", disconnected: true, unprinted: old, wakeLock: "refused" };
        expect(status(noisy)).toBeNull();
        expect(status({ ...noisy, errorCode: "driver_locked" })).toBe("driver_locked");
    });

    describe("stale", () => {
        it("needs an unprinted ticket older than 30 s", () => {
            expect(STALE_PRINT_MS).toBe(30000);
            expect(status({ unprinted: [{ key: "t1", since: ago(STALE_PRINT_MS), state: "queued" }] })).toBeNull();
            expect(status({ unprinted: [{ key: "t1", since: ago(STALE_PRINT_MS + 1), state: "queued" }] })).toBe("stale");
        });

        it("is held for 15 s after the condition cleared, then goes", () => {
            expect(STALE_HOLD_MS).toBe(15000);
            const staleAt = NOW - (STALE_HOLD_MS - 1);
            expect(status({ staleAt })).toBe("stale");
            expect(status({ staleAt: NOW - STALE_HOLD_MS })).toBeNull();
        });

        it("is not shown with auto-print off, hold or not", () => {
            expect(status({ autoPrint: false, staleAt: NOW })).toBeNull();
        });
    });
});

describe("isStale", () => {
    it("is true when any ticket is older than 30 s", () => {
        const now = Date.parse("2026-11-26T10:00:40Z");
        expect(isStale([{ since: "2026-11-26T10:00:00Z" }], now)).toBe(true);
        expect(isStale([{ since: "2026-11-26T10:00:20Z" }], now)).toBe(false);
        expect(isStale([], now)).toBe(false);
    });
});

describe("isNotPrinted", () => {
    const now = Date.parse("2026-11-26T10:00:40Z");

    it("is false without an entry", () => {
        expect(isNotPrinted(undefined, now)).toBe(false);
    });

    it("is true for a failed entry at once and for a young one only after 30 s", () => {
        expect(isNotPrinted({ since: "2026-11-26T10:00:39Z", state: "failed" }, now)).toBe(true);
        expect(isNotPrinted({ since: "2026-11-26T10:00:39Z", state: "queued" }, now)).toBe(false);
        expect(isNotPrinted({ since: "2026-11-26T10:00:00Z", state: "queued" }, now)).toBe(true);
    });

    it("turns true just after STALE_PRINT_MS (30 s)", () => {
        expect(STALE_PRINT_MS).toBe(30000);
        const thirtySecondsOld = { since: "2026-11-26T10:00:10Z", state: "queued" };
        expect(isNotPrinted(thirtySecondsOld, now)).toBe(false);
        expect(isNotPrinted(thirtySecondsOld, now + 1)).toBe(true);
    });
});

describe("unprintedFromJobs", () => {
    const original = (n, over = {}) => ({ key: `t${n}`, ticket: { key: `t${n}`, since: `s${n}` }, itemIds: [n], ...over });

    it("lists queued originals, marks the failed head, and leaves out cancel and manual jobs", () => {
        const jobs = [
            original(1, { lastError: { code: "transfer" } }),
            original(2),
            { key: "c1t1", kind: "cancel", ticket: { since: "x" } },
            { key: "r2-1", manual: true, ticket: { since: "y" } },
        ];
        expect(unprintedFromJobs(jobs)).toEqual([
            { key: "t1", since: "s1", state: "failed" },
            { key: "t2", since: "s2", state: "queued" },
        ]);
    });

    it("is empty for no jobs", () => {
        expect(unprintedFromJobs([])).toEqual([]);
    });
});
