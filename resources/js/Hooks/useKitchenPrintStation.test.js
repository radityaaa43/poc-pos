import { act, renderHook } from "@testing-library/react";
import axios from "axios";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import i18n from "@/i18n";
import { buildKitchenTicketBytes, printBytes } from "@/Utils/escpos";
import { useKitchenPrintStation } from "./useKitchenPrintStation";

vi.mock("axios", () => ({ default: { get: vi.fn(), post: vi.fn() } }));
vi.mock("@/Utils/escpos", () => ({
    buildKitchenTicketBytes: vi.fn(() => new Uint8Array([1, 2])),
    printBytes: vi.fn(),
}));
// A minimal stand-in that follows the printQueue contract (FIFO, failed head stays, duplicate keys ignored).
vi.mock("@/Utils/printQueue", () => ({
    createPrintQueue: ({ send }) => {
        const jobs = [];
        let running = false;
        let status = "idle";
        return {
            enqueue(job) {
                if (!jobs.some((j) => j.key === job.key)) jobs.push(job);
            },
            async drain() {
                if (running) return;
                running = true;
                try {
                    while (jobs.length) {
                        try {
                            await send(jobs[0]);
                            jobs.shift();
                        } catch {
                            status = "failed";
                            return;
                        }
                    }
                    status = "idle";
                } finally {
                    running = false;
                }
            },
            get jobs() {
                return jobs;
            },
            get status() {
                return status;
            },
        };
    },
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
const unconfirmed = () => JSON.parse(localStorage.getItem("kitchen.unconfirmed") ?? "[]");
const postedIds = () => axios.post.mock.calls.map((c) => c[1].item_ids);

let onLine;
let tickets;

const flush = () => act(() => vi.advanceTimersByTimeAsync(0));
const advance = (ms) => act(() => vi.advanceTimersByTimeAsync(ms));
const mount = (o = outlet) => renderHook(() => useKitchenPrintStation(o));

beforeEach(async () => {
    vi.useFakeTimers();
    vi.clearAllMocks();
    localStorage.clear();
    await i18n.changeLanguage("id");
    onLine = true;
    vi.spyOn(navigator, "onLine", "get").mockImplementation(() => onLine);
    tickets = [ticket(1, [11, 12])];
    axios.get.mockImplementation(async () => ({ data: { tickets } }));
    axios.post.mockResolvedValue({ status: 204 });
    printBytes.mockResolvedValue(undefined);
});

afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
});

describe("useKitchenPrintStation: when it does nothing", () => {
    it("does nothing without a station", async () => {
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

describe("useKitchenPrintStation: printing a polled ticket", () => {
    it("polls the station, builds the ticket with translated labels and prints it on the stored printer", async () => {
        setStorage({ "kitchen.paper": "58mm" });
        mount();
        await flush();

        expect(axios.get).toHaveBeenCalledWith("/kitchen.stations.tickets");
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

describe("useKitchenPrintStation: never lost, never doubled", () => {
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

describe("useKitchenPrintStation: offline and unmount", () => {
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

    it("starts polling when a station is saved and auto-print is switched on", async () => {
        const { result } = mount();
        await advance(5000);
        expect(axios.get).not.toHaveBeenCalled();

        act(() => {
            result.current.setStation({ id: 5, name: "Dapur 1" });
            result.current.setAutoPrint(true);
        });
        await flush();
        expect(axios.get).toHaveBeenCalledWith("/kitchen.stations.tickets");
    });
});

describe("useKitchenPrintStation: settings", () => {
    it("reads and writes the per-device settings in localStorage", () => {
        setStorage({ "kitchen.paper": "58mm" });
        const { result } = mount();
        expect(result.current).toMatchObject({
            station: { id: 5, name: "Dapur 1" },
            printerKey: "1:2:abc",
            paper: "58mm",
            autoPrint: true,
        });

        act(() => {
            result.current.setPaper("80mm");
            result.current.setAutoPrint(false);
            result.current.setPrinterKey("");
            result.current.setStation({ id: 9, name: "Bar" });
        });
        expect(localStorage.getItem("kitchen.paper")).toBe("80mm");
        expect(localStorage.getItem("kitchen.autoPrint")).toBe("false");
        expect(localStorage.getItem("kitchen.printerKey")).toBeNull();
        expect(JSON.parse(localStorage.getItem("kitchen.station"))).toEqual({ id: 9, name: "Bar" });
    });

    it("naming another station forgets the old station's unconfirmed ids, naming the same one keeps them", () => {
        setStorage({ "kitchen.unconfirmed": "[11,12]" });
        const { result } = mount();

        act(() => result.current.setStation({ id: 5, name: "Dapur 1" }));
        expect(unconfirmed()).toEqual([11, 12]);

        act(() => result.current.setStation({ id: 9, name: "Bar" }));
        expect(unconfirmed()).toEqual([]);
    });

    it("defaults to 80 mm, auto-print off, no station", () => {
        const { result } = mount();
        expect(result.current).toMatchObject({ station: null, printerKey: "", paper: "80mm", autoPrint: false });
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
