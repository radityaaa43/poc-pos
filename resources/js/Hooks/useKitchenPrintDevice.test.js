import { act, renderHook } from "@testing-library/react";
import axios from "axios";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import i18n from "@/i18n";
import { buildKitchenTicketBytes, printBytes } from "@/Utils/escpos";
import { useKitchenPrintDevice } from "./useKitchenPrintDevice";

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

const setStorage = (over = {}) => {
    localStorage.setItem("kitchen.station", JSON.stringify({ id: 5, name: "Dapur 1" }));
    localStorage.setItem("kitchen.printerKey", "1:2:abc");
    localStorage.setItem("kitchen.autoPrint", "true");
    Object.entries(over).forEach(([k, v]) => (v === null ? localStorage.removeItem(k) : localStorage.setItem(k, v)));
};
const cancelTicket = (returnId, ids, over = {}) => ({
    key: `c${returnId}t1`,
    number: "Meja 1",
    type: "dine_in",
    unpaid: false,
    since: "2026-11-26T10:05:00Z",
    cancellation_ids: ids,
    items: ids.map((id) => ({ id, title: `Item ${id}`, qty: 2, note: "tanpa pedas" })),
    ...over,
});
const unconfirmedCancellations = () => JSON.parse(localStorage.getItem("kitchen.unconfirmedCancellations") ?? "[]");
const cancelPosts = () => axios.post.mock.calls.filter((c) => c[0].includes("cancellation-prints"));
const itemPosts = () => axios.post.mock.calls.filter((c) => !c[0].includes("cancellation-prints"));
const unconfirmed = () => JSON.parse(localStorage.getItem("kitchen.unconfirmed") ?? "[]");
const postedIds = () => itemPosts().map((c) => c[1].item_ids);

let onLine;
let usb;
let wakeLock;
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
    onLine = true;
    vi.spyOn(navigator, "onLine", "get").mockImplementation(() => onLine);
    vi.spyOn(console, "warn").mockImplementation(() => {});
    // WebUSB and Wake Lock exist unless a test removes them; the hook skips printing without WebUSB.
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

describe("useKitchenPrintDevice: when it does nothing", () => {
    it("does nothing without a device", async () => {
        setStorage({ "kitchen.station": null });
        mount();
        await advance(10000);
        expect(axios.get).not.toHaveBeenCalled();
        expect(axios.post).not.toHaveBeenCalled();
    });

    it("does nothing while auto-print is off", async () => {
        setStorage({ "kitchen.autoPrint": "false" });
        mount();
        await advance(10000);
        expect(axios.get).not.toHaveBeenCalled();
    });

    it("does nothing without an outlet", async () => {
        setStorage();
        mount(null);
        await advance(10000);
        expect(axios.get).not.toHaveBeenCalled();
    });

    it("stops after the session expires (401)", async () => {
        setStorage();
        axios.get.mockRejectedValue({ response: { status: 401 } });
        mount();
        await flush();
        await advance(15000);
        expect(axios.get).toHaveBeenCalledTimes(1);
    });
});

describe("useKitchenPrintDevice: printing a polled ticket", () => {
    it("polls the device, builds the ticket with translated labels and prints it on the stored printer", async () => {
        setStorage({ "kitchen.paper": "58mm" });
        mount();
        await flush();

        expect(axios.get).toHaveBeenCalledWith("/kitchen.print-devices.tickets");
        expect(buildKitchenTicketBytes).toHaveBeenCalledWith(
            expect.objectContaining({ number: "Meja 1", type: "dine_in", unpaid: false }),
            "58mm",
            { type: "Dine-in", unpaid: "BELUM LUNAS" }
        );
        expect(printBytes).toHaveBeenCalledWith(new Uint8Array([1, 2]), { deviceKey: "1:2:abc" });
        expect(postedIds()).toEqual([[11, 12]]);
        expect(unconfirmed()).toEqual([]);
    });

    it("defaults to 80 mm", async () => {
        setStorage();
        mount();
        await flush();
        expect(buildKitchenTicketBytes.mock.calls[0][1]).toBe("80mm");
    });

    it("prints several tickets one at a time in the order received", async () => {
        setStorage();
        tickets = [ticket(1, [11]), ticket(2, [21])];
        mount();
        await flush();
        expect(buildKitchenTicketBytes.mock.calls.map((c) => c[0].number)).toEqual(["Meja 1", "Meja 2"]);
        expect(postedIds()).toEqual([[11], [21]]);
    });

    it("prints first and records after: the ids are in kitchen.unconfirmed when the POST starts", async () => {
        setStorage();
        const order = [];
        printBytes.mockImplementation(async () => order.push("print"));
        axios.post.mockImplementation(async () => {
            order.push(`post:${JSON.stringify(unconfirmed())}`);
        });
        mount();
        await flush();
        expect(order).toEqual(["print", "post:[11,12]"]);
        expect(unconfirmed()).toEqual([]);
    });
});

