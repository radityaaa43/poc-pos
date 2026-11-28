import { act, renderHook } from "@testing-library/react";
import axios from "axios";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import i18n from "@/i18n";
import { buildKitchenTicketBytes, printBytes } from "@/Utils/escpos";
import { useKitchenPrintDevice } from "./useKitchenPrintDevice";

// REQ-004 K2: printer failures, retry, reprint, USB events and Wake Lock. The queue is the real one; the main test file covers
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
const unconfirmed = () => JSON.parse(localStorage.getItem("kitchen.unconfirmed") ?? "[]");
const unconfirmedCancellations = () => JSON.parse(localStorage.getItem("kitchen.unconfirmedCancellations") ?? "[]");
const printedHere = () => JSON.parse(localStorage.getItem("kitchen.printed") ?? "[]");
const postedIds = () => axios.post.mock.calls.filter((c) => !c[0].includes("cancellation-prints")).map((c) => c[1].item_ids);

let usb;
let wakeLock;
let tickets;
let cancellations;

const flush = () => act(() => vi.advanceTimersByTimeAsync(0));
const advance = (ms) => act(() => vi.advanceTimersByTimeAsync(ms));
const mount = (o = outlet) => renderHook(() => useKitchenPrintDevice(o));
// Mounted, with the first poll answered.
const mounted = async () => {
    const hook = mount();
    await flush();
    return hook;
};

beforeEach(async () => {
    vi.useFakeTimers();
    vi.clearAllMocks();
    localStorage.clear();
    await i18n.changeLanguage("id");
    vi.spyOn(navigator, "onLine", "get").mockReturnValue(true);
    vi.spyOn(console, "warn").mockImplementation(() => {});
    usb = new EventTarget();
    Object.defineProperty(navigator, "usb", { value: usb, configurable: true });
    wakeLock = { request: vi.fn(async () => ({ released: false, release: vi.fn(), addEventListener: vi.fn() })) };
    Object.defineProperty(navigator, "wakeLock", { value: wakeLock, configurable: true });
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
    delete navigator.wakeLock;
});
const WINDOWS = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130";
const LINUX = "Mozilla/5.0 (X11; Linux x86_64) Chrome/130";
const fail = (code, cause) => Object.assign(new Error(code), { code, cause });
const usbEvent = (type, key) => Object.assign(new Event(type), { device: { key } });
const order = {
    id: 7,
    number: "Meja 4",
    type: "dine_in",
    unpaid: true,
    since: "2026-11-26T09:50:00Z",
    items: [
        { id: 1, title: "Nasi Goreng", qty: 2, note: "tanpa pedas", status: "accepted", station_id: 3 },
        { id: 2, title: "Es Teh", qty: 1, note: null, status: "cancelled", station_id: 3 },
    ],
};

