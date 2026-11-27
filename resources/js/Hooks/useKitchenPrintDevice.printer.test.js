import { act, renderHook } from "@testing-library/react";
import axios from "axios";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import i18n from "@/i18n";
import { buildKitchenTicketBytes, printBytes } from "@/Utils/escpos";
import { useKitchenPrintDevice } from "./useKitchenPrintDevice";

// REQ-004 K2: printer failures, retry and USB events. The queue is the real one; the main test file covers
// the printing flow itself.
vi.mock("axios", () => ({ default: { get: vi.fn(), post: vi.fn() } }));
vi.mock("@/Utils/escpos", () => ({
    buildKitchenTicketBytes: vi.fn(() => new Uint8Array([1, 2])),
    printBytes: vi.fn(),
    printerKey: vi.fn((device) => device.key),
}));

const outlet = { id: 1, name: "Pusat" };
const ticket = (n, ids) => ({
    key: `t${n}`,
    number: `Meja ${n}`,
    type: "dine_in",
    unpaid: n === 2,
    since: "2026-11-26T10:00:00Z",
    item_ids: ids,
    items: ids.map((id) => ({ id, title: `Item ${id}`, qty: 1, note: null })),
});
const cancelTicket = (returnId, ids) => ({
    key: `c${returnId}t1`,
    number: "Meja 1",
    type: "dine_in",
    unpaid: false,
    since: "2026-11-26T10:05:00Z",
    cancellation_ids: ids,
    items: ids.map((id) => ({ id, title: `Item ${id}`, qty: 2, note: null })),
});

const setStorage = (over = {}) => {
    localStorage.setItem("kitchen.station", JSON.stringify({ id: 5, name: "Dapur 1" }));
    localStorage.setItem("kitchen.printerKey", "1:2:abc");
    localStorage.setItem("kitchen.autoPrint", "true");
    Object.entries(over).forEach(([k, v]) => (v === null ? localStorage.removeItem(k) : localStorage.setItem(k, v)));
};
const postedIds = () => axios.post.mock.calls.filter((c) => !c[0].includes("cancellation-prints")).map((c) => c[1].item_ids);

let usb;
let tickets;
let cancellations;

const flush = () => act(() => vi.advanceTimersByTimeAsync(0));
const advance = (ms) => act(() => vi.advanceTimersByTimeAsync(ms));
const mount = (o = outlet) => renderHook(() => useKitchenPrintDevice(o));

beforeEach(async () => {
    vi.useFakeTimers();
    vi.clearAllMocks();
    localStorage.clear();
    await i18n.changeLanguage("id");
    vi.spyOn(navigator, "onLine", "get").mockReturnValue(true);
    vi.spyOn(console, "warn").mockImplementation(() => {});
    usb = new EventTarget();
    Object.defineProperty(navigator, "usb", { value: usb, configurable: true });
    tickets = [ticket(1, [11, 12])];
    cancellations = [];
    axios.get.mockImplementation(async () => ({ data: { tickets, cancellations } }));
    axios.post.mockResolvedValue({ status: 204 });
    printBytes.mockResolvedValue(undefined);
});

afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    delete navigator.usb;
});
const WINDOWS = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130";
const LINUX = "Mozilla/5.0 (X11; Linux x86_64) Chrome/130";
const fail = (code, cause) => Object.assign(new Error(code), { code, cause });
const usbEvent = (type, key) => Object.assign(new Event(type), { device: { key } });
describe("useKitchenPrintDevice: failures (REQ-004 K2)", () => {
    beforeEach(() => {
        vi.setSystemTime(new Date("2026-11-26T10:00:05Z"));
        setStorage();
    });

    it("starts clean: nothing unprinted, no error, no status", async () => {
        const { result } = mount();
        await flush();

        expect(result.current).toMatchObject({ unprinted: [], lastError: null, status: null });
    });

    it("a failed print keeps its ticket unprinted with the error code, then the next tick prints it and clears all", async () => {
        printBytes.mockRejectedValueOnce(fail("transfer"));
        const { result } = mount();
        await flush();

        expect(result.current.unprinted).toEqual([{ key: "t1", since: "2026-11-26T10:00:00Z", state: "failed" }]);
        expect(result.current.lastError).toMatchObject({ code: "transfer" });
        expect(result.current.status).toBe("failed");

        await advance(5000);

        expect(printBytes).toHaveBeenCalledTimes(2);
        expect(result.current).toMatchObject({ unprinted: [], lastError: null, status: null });
        expect(postedIds()).toEqual([[11, 12]]);
    });

    it("tickets behind the failed head are queued, and the console gets the key and the code", async () => {
        tickets = [ticket(1, [11]), ticket(2, [21])];
        printBytes.mockRejectedValue(fail("transfer"));
        const { result } = mount();
        await flush();

        expect(result.current.unprinted.map((u) => [u.key, u.state])).toEqual([
            ["t1", "failed"],
            ["t2", "queued"],
        ]);
        expect(console.warn).toHaveBeenCalledWith("kitchen print failed", "t1", "transfer");
    });

    it("a not_connected failure shows not_connected", async () => {
        printBytes.mockRejectedValue(fail("not_connected"));
        const { result } = mount();
        await flush();

        expect(result.current.lastError.code).toBe("not_connected");
        expect(result.current.status).toBe("not_connected");
    });

    it("a claim error is driver_locked on Windows and a plain failure elsewhere", async () => {
        printBytes.mockRejectedValue(fail("claim_failed", new DOMException("busy", "NetworkError")));
        const ua = vi.spyOn(navigator, "userAgent", "get").mockReturnValue(WINDOWS);
        const win = mount();
        await flush();
        expect(win.result.current.lastError.code).toBe("driver_locked");
        expect(win.result.current.status).toBe("driver_locked");
        win.unmount();

        ua.mockReturnValue(LINUX);
        const linux = mount();
        await flush();
        expect(linux.result.current.lastError.code).toBe("claim_failed");
        expect(linux.result.current.status).toBe("failed");
    });

    it("never retries a driver lock on its own, but the button still can", async () => {
        printBytes.mockRejectedValue(fail("claim_failed", new DOMException("busy", "NetworkError")));
        vi.spyOn(navigator, "userAgent", "get").mockReturnValue(WINDOWS);
        const { result } = mount();
        await flush();
        await advance(15000);

        expect(printBytes).toHaveBeenCalledTimes(1);
        expect(axios.get.mock.calls.length).toBeGreaterThan(1);

        await act(() => result.current.retry());
        expect(printBytes).toHaveBeenCalledTimes(2);
    });

    it("does not print without WebUSB, and says unsupported", async () => {
        delete navigator.usb;
        const { result } = mount();
        await advance(10000);

        expect(printBytes).not.toHaveBeenCalled();
        expect(result.current.status).toBe("unsupported");
    });

    it("does not print on an insecure page either", async () => {
        vi.stubGlobal("isSecureContext", false);
        const { result } = mount();
        await advance(10000);

        expect(printBytes).not.toHaveBeenCalled();
        expect(result.current.status).toBe("unsupported");
        vi.unstubAllGlobals();
    });

    it("retry() sends the failed head once, in order, and a second tap while it runs sends nothing more", async () => {
        tickets = [ticket(1, [11]), ticket(2, [21])];
        printBytes.mockRejectedValueOnce(fail("transfer"));
        const { result } = mount();
        await flush();

        let release;
        printBytes.mockImplementation(() => new Promise((resolve) => (release = resolve)));
        let first;
        let second;
        await act(async () => {
            first = result.current.retry();
            second = result.current.retry();
        });
        expect(printBytes).toHaveBeenCalledTimes(2);

        printBytes.mockResolvedValue(undefined);
        await act(async () => {
            release();
            await Promise.all([first, second]);
        });

        expect(buildKitchenTicketBytes.mock.calls.map((c) => c[0].key)).toEqual(["t1", "t1", "t2"]);
        expect(postedIds()).toEqual([[11], [21]]);
        expect(result.current.unprinted).toEqual([]);
    });

    it("retry() does nothing while auto-print is off", async () => {
        setStorage({ "kitchen.autoPrint": "false" });
        const { result } = mount();

        await act(() => result.current.retry());

        expect(printBytes).not.toHaveBeenCalled();
    });

    it("a failed cancel ticket stops the queue as a failure but is not an unprinted order", async () => {
        tickets = [];
        cancellations = [cancelTicket(7, [31])];
        printBytes.mockRejectedValue(fail("transfer"));
        const { result } = mount();
        await flush();

        expect(result.current.status).toBe("failed");
        expect(result.current.lastError).toMatchObject({ code: "transfer" });
        expect(result.current.unprinted).toEqual([]);
    });

    it("lists a ticket that is still sending as queued, so its card can age into Belum tercetak, until it prints", async () => {
        let release;
        printBytes.mockImplementation(() => new Promise((resolve) => (release = resolve)));
        const { result } = mount();
        await flush();

        expect(result.current.unprinted).toEqual([{ key: "t1", since: "2026-11-26T10:00:00Z", state: "queued" }]);
        expect(result.current.status).toBeNull();

        tickets = [];
        await act(async () => release());
        expect(result.current.unprinted).toEqual([]);
    });
});