describe("useKitchenPrintDevice: never lost, never doubled", () => {
    it("a failed print records nothing and the next tick retries it once", async () => {
        setStorage();
        printBytes.mockRejectedValueOnce(new Error("usb"));
        mount();
        await flush();

        expect(axios.post).not.toHaveBeenCalled();
        expect(unconfirmed()).toEqual([]);

        await advance(5000);
        expect(printBytes).toHaveBeenCalledTimes(2);
        expect(postedIds()).toEqual([[11, 12]]);
    });

    it("a failed POST leaves the ids unconfirmed; the next tick re-posts them first and does not print again", async () => {
        setStorage();
        axios.post.mockRejectedValueOnce(new Error("offline"));
        mount();
        await flush();
        expect(unconfirmed()).toEqual([11, 12]);

        const calls = [];
        axios.get.mockImplementation(async () => {
            calls.push("get");
            return { data: { tickets } };
        });
        axios.post.mockImplementation(async () => {
            calls.push("post");
            tickets = []; // the server no longer offers what was just recorded
        });
        await advance(5000);

        expect(calls).toEqual(["post", "get"]);
        expect(printBytes).toHaveBeenCalledTimes(1);
        expect(unconfirmed()).toEqual([]);
    });

    it("re-posts ids left over from a previous load before asking for tickets", async () => {
        setStorage({ "kitchen.unconfirmed": "[11,12]" });
        const calls = [];
        axios.post.mockImplementation(async (url, body) => {
            calls.push(["post", body.item_ids]);
            tickets = [];
        });
        axios.get.mockImplementation(async () => {
            calls.push(["get"]);
            return { data: { tickets } };
        });
        mount();
        await flush();

        expect(calls).toEqual([["post", [11, 12]], ["get"]]);
        expect(printBytes).not.toHaveBeenCalled();
        expect(unconfirmed()).toEqual([]);
    });

    it("an overlapping poll does not queue a ticket that is still printing", async () => {
        setStorage();
        let release;
        printBytes.mockImplementation(() => new Promise((resolve) => (release = resolve)));
        mount();
        await flush();
        await advance(5000);
        await advance(5000);
        expect(axios.get).toHaveBeenCalledTimes(3);
        expect(printBytes).toHaveBeenCalledTimes(1);

        await act(async () => release());
        expect(printBytes).toHaveBeenCalledTimes(1);
        expect(postedIds()).toEqual([[11, 12]]);
    });
});

describe("useKitchenPrintDevice: offline and unmount", () => {
    it("posts and polls nothing while offline, and resumes when the browser is back online", async () => {
        setStorage();
        onLine = false;
        mount();
        await advance(10000);
        expect(axios.get).not.toHaveBeenCalled();
        expect(axios.post).not.toHaveBeenCalled();

        onLine = true;
        await act(async () => window.dispatchEvent(new Event("online")));
        await flush();
        expect(axios.get).toHaveBeenCalledTimes(1);
        expect(postedIds()).toEqual([[11, 12]]);
    });

    it("clears its interval and listener on unmount", async () => {
        setStorage();
        const { unmount } = mount();
        await flush();
        unmount();
        axios.get.mockClear();
        await advance(10000);
        await act(async () => window.dispatchEvent(new Event("online")));
        expect(axios.get).not.toHaveBeenCalled();
    });

    it("starts polling when a device is saved and auto-print is switched on", async () => {
        const { result } = mount();
        await advance(5000);
        expect(axios.get).not.toHaveBeenCalled();

        act(() => {
            result.current.setDevice({ id: 5, name: "Dapur 1" });
            result.current.setAutoPrint(true);
        });
        await flush();
        expect(axios.get).toHaveBeenCalledWith("/kitchen.print-devices.tickets");
    });
});