describe("useKitchenPrintDevice: failures (REQ-004 K2)", () => {
    beforeEach(() => {
        vi.setSystemTime(new Date("2026-11-26T10:00:05Z"));
        setStorage();
    });

    it("starts clean: nothing unprinted, no error, no status", async () => {
        const { result } = mount();
        await flush();

        expect(result.current).toMatchObject({ unprinted: [], lastError: null, status: null, headFailed: false });
    });

    it("a failed print keeps its ticket unprinted with the error code, then the next tick prints it and clears all", async () => {
        printBytes.mockRejectedValueOnce(fail("transfer"));
        const { result } = mount();
        await flush();

        expect(result.current.unprinted).toEqual([{ key: "t1", since: "2026-11-26T10:00:00Z", state: "failed" }]);
        expect(result.current.lastError).toMatchObject({ code: "transfer" });
        expect(result.current.status).toBe("failed");
        expect(result.current.headFailed).toBe(true);

        await advance(5000);

        expect(printBytes).toHaveBeenCalledTimes(2);
        expect(result.current).toMatchObject({ unprinted: [], lastError: null, status: null, headFailed: false });
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

describe("useKitchenPrintDevice: reprint (REQ-004 K2)", () => {
    beforeEach(() => {
        vi.setSystemTime(new Date("2026-11-26T10:00:05Z"));
        setStorage({ "kitchen.autoPrint": "false" });
        tickets = [];
    });

    it.each([
        ["id", { type: "Dine-in", unpaid: "BELUM LUNAS", reprint: "CETAK ULANG", addon: "TAMBAHAN" }],
        ["en", { type: "Dine-in", unpaid: "UNPAID", reprint: "REPRINT", addon: "ADD-ON" }],
    ])("in %s, prints a marked reprint on the stored printer, with no request, nothing recorded", async (lang, labels) => {
        await i18n.changeLanguage(lang);
        const { result } = mount();

        await act(() => result.current.reprint(order));

        expect(buildKitchenTicketBytes).toHaveBeenCalledWith(
            {
                key: "r7-1",
                number: "Meja 4",
                type: "dine_in",
                unpaid: true,
                since: "2026-11-26T09:50:00Z",
                reprint: true,
                items: [{ title: "Nasi Goreng", qty: 2, note: "tanpa pedas" }],
            },
            "80mm",
            labels
        );
        expect(printBytes).toHaveBeenCalledWith(new Uint8Array([1, 2]), { deviceKey: "1:2:abc" });
        expect(axios.post).not.toHaveBeenCalled();
        expect(axios.get).not.toHaveBeenCalled();
        expect(unconfirmed()).toEqual([]);
        expect(unconfirmedCancellations()).toEqual([]);
    });

    it("titles a station reprint with the station name (#345)", async () => {
        await i18n.changeLanguage("id");
        const { result } = mount();

        await act(() => result.current.reprint({ ...order, station: "Bar" }));

        expect(buildKitchenTicketBytes).toHaveBeenCalledWith(
            expect.objectContaining({ station: "Bar", reprint: true }),
            "80mm",
            expect.any(Object)
        );
    });

    it("can be repeated: every tap prints, under its own key", async () => {
        const { result } = mount();

        await act(() => result.current.reprint(order));
        await act(() => result.current.reprint(order));

        expect(printBytes).toHaveBeenCalledTimes(2);
        expect(buildKitchenTicketBytes.mock.calls.map((c) => c[0].key)).toEqual(["r7-1", "r7-2"]);
        expect(axios.post).not.toHaveBeenCalled();
    });

    // A split shop reprints once per station: the card stays busy until the second ticket is out (#345).
    it("keeps the order busy while a second station ticket still prints", async () => {
        setStorage();
        tickets = [];
        const sends = [];
        printBytes.mockImplementation(() => new Promise((resolve, reject) => sends.push({ resolve, reject })));
        const { result } = mount();
        await flush();

        await act(async () => {
            result.current.reprint({ ...order, station: "Bar" });
            result.current.reprint({ ...order, station: "Dapur" });
        });

        expect(result.current.reprinting).toEqual([order.id, order.id]);

        sends[0].resolve();
        await flush();
        expect(result.current.reprinting).toEqual([order.id]);

        sends[1].resolve();
        await flush();
        expect(result.current.reprinting).toEqual([]);
    });

    // Auto-print on, the original still being sent, Cetak ulang tapped behind it. Each send waits to be settled.
    const reprintBehindOriginal = async () => {
        setStorage();
        tickets = [ticket(1, [11, 12])];
        const sends = [];
        printBytes.mockImplementation(() => new Promise((resolve, reject) => sends.push({ resolve, reject })));
        const { result } = mount();
        await flush();
        expect(printBytes).toHaveBeenCalledTimes(1);
        let done;
        await act(async () => {
            done = result.current.reprint(order);
        });
        return { result, sends, done };
    };

    it("while auto-print is on, goes into the same queue after the tickets already queued, and records nothing", async () => {
        const { result, sends, done } = await reprintBehindOriginal();
        expect(printBytes).toHaveBeenCalledTimes(1); // one send at a time: the original goes first
        expect(result.current.reprinting).toEqual([7]);

        tickets = [];
        await act(async () => {
            sends[0].resolve();
            await vi.advanceTimersByTimeAsync(0);
        });
        await act(async () => {
            sends[1].resolve();
            await done;
        });

        expect(buildKitchenTicketBytes.mock.calls.map((c) => c[0].key)).toEqual(["t1", "r7-1"]);
        expect(postedIds()).toEqual([[11, 12]]);
        expect(unconfirmed()).toEqual([]);
        expect(result.current.reprinting).toEqual([]);
    });

    it("drops a reprint queued behind an original that then fails: it never prints later, and its order is named", async () => {
        const { result, sends, done } = await reprintBehindOriginal();
        expect(result.current.reprinting).toEqual([7]);

        await act(async () => {
            sends[0].reject(fail("transfer"));
            await vi.advanceTimersByTimeAsync(0);
            await done;
        });

        expect(result.current.headFailed).toBe(true);
        expect(result.current.reprinting).toEqual([]);
        expect(result.current.reprintFailed).toBe(7);
        expect(buildKitchenTicketBytes.mock.calls.map((c) => c[0].key)).toEqual(["t1"]);

        // The printer is back: only the original prints, the forgotten reprint does not come back.
        printBytes.mockResolvedValue(undefined);
        await advance(5000);
        expect(buildKitchenTicketBytes.mock.calls.map((c) => c[0].key)).toEqual(["t1", "t1"]);
    });

    it("tracks the order being reprinted until it is done", async () => {
        let release;
        printBytes.mockImplementation(() => new Promise((resolve) => (release = resolve)));
        const { result } = mount();

        let done;
        await act(async () => {
            done = result.current.reprint(order);
        });
        expect(result.current.reprinting).toEqual([7]);

        await act(async () => {
            release();
            await done;
        });
        expect(result.current.reprinting).toEqual([]);
        expect(result.current.reprintFailed).toBeNull();
    });

    it("drops a failed reprint, names its order, and does not block the queue or raise a status", async () => {
        setStorage();
        printBytes.mockRejectedValueOnce(fail("transfer"));
        const { result } = await mounted();

        await act(() => result.current.reprint(order));

        expect(result.current.reprintFailed).toBe(7);
        expect(result.current.reprinting).toEqual([]);
        expect(result.current.headFailed).toBe(false);
        expect(result.current.status).toBeNull();

        await advance(5000);
        expect(printBytes).toHaveBeenCalledTimes(1); // nothing retries it later
    });

    it("clears the failure mark when another reprint starts", async () => {
        printBytes.mockRejectedValueOnce(fail("transfer"));
        const { result } = mount();
        await act(() => result.current.reprint(order));
        expect(result.current.reprintFailed).toBe(7);

        await act(() => result.current.reprint(order));

        expect(result.current.reprintFailed).toBeNull();
    });
});

describe("useKitchenPrintDevice: what this device printed (REQ-004 K2, decision 12)", () => {
    beforeEach(() => {
        vi.setSystemTime(new Date("2026-11-26T10:00:05Z"));
        setStorage();
    });

    it("keeps the item ids of a ticket that went to the printer, also after a reload, even if the record fails", async () => {
        axios.post.mockRejectedValue(new Error("offline"));
        const first = await mounted();

        expect(first.result.current.printedIds).toEqual([11, 12]);
        first.unmount();
        expect(mount().result.current.printedIds).toEqual([11, 12]);
    });

    it("keeps nothing for a failed ticket, a cancel ticket, a test print or a reprint", async () => {
        printBytes.mockRejectedValueOnce(fail("transfer"));
        const { result } = await mounted();
        expect(result.current.printedIds).toEqual([]);

        setStorage({ "kitchen.autoPrint": "false" });
        act(() => result.current.setAutoPrint(false));
        await act(() => result.current.testPrint());
        await act(() => result.current.reprint(order));
        tickets = [];
        cancellations = [cancelTicket(3, [31])];
        await act(async () => result.current.setAutoPrint(true));
        await flush();

        expect(printBytes).toHaveBeenCalledTimes(4);
        expect(result.current.printedIds).toEqual([]);
        expect(printedHere()).toEqual([]);
    });

    it("keeps only the newest 2000 ids", async () => {
        localStorage.setItem("kitchen.printed", JSON.stringify(Array.from({ length: 2000 }, (_, i) => 1000 + i)));
        const { result } = await mounted();

        expect(result.current.printedIds).toHaveLength(2000);
        expect(result.current.printedIds.slice(-3)).toEqual([2999, 11, 12]);
        expect(result.current.printedIds[0]).toBe(1002);
    });

    it("forgets them when the device becomes another print device", async () => {
        const { result } = await mounted();

        act(() => result.current.setDevice({ id: 6, name: "Dapur 2" }));

        expect(result.current.printedIds).toEqual([]);
        expect(printedHere()).toEqual([]);
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

describe("useKitchenPrintDevice: Wake Lock and the stale warning (REQ-004 K2)", () => {
    beforeEach(() => {
        vi.setSystemTime(new Date("2026-11-26T10:00:05Z"));
        setStorage();
        tickets = [];
    });

    it("keeps the screen awake while auto-print is on and lets go when it is switched off", async () => {
        const release = vi.fn();
        wakeLock.request.mockResolvedValue({ released: false, release, addEventListener: vi.fn() });
        const { result } = await mounted();
        expect(wakeLock.request).toHaveBeenCalledWith("screen");
        expect(result.current.status).toBeNull();

        act(() => result.current.setAutoPrint(false));

        expect(release).toHaveBeenCalled();
    });

    it("does not ask for the screen lock while auto-print is off", async () => {
        setStorage({ "kitchen.autoPrint": "false" });
        mount();
        await flush();

        expect(wakeLock.request).not.toHaveBeenCalled();
    });

    it("warns when the screen lock is refused or the browser has none", async () => {
        wakeLock.request.mockRejectedValue(new DOMException("no", "NotAllowedError"));
        const refused = await mounted();
        expect(refused.result.current.status).toBe("wake_lock_off");
        refused.unmount();

        delete navigator.wakeLock;
        const none = await mounted();
        expect(none.result.current.status).toBe("wake_lock_off");
    });

    it("shows stale when an unprinted ticket is older than 30 s, and holds it 15 s after the backlog printed", async () => {
        vi.setSystemTime(new Date("2026-11-26T10:00:40Z")); // the ticket is 40 s old
        tickets = [ticket(1, [11, 12])];
        let release;
        printBytes.mockImplementation(() => new Promise((resolve) => (release = resolve)));
        const { result } = await mounted();
        await advance(1000);
        expect(result.current.status).toBe("stale");

        tickets = [];
        printBytes.mockResolvedValue(undefined);
        await act(async () => release());
        expect(result.current.unprinted).toEqual([]);
        expect(result.current.status).toBe("stale");

        await advance(10000);
        expect(result.current.status).toBe("stale");

        await advance(6000);
        expect(result.current.status).toBeNull();
    });

    it("is not stale for a young ticket", async () => {
        let release;
        printBytes.mockImplementation(() => new Promise((resolve) => (release = resolve)));
        tickets = [ticket(1, [11])];
        const { result } = await mounted();
        await advance(2000);

        expect(result.current.unprinted).toHaveLength(1);
        expect(result.current.status).toBeNull();
        // A real send always settles (15 s timeout); one left hanging would hold back every later queue.
        await act(async () => release());
    });

    it("prints the orders that waited during sleep oldest first, once each, and still warns when that is instant", async () => {
        vi.setSystemTime(new Date("2026-11-26T10:00:40Z"));
        tickets = [ticket(1, [11]), ticket(2, [21])];
        const { result } = await mounted();

        expect(buildKitchenTicketBytes.mock.calls.map((c) => c[0].key)).toEqual(["t1", "t2"]);
        expect(result.current.unprinted).toEqual([]);
        expect(result.current.status).toBe("stale");

        // The server may still list them (a record in flight): nothing prints a second time, and the warning goes.
        await advance(20000);
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

    it("D4: a failing ticket record does not hold back the cancellation record", async () => {
        localStorage.setItem("kitchen.unconfirmed", "[11]");
        localStorage.setItem("kitchen.unconfirmedCancellations", "[7]");
        tickets = [];
        axios.post.mockImplementation(async (url) => {
            if (!url.includes("cancellation-prints")) throw new Error("offline");
            return { status: 204 };
        });
        mount();
        await flush();

        expect(axios.post.mock.calls.map((call) => call[1].cancellation_ids).filter(Boolean)).toEqual([[7]]);
    });

    it("D6: a claim that never answers fails the send, and printing resumes after off and on", async () => {
        const { printBytes: send } = await vi.importActual("@/Utils/escpos");
        printBytes.mockImplementation(send);
        tickets = [ticket(1, [11])];
        const device = {
            key: "1:2:abc",
            vendorId: 1,
            productId: 2,
            serialNumber: "abc",
            opened: false,
            configuration: {
                interfaces: [
                    { interfaceNumber: 0, alternates: [{ interfaceClass: 7, endpoints: [{ direction: "out", endpointNumber: 2 }] }] },
                ],
            },
            open: vi.fn(async () => (device.opened = true)),
            close: vi.fn(async () => (device.opened = false)),
            claimInterface: vi.fn(() => new Promise(() => {})),
            transferOut: vi.fn().mockResolvedValue({ status: "ok" }),
        };
        usb.getDevices = vi.fn().mockResolvedValue([device]);
        const { result } = mount();
        await flush();

        act(() => result.current.setAutoPrint(false));
        act(() => result.current.setAutoPrint(true));
        await advance(35000);

        expect(device.claimInterface.mock.calls.length).toBeGreaterThan(1);
        expect(result.current.status).toBe("failed");
        device.claimInterface.mockResolvedValue(undefined);
        await advance(30000);

        expect(postedIds()).toEqual([[11]]);
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