describe("useKitchenPrintDevice: a poll already in flight (REQ-004 K2, BR-2)", () => {
    beforeEach(() => {
        vi.setSystemTime(new Date("2026-11-26T10:00:05Z"));
        setStorage();
    });

    // Coba lagi can send and record a ticket while a poll's request is still open; its stale answer lists the ids again.
    it.each([
        ["order ticket", [ticket(1, [11, 12])], []],
        ["cancel ticket", [], [cancelTicket(3, [31])]],
    ])("never prints the same %s twice when the stale answer arrives after Coba lagi recorded it", async (_name, orders, cancels) => {
        tickets = orders;
        cancellations = cancels;
        printBytes.mockRejectedValueOnce(fail("transfer"));
        const { result } = mount();
        await flush();
        let answer;
        axios.get.mockImplementationOnce(() => new Promise((resolve) => (answer = resolve)));
        await advance(5000);

        await act(() => result.current.retry());
        expect(printBytes).toHaveBeenCalledTimes(2);

        await act(async () => {
            answer({ data: { tickets: orders, cancellations: cancels } });
            await vi.advanceTimersByTimeAsync(0);
        });
        expect(printBytes).toHaveBeenCalledTimes(2);
    });
});

describe("useKitchenPrintDevice: USB events and connect errors (REQ-004 K2)", () => {
    beforeEach(() => {
        vi.setSystemTime(new Date("2026-11-26T10:00:05Z"));
        setStorage();
        tickets = [];
    });

    it("shows not_connected at once on a disconnect of the saved printer, and clears on connect", async () => {
        const { result } = mount();
        await flush();

        act(() => usb.dispatchEvent(usbEvent("disconnect", "1:2:abc")));
        expect(result.current.status).toBe("not_connected");

        act(() => usb.dispatchEvent(usbEvent("connect", "1:2:abc")));
        expect(result.current.status).toBeNull();
    });

    it("ignores another USB device", async () => {
        const { result } = mount();
        await flush();

        act(() => usb.dispatchEvent(usbEvent("disconnect", "9:9:zzz")));

        expect(result.current.status).toBeNull();
    });

    it("a reconnect retries the ticket that failed while the printer was away", async () => {
        tickets = [ticket(1, [11])];
        printBytes.mockRejectedValueOnce(fail("not_connected"));
        const { result } = mount();
        await flush();
        expect(result.current.status).toBe("not_connected");

        await act(async () => {
            usb.dispatchEvent(usbEvent("connect", "1:2:abc"));
            await vi.advanceTimersByTimeAsync(0);
        });

        expect(printBytes).toHaveBeenCalledTimes(2);
        expect(result.current.status).toBeNull();
    });

    it("stays quiet about USB events while auto-print is off", async () => {
        setStorage({ "kitchen.autoPrint": "false" });
        const { result } = mount();

        act(() => usb.dispatchEvent(usbEvent("disconnect", "1:2:abc")));

        expect(result.current.status).toBeNull();
    });

    it("says not_connected when auto-print is on and no printer is connected", async () => {
        setStorage({ "kitchen.printerKey": null });
        const { result } = mount();
        await flush();

        expect(result.current.status).toBe("not_connected");
    });

    it("stops listening for USB events on unmount", async () => {
        const add = vi.spyOn(usb, "addEventListener");
        const remove = vi.spyOn(usb, "removeEventListener");
        const { unmount } = mount();
        unmount();

        const added = add.mock.calls.find((c) => c[0] === "disconnect");
        expect(added).toBeDefined();
        expect(remove).toHaveBeenCalledWith("disconnect", added[1]);
    });

    it("reportError() turns a Windows claim error from the connect button into driver_locked, even with auto-print off", async () => {
        setStorage({ "kitchen.autoPrint": "false" });
        vi.spyOn(navigator, "userAgent", "get").mockReturnValue(WINDOWS);
        const { result } = mount();

        act(() => result.current.reportError(fail("claim_failed", new DOMException("busy", "NetworkError"))));
        expect(result.current.status).toBe("driver_locked");

        act(() => result.current.reportError(null));
        expect(result.current.status).toBeNull();
    });

    it("reportError() keeps only the driver lock, and a good connect retries the stuck ticket", async () => {
        tickets = [ticket(1, [11])];
        printBytes.mockRejectedValueOnce(fail("transfer"));
        const { result } = mount();
        await flush();

        act(() => result.current.reportError(fail("claim_failed", new DOMException("busy", "NetworkError"))));
        expect(result.current.status).toBe("failed"); // Linux user agent: not the WinUSB case, and not kept

        await act(async () => {
            result.current.reportError(null);
            await vi.advanceTimersByTimeAsync(0);
        });
        expect(printBytes).toHaveBeenCalledTimes(2);
        expect(result.current.status).toBeNull();
    });
});