describe("useKitchenPrintDevice: settings", () => {
    it("keeps a print device saved before the rename (same storage keys, #366)", () => {
        // What a browser stored while the code still said "station": these literal key names must keep working.
        localStorage.clear();
        localStorage.setItem("kitchen.station", JSON.stringify({ id: 7, name: "Dapur lama" }));
        localStorage.setItem("kitchen.unconfirmed", JSON.stringify([31, 32]));

        const { result } = mount();

        expect(result.current.device).toEqual({ id: 7, name: "Dapur lama" });
        expect(unconfirmed()).toEqual([31, 32]);
    });

    it("reads and writes the per-device settings in localStorage", () => {
        setStorage({ "kitchen.paper": "58mm" });
        const { result } = mount();
        expect(result.current).toMatchObject({
            device: { id: 5, name: "Dapur 1" },
            printerKey: "1:2:abc",
            paper: "58mm",
            autoPrint: true,
        });

        act(() => {
            result.current.setPaper("80mm");
            result.current.setAutoPrint(false);
            result.current.setPrinterKey("");
            result.current.setDevice({ id: 9, name: "Bar" });
        });
        expect(localStorage.getItem("kitchen.paper")).toBe("80mm");
        expect(localStorage.getItem("kitchen.autoPrint")).toBe("false");
        expect(localStorage.getItem("kitchen.printerKey")).toBeNull();
        expect(JSON.parse(localStorage.getItem("kitchen.station"))).toEqual({ id: 9, name: "Bar" });
    });

    it("naming another device forgets the old device's unconfirmed ids, naming the same one keeps them", () => {
        setStorage({ "kitchen.unconfirmed": "[11,12]" });
        const { result } = mount();

        act(() => result.current.setDevice({ id: 5, name: "Dapur 1" }));
        expect(unconfirmed()).toEqual([11, 12]);

        act(() => result.current.setDevice({ id: 9, name: "Bar" }));
        expect(unconfirmed()).toEqual([]);
    });

    it("defaults to 80 mm, auto-print off, no device", () => {
        const { result } = mount();
        expect(result.current).toMatchObject({ device: null, printerKey: "", paper: "80mm", autoPrint: false });
    });

    it("test print sends a TEST ticket in the chosen width to the stored printer", async () => {
        setStorage({ "kitchen.paper": "58mm" });
        const { result } = mount();
        await result.current.testPrint();

        expect(buildKitchenTicketBytes).toHaveBeenCalledWith(
            expect.objectContaining({ number: "TEST", items: [expect.objectContaining({ qty: 1 })] }),
            "58mm",
            expect.any(Object)
        );
        expect(printBytes).toHaveBeenCalledWith(new Uint8Array([1, 2]), { deviceKey: "1:2:abc" });
        expect(axios.post).not.toHaveBeenCalled();
    });
});

// A stand-in for navigator.locks: exclusive per name, waiters in order, an abort rejects a waiting request.
const fakeLocks = () => {
    const state = { held: false, queue: [] };
    const next = () => {
        if (state.held || state.queue.length === 0) return;
        const entry = state.queue.shift();
        state.held = true;
        Promise.resolve(entry.callback())
            .then(entry.resolve, entry.reject)
            .finally(() => {
                state.held = false;
                next();
            });
    };
    const request = vi.fn(
        (name, options, callback) =>
            new Promise((resolve, reject) => {
                const entry = { callback, resolve, reject };
                options.signal?.addEventListener("abort", () => {
                    const at = state.queue.indexOf(entry);
                    if (at < 0) return;
                    state.queue.splice(at, 1);
                    reject(new DOMException("aborted", "AbortError"));
                });
                state.queue.push(entry);
                next();
            })
    );

    return { state, request };
};

describe("useKitchenPrintDevice: one tab prints per device", () => {
    let locks;

    beforeEach(() => {
        locks = fakeLocks();
        Object.defineProperty(navigator, "locks", { value: locks, configurable: true });
        setStorage();
    });

    afterEach(() => {
        delete navigator.locks;
    });

    it("a tab without the lock never polls or prints, and says another tab prints", async () => {
        locks.request("kitchen-print", {}, () => new Promise(() => {})); // another tab is the leader
        const { result } = mount();
        await advance(15000);

        expect(result.current.otherTab).toBe(true);
        expect(axios.get).not.toHaveBeenCalled();
        expect(printBytes).not.toHaveBeenCalled();
    });

    it("starts the loop when the lock is granted", async () => {
        const { result } = mount();
        await flush();

        expect(locks.request).toHaveBeenCalledWith("kitchen-print", expect.objectContaining({ signal: expect.anything() }), expect.any(Function));
        expect(result.current.otherTab).toBe(false);
        expect(printBytes).toHaveBeenCalledTimes(1);
    });

    it("does not blame another tab while its own send is still finishing after off and on", async () => {
        let release;
        printBytes.mockImplementationOnce(() => new Promise((resolve) => (release = resolve)));
        const { result } = mount();
        await flush();

        act(() => result.current.setAutoPrint(false));
        act(() => result.current.setAutoPrint(true));
        await flush();
        expect(result.current.otherTab).toBe(false);

        await act(async () => {
            release();
            await vi.advanceTimersByTimeAsync(0);
        });
        expect(result.current.otherTab).toBe(false);
        expect(locks.request).toHaveBeenCalledTimes(2);
    });

    it("unmount releases the lock, and a waiting tab's cleanup aborts its request", async () => {
        const leader = mount();
        await flush();
        const waiting = mount();
        await flush();
        expect(waiting.result.current.otherTab).toBe(true);
        expect(locks.state.queue).toHaveLength(1);

        waiting.unmount();
        expect(locks.state.queue).toHaveLength(0);
        expect(locks.state.held).toBe(true); // the leader still holds it

        leader.unmount();
        await flush();
        expect(locks.state.held).toBe(false);
    });

    it("turning auto-print off releases the lock", async () => {
        const { result } = mount();
        await flush();
        expect(locks.state.held).toBe(true);

        act(() => result.current.setAutoPrint(false));
        await flush();
        expect(locks.state.held).toBe(false);
    });

    it("two tabs share one lock: a ticket prints once, and the other tab takes over when the leader closes", async () => {
        axios.post.mockImplementation(async () => {
            tickets = []; // the server stops offering a ticket once it is confirmed
            return { status: 204 };
        });
        const first = mount();
        await flush();
        const second = mount();
        await advance(10000);

        expect(printBytes).toHaveBeenCalledTimes(1);
        expect(second.result.current.otherTab).toBe(true);

        first.unmount();
        tickets = [ticket(2, [21])];
        await advance(5000);
        expect(second.result.current.otherTab).toBe(false);
        expect(printBytes).toHaveBeenCalledTimes(2);
    });
});