describe("useKitchenPrintDevice: print defects (#410)", () => {
    beforeEach(() => setStorage());

    it("D1: sends and records nothing without a chosen kitchen printer", async () => {
        setStorage({ "kitchen.printerKey": null });
        const { result } = mount();
        await flush();

        expect(printBytes).not.toHaveBeenCalled();
        expect(postedIds()).toEqual([]);
        expect(result.current.status).toBe("not_connected");
        await expect(result.current.testPrint()).rejects.toMatchObject({ code: "not_connected" });
    });

    it("D2: switching auto-print off stops the backlog", async () => {
        tickets = [ticket(1, [11]), ticket(2, [21])];
        let release;
        printBytes.mockImplementationOnce(() => new Promise((resolve) => (release = resolve)));
        const { result } = mount();
        await flush();

        act(() => result.current.setAutoPrint(false));
        await act(async () => {
            release();
            await vi.advanceTimersByTimeAsync(10000);
        });

        expect(printBytes).toHaveBeenCalledTimes(1);
    });

    it("D2: off and on again during a slow send prints each ticket once", async () => {
        tickets = [ticket(1, [11]), ticket(2, [21])];
        let release;
        printBytes.mockImplementationOnce(() => new Promise((resolve) => (release = resolve)));
        const { result } = mount();
        await flush();

        act(() => result.current.setAutoPrint(false));
        act(() => result.current.setAutoPrint(true));
        await advance(10000);
        expect(printBytes).toHaveBeenCalledTimes(1); // the new queue waits for the send in flight
        tickets = [ticket(2, [21])]; // the server stops listing what gets recorded
        await act(async () => {
            release();
            await vi.advanceTimersByTimeAsync(0);
        });

        expect(printBytes).toHaveBeenCalledTimes(2);
    });

    it("D2: off, on, off and on again during one slow send still prints it once", async () => {
        tickets = [ticket(1, [11])];
        let release;
        printBytes.mockImplementationOnce(() => new Promise((resolve) => (release = resolve)));
        const { result } = mount();
        await flush();

        for (const on of [false, true, false, true]) act(() => result.current.setAutoPrint(on));
        await advance(10000);
        tickets = [];
        await act(async () => {
            release();
            await vi.advanceTimersByTimeAsync(0);
        });

        expect(printBytes).toHaveBeenCalledTimes(1);
    });

    it("D2: leaving the page during a send and coming back prints it once", async () => {
        tickets = [ticket(1, [11])];
        let release;
        printBytes.mockImplementationOnce(() => new Promise((resolve) => (release = resolve)));
        mount().unmount();
        const first = mount();
        await flush();
        first.unmount();
        mount();
        await advance(10000);
        tickets = [];
        await act(async () => {
            release?.();
            await vi.advanceTimersByTimeAsync(0);
        });

        expect(printBytes).toHaveBeenCalledTimes(1);
    });

    it("warns when the session has expired and printing stops", async () => {
        axios.get.mockRejectedValue({ response: { status: 401 } });
        const { result } = mount();
        await flush();

        expect(result.current.status).toBe("failed");
    });

    it("D4: records a large backlog in calls of at most 200 ids", async () => {
        const ids = Array.from({ length: 450 }, (_, i) => i + 1);
        localStorage.setItem("kitchen.unconfirmed", JSON.stringify(ids));
        localStorage.setItem("kitchen.unconfirmedCancellations", JSON.stringify(ids.slice(0, 201)));
        tickets = [];
        mount();
        await flush();

        expect(postedIds().map((batch) => batch.length)).toEqual([200, 200, 50]);
        const cancelled = axios.post.mock.calls.filter((call) => call[0].includes("cancellation-prints"));
        expect(cancelled.map((call) => call[1].cancellation_ids.length)).toEqual([200, 1]);
        expect(localStorage.getItem("kitchen.unconfirmed")).toBe("[]");
    });

    it("D4: a refused record call keeps the ids, still prints new tickets, and warns", async () => {
        localStorage.setItem("kitchen.unconfirmed", JSON.stringify([99]));
        axios.post.mockRejectedValue({ response: { status: 422 } });
        tickets = [ticket(1, [11])];
        const { result } = mount();
        await flush();

        expect(printBytes).toHaveBeenCalledTimes(1);
        expect(JSON.parse(localStorage.getItem("kitchen.unconfirmed"))).toEqual([99, 11]);
        expect(result.current.status).toBe("failed");
    });

    it.each([
        ["not among the connected printers", [], "not_connected"],
        ["connected", [{ key: "1:2:abc" }], null],
    ])("D5: at page load, a saved printer that is %s shows the right status", async (_case, devices, status) => {
        usb.getDevices = vi.fn().mockResolvedValue(devices);
        tickets = [];
        const { result } = mount();
        await flush();

        expect(result.current.status).toBe(status);
    });
});