describe("useKitchenPrintDevice: cancel tickets (DIBATALKAN)", () => {
    beforeEach(() => {
        setStorage();
        tickets = [];
    });

    it("prints a cancel ticket with the cancelled label, then posts its ids to the cancellation route", async () => {
        cancellations = [cancelTicket(7, [71, 72])];
        mount();
        await flush();

        expect(buildKitchenTicketBytes).toHaveBeenCalledWith(
            expect.objectContaining({ kind: "cancel", key: "c7t1", number: "Meja 1", items: expect.any(Array) }),
            "80mm",
            { type: "Dine-in", unpaid: "BELUM LUNAS", cancelled: "DIBATALKAN" }
        );
        expect(printBytes).toHaveBeenCalledWith(new Uint8Array([1, 2]), { deviceKey: "1:2:abc" });
        expect(cancelPosts()).toEqual([
            ["/kitchen.print-devices.cancellation-prints", { cancellation_ids: [71, 72] }, { timeout: 10000 }],
        ]);
        expect(itemPosts()).toEqual([]);
        expect(unconfirmedCancellations()).toEqual([]);
    });

    it("uses the English label on an English device", async () => {
        await i18n.changeLanguage("en");
        cancellations = [cancelTicket(7, [71])];
        mount();
        await flush();
        expect(buildKitchenTicketBytes.mock.calls[0][2].cancelled).toBe("CANCELLED");
    });

    it("prints first and records after: the ids are in kitchen.unconfirmedCancellations when the POST starts", async () => {
        cancellations = [cancelTicket(7, [71, 72])];
        const order = [];
        printBytes.mockImplementation(async () => order.push("print"));
        axios.post.mockImplementation(async () => {
            order.push(`post:${JSON.stringify(unconfirmedCancellations())}`);
        });
        mount();
        await flush();
        expect(order).toEqual(["print", "post:[71,72]"]);
        expect(unconfirmedCancellations()).toEqual([]);
    });

    it("a cancel ticket waits its turn behind an original ticket (FIFO)", async () => {
        tickets = [ticket(1, [11])];
        cancellations = [cancelTicket(7, [71])];
        mount();
        await flush();
        expect(buildKitchenTicketBytes.mock.calls.map((c) => c[0].kind ?? "original")).toEqual(["original", "cancel"]);
        expect(axios.post.mock.calls.map((c) => c[0])).toEqual([
            "/kitchen.print-devices.prints",
            "/kitchen.print-devices.cancellation-prints",
        ]);
    });

    it("does not queue a cancel twice while it is still printing (overlapping polls)", async () => {
        cancellations = [cancelTicket(7, [71])];
        let release;
        printBytes.mockImplementation(() => new Promise((resolve) => (release = resolve)));
        mount();
        await flush();
        await advance(5000);
        await advance(5000);
        expect(axios.get).toHaveBeenCalledTimes(3);
        expect(printBytes).toHaveBeenCalledTimes(1);

        await act(async () => release());
        expect(printBytes).toHaveBeenCalledTimes(1);
        expect(cancelPosts()).toHaveLength(1);
    });

    it("does not queue a cancel whose ids are in kitchen.unconfirmedCancellations", async () => {
        cancellations = [cancelTicket(7, [71]), cancelTicket(8, [81])];
        // Another run recorded 81 while this poll was in flight.
        axios.get.mockImplementation(async () => {
            localStorage.setItem("kitchen.unconfirmedCancellations", "[81]");
            return { data: { tickets, cancellations } };
        });
        mount();
        await flush();
        expect(buildKitchenTicketBytes.mock.calls.map((c) => c[0].key)).toEqual(["c7t1"]);
    });

    it("re-posts kitchen.unconfirmedCancellations before asking for tickets, and does not print them again", async () => {
        setStorage({ "kitchen.unconfirmedCancellations": "[71,72]" });
        const calls = [];
        axios.post.mockImplementation(async (url, body) => {
            calls.push(["post", url, body]);
            cancellations = [];
        });
        axios.get.mockImplementation(async () => {
            calls.push(["get"]);
            return { data: { tickets, cancellations } };
        });
        cancellations = [cancelTicket(7, [71, 72])];
        mount();
        await flush();

        expect(calls).toEqual([
            ["post", "/kitchen.print-devices.cancellation-prints", { cancellation_ids: [71, 72] }],
            ["get"],
        ]);
        expect(printBytes).not.toHaveBeenCalled();
        expect(unconfirmedCancellations()).toEqual([]);
    });

    it("a failed POST leaves the ids unconfirmed; the next tick re-posts them and does not print again", async () => {
        cancellations = [cancelTicket(7, [71, 72])];
        axios.post.mockRejectedValueOnce(new Error("offline"));
        mount();
        await flush();
        expect(unconfirmedCancellations()).toEqual([71, 72]);
        expect(printBytes).toHaveBeenCalledTimes(1);

        // The repeat POST succeeds, so the server stops offering the cancel.
        axios.post.mockImplementation(async () => {
            cancellations = [];
        });
        await advance(5000);

        expect(printBytes).toHaveBeenCalledTimes(1);
        expect(cancelPosts()).toHaveLength(2);
        expect(unconfirmedCancellations()).toEqual([]);
    });

    it("a reload (remount) never prints a cancel twice while its ids are unconfirmed", async () => {
        cancellations = [cancelTicket(7, [71])];
        axios.post.mockRejectedValue(new Error("offline"));
        const first = mount();
        await flush();
        expect(printBytes).toHaveBeenCalledTimes(1);
        first.unmount();

        mount(); // a fresh page: new queue, same localStorage
        await flush();
        await advance(10000);

        expect(printBytes).toHaveBeenCalledTimes(1);
        expect(unconfirmedCancellations()).toEqual([71]);
    });

    it("a failed print records nothing and the next tick retries the cancel", async () => {
        cancellations = [cancelTicket(7, [71])];
        printBytes.mockRejectedValueOnce(new Error("usb"));
        mount();
        await flush();
        expect(unconfirmedCancellations()).toEqual([]);
        expect(cancelPosts()).toEqual([]);

        await advance(5000);
        expect(printBytes).toHaveBeenCalledTimes(2);
        expect(cancelPosts()).toHaveLength(1);
    });

    it("works with an old server that sends no cancellations field", async () => {
        axios.get.mockImplementation(async () => ({ data: { tickets: [ticket(1, [11])] } }));
        mount();
        await flush();
        expect(postedIds()).toEqual([[11]]);
        expect(cancelPosts()).toEqual([]);
    });

    it("naming another device forgets the old device's unconfirmed cancellations, naming the same one keeps them", () => {
        setStorage({ "kitchen.unconfirmedCancellations": "[71,72]" });
        const { result } = mount();

        act(() => result.current.setDevice({ id: 5, name: "Dapur 1" }));
        expect(unconfirmedCancellations()).toEqual([71, 72]);

        act(() => result.current.setDevice({ id: 9, name: "Bar" }));
        expect(localStorage.getItem("kitchen.unconfirmedCancellations")).toBeNull();
    });
});

describe("useKitchenPrintDevice: reconciling queued originals with the poll", () => {
    let release;
    // The first print hangs, so ticket 1 is the sending head and everything else waits behind it.
    const blockHead = () => {
        printBytes.mockImplementationOnce(() => new Promise((resolve) => (release = resolve)));
    };
    const printedNumbers = () => buildKitchenTicketBytes.mock.calls.map((c) => c[0].number);

    beforeEach(() => setStorage());

    it("drops a queued original whose key left the response, and never prints or posts it", async () => {
        tickets = [ticket(1, [11]), ticket(2, [21])];
        blockHead();
        mount();
        await flush();

        tickets = [ticket(1, [11])]; // ticket 2 was cancelled or served meanwhile
        await advance(5000);
        await act(async () => release());

        expect(printedNumbers()).toEqual(["Meja 1"]);
        expect(postedIds()).toEqual([[11]]);
        expect(unconfirmed()).toEqual([]);
    });

    it("replaces a queued original whose items changed, keeping its place", async () => {
        tickets = [ticket(1, [11]), ticket(2, [21, 22]), ticket(3, [31])];
        blockHead();
        mount();
        await flush();

        tickets = [ticket(1, [11]), ticket(2, [21]), ticket(3, [31])]; // item 22 was cancelled
        await advance(5000);
        await act(async () => release());

        expect(printedNumbers()).toEqual(["Meja 1", "Meja 2", "Meja 3"]);
        expect(buildKitchenTicketBytes.mock.calls[1][0].items.map((i) => i.id)).toEqual([21]);
        expect(postedIds()).toEqual([[11], [21], [31]]);
    });

    it("replaces a queued original whose quantity changed (a partial return)", async () => {
        tickets = [ticket(1, [11]), ticket(2, [21])];
        blockHead();
        mount();
        await flush();

        const lowered = ticket(2, [21]);
        lowered.items[0].qty = 3;
        tickets = [ticket(1, [11]), lowered];
        await advance(5000);
        await act(async () => release());

        expect(buildKitchenTicketBytes.mock.calls[1][0].items[0].qty).toBe(3);
    });

    it("keeps the head that is sending even when its key left the response", async () => {
        tickets = [ticket(1, [11])];
        blockHead();
        mount();
        await flush();

        tickets = [];
        await advance(5000);
        await act(async () => release());

        expect(printedNumbers()).toEqual(["Meja 1"]);
        expect(postedIds()).toEqual([[11]]);
    });

    it("drops a failed head whose key left the response, and the queue moves on", async () => {
        tickets = [ticket(1, [11]), ticket(2, [21])];
        printBytes.mockRejectedValueOnce(new Error("usb"));
        mount();
        await flush();
        expect(printBytes).toHaveBeenCalledTimes(1);

        tickets = [ticket(2, [21])];
        await advance(5000);

        expect(printedNumbers()).toEqual(["Meja 1", "Meja 2"]);
        expect(postedIds()).toEqual([[21]]);
    });

    it("reconciles nothing after a failed poll", async () => {
        tickets = [ticket(1, [11]), ticket(2, [21])];
        blockHead();
        mount();
        await flush();

        axios.get.mockRejectedValue({ response: { status: 500 } });
        await advance(5000);
        axios.get.mockImplementation(async () => ({ data: { tickets, cancellations } }));
        await act(async () => release());

        expect(printedNumbers()).toEqual(["Meja 1", "Meja 2"]);
        expect(postedIds()).toEqual([[11], [21]]);
    });

    it("offers a dropped job again when it reappears (the server caps a poll at 30 orders)", async () => {
        tickets = [ticket(1, [11]), ticket(2, [21])];
        blockHead();
        mount();
        await flush();

        tickets = [ticket(1, [11])]; // pushed out of the capped response
        await advance(5000);
        tickets = [ticket(1, [11]), ticket(2, [21])]; // and back
        await advance(5000);
        await act(async () => release());

        expect(printedNumbers()).toEqual(["Meja 1", "Meja 2"]);
        expect(postedIds()).toEqual([[11], [21]]);
    });

    it("does not reconcile cancel jobs: a queued cancel survives a response that no longer lists it", async () => {
        tickets = [ticket(1, [11])];
        cancellations = [cancelTicket(7, [71])];
        blockHead();
        mount();
        await flush();

        cancellations = [];
        await advance(5000);
        await act(async () => release());

        expect(buildKitchenTicketBytes.mock.calls.map((c) => c[0].key)).toEqual(["t1", "c7t1"]);
        expect(cancelPosts()).toHaveLength(1);
    });
});

describe("useKitchenPrintDevice: a reprint keeps the print safety rules (#410)", () => {
    const order = { id: 7, number: "Meja 4", type: "dine_in", unpaid: false, since: "2026-11-26T09:50:00Z", items: [] };
    let release;
    const sent = () => buildKitchenTicketBytes.mock.calls.map((c) => c[0].key);
    const holdFirstSend = () => printBytes.mockImplementationOnce(() => new Promise((resolve) => (release = resolve)));
    const tap = (result) => act(async () => void result.current.reprint(order));
    const finishSend = () =>
        act(async () => {
            release();
            await vi.advanceTimersByTimeAsync(0);
        });

    it("sends nothing without a chosen kitchen printer, and names the order", async () => {
        setStorage({ "kitchen.printerKey": null, "kitchen.autoPrint": "false" });
        const { result } = mount();

        await act(() => result.current.reprint(order));

        expect(printBytes).not.toHaveBeenCalled();
        expect(result.current.reprintFailed).toBe(7);
    });

    it.each([
        ["off", [false]],
        ["off and on again", [false, true]],
    ])("waits for the send in flight after auto-print is switched %s", async (_case, switches) => {
        setStorage();
        holdFirstSend();
        const { result } = mount();
        await flush();
        for (const on of switches) act(() => result.current.setAutoPrint(on));

        await tap(result);
        expect(sent()).toEqual(["t1"]);

        tickets = [];
        await finishSend();
        expect(sent()).toEqual(["t1", "r7-1"]);
    });

    it("holds the queue back while a reprint started with auto-print off is still sending", async () => {
        setStorage({ "kitchen.autoPrint": "false" });
        holdFirstSend();
        const { result } = mount();
        await tap(result);

        act(() => result.current.setAutoPrint(true));
        await advance(10000);
        expect(sent()).toEqual(["r7-1"]);

        await finishSend();
        expect(sent()).toEqual(["r7-1", "t1"]);
    });

    it("after the session ended: drops the queued reprint with its failure, and a new one prints after the send in flight", async () => {
        setStorage();
        holdFirstSend();
        const { result } = mount();
        await flush();
        await tap(result); // queued behind the original
        axios.get.mockRejectedValue({ response: { status: 401 } });
        await advance(5000);

        expect(result.current.reprinting).toEqual([]);
        expect(result.current.reprintFailed).toBe(7);

        await tap(result);
        expect(sent()).toEqual(["t1"]);

        await finishSend();
        expect(sent()).toEqual(["t1", "r7-2"]);
        expect(result.current.reprinting).toEqual([]);
    });
});
