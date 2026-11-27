import { useCallback, useEffect, useState } from "react";
import axios from "axios";
import i18n from "@/i18n";
import { createPrintQueue } from "@/Utils/printQueue";
import { buildKitchenTicketBytes, printBytes } from "@/Utils/escpos";

const POLL_MS = 5000;
// Per-device settings (BR-3). printerKey is a plain string because escpos.js reads it directly.
// The key keeps its old name on purpose: a device that saved its print device before the rename must not lose it.
const DEVICE = "kitchen.station";
const PRINTER_KEY = "kitchen.printerKey";
const PAPER = "kitchen.paper";
const AUTO_PRINT = "kitchen.autoPrint";
// Item ids printed but not yet confirmed to the server (print first, record after).
const UNCONFIRMED = "kitchen.unconfirmed";
// Cancellation ids (DIBATALKAN tickets) printed but not yet confirmed: the same pattern, a separate list.
const UNCONFIRMED_CANCELLATIONS = "kitchen.unconfirmedCancellations";
const PRINT_LOCK = "kitchen-print";

const readJson = (key, fallback) => {
    try {
        return JSON.parse(localStorage.getItem(key)) ?? fallback;
    } catch {
        return fallback;
    }
};
const readUnconfirmed = () => readJson(UNCONFIRMED, []);
const writeUnconfirmed = (ids) => localStorage.setItem(UNCONFIRMED, JSON.stringify(ids));
const readUnconfirmedCancellations = () => readJson(UNCONFIRMED_CANCELLATIONS, []);
const writeUnconfirmedCancellations = (ids) => localStorage.setItem(UNCONFIRMED_CANCELLATIONS, JSON.stringify(ids));

const labelsFor = (type) => ({
    type: i18n.t(`kitchen.types.${type}`, { defaultValue: type }),
    unpaid: i18n.t("kitchen.print.unpaid"),
});

const buildBytes = (ticket, extraLabels) =>
    buildKitchenTicketBytes(ticket, localStorage.getItem(PAPER) || "80mm", { ...labelsFor(ticket.type), ...extraLabels });

// Same items and quantities: a queued original is only rebuilt when the kitchen would see something else.
const signature = (ticket) => ticket.items.map((item) => `${item.id}:${item.qty}`).join(",");

const deviceKey = () => ({ deviceKey: localStorage.getItem(PRINTER_KEY) || undefined });

// Tells the server which items were printed, then forgets them locally. Throws when the POST fails,
// leaving the ids in kitchen.unconfirmed for the next tick.
async function confirm(ids) {
    const device = readJson(DEVICE, null);
    if (!device || ids.length === 0) return;
    await axios.post(route("kitchen.print-devices.prints", device.id), { item_ids: ids });
    writeUnconfirmed(readUnconfirmed().filter((id) => !ids.includes(id)));
}

// Same as confirm() for cancellation ids; the list is kitchen.unconfirmedCancellations.
async function confirmCancellations(ids) {
    const device = readJson(DEVICE, null);
    if (!device || ids.length === 0) return;
    await axios.post(route("kitchen.print-devices.cancellation-prints", device.id), { cancellation_ids: ids });
    writeUnconfirmedCancellations(readUnconfirmedCancellations().filter((id) => !ids.includes(id)));
}

export function useKitchenPrintDevice(outlet) {
    const [device, setDeviceState] = useState(() => readJson(DEVICE, null));
    const [printerKey, setPrinterKeyState] = useState(() => localStorage.getItem(PRINTER_KEY) || "");
    const [paper, setPaperState] = useState(() => localStorage.getItem(PAPER) || "80mm");
    const [autoPrint, setAutoPrintState] = useState(() => localStorage.getItem(AUTO_PRINT) === "true");

    const setDevice = useCallback((value) => {
        // Unconfirmed ids belong to the old device: posting them to the new one would 404 and halt printing.
        if (readJson(DEVICE, null)?.id !== value?.id) {
            localStorage.removeItem(UNCONFIRMED);
            localStorage.removeItem(UNCONFIRMED_CANCELLATIONS);
        }
        localStorage.setItem(DEVICE, JSON.stringify(value));
        setDeviceState(value);
    }, []);
    const setPrinterKey = useCallback((value) => {
        if (value) localStorage.setItem(PRINTER_KEY, value);
        else localStorage.removeItem(PRINTER_KEY);
        setPrinterKeyState(value);
    }, []);
    const setPaper = useCallback((value) => {
        localStorage.setItem(PAPER, value);
        setPaperState(value);
    }, []);
    const setAutoPrint = useCallback((value) => {
        localStorage.setItem(AUTO_PRINT, String(value));
        setAutoPrintState(value);
    }, []);

    const testPrint = useCallback(
        () =>
            printBytes(
                buildBytes({
                    number: "TEST",
                    type: "dine_in",
                    unpaid: false,
                    since: new Date().toISOString(),
                    items: [{ title: "Test", qty: 1, note: "" }],
                }),
                deviceKey()
            ),
        []
    );

    const active = Boolean(outlet) && Boolean(device) && autoPrint;
    const deviceId = device?.id;

    // Two tabs of one device each keep their own queue and would print a ticket twice (AC-05), so only the tab
    // holding this lock prints. A waiting tab takes over when the leader closes. Without the Web Locks API
    // (never with WebUSB in practice) every tab prints, as before.
    const hasLocks = typeof navigator !== "undefined" && Boolean(navigator.locks);
    const [leader, setLeader] = useState(!hasLocks);
    useEffect(() => {
        if (!active || !hasLocks) return undefined;
        const abort = new AbortController();
        let release;
        navigator.locks
            .request(PRINT_LOCK, { signal: abort.signal }, () => {
                setLeader(true);
                return new Promise((resolve) => {
                    release = resolve;
                });
            })
            .catch(() => {}); // aborted while waiting
        return () => {
            abort.abort();
            release?.();
            setLeader(false);
        };
    }, [active, hasLocks]);

    const printing = active && leader;
    const otherTab = active && !leader;

    useEffect(() => {
        if (!printing) return undefined;
        let halted = false;
        // Items of tickets that are queued (or printing) and not yet in kitchen.unconfirmed.
        const queued = new Set();

        const queue = createPrintQueue({
            send: async (job) => {
                if (job.kind === "cancel") {
                    await printBytes(buildBytes(job.ticket, { cancelled: i18n.t("kitchen.print.cancelled") }), deviceKey());
                    writeUnconfirmedCancellations([...new Set([...readUnconfirmedCancellations(), ...job.cancellationIds])]);
                    await confirmCancellations(job.cancellationIds).catch(() => {});
                    return;
                }
                await printBytes(buildBytes(job.ticket), deviceKey()); // the queue logs a failure and keeps the job
                // Record locally before the POST so a crash or a lost connection cannot double the ticket.
                writeUnconfirmed([...new Set([...readUnconfirmed(), ...job.itemIds])]);
                job.itemIds.forEach((id) => queued.delete(id));
                await confirm(job.itemIds).catch(() => {});
            },
        });

        const tick = async () => {
            if (halted || !navigator.onLine) return;
            try {
                await confirm(readUnconfirmed());
                await confirmCancellations(readUnconfirmedCancellations());
                const { data } = await axios.get(route("kitchen.print-devices.tickets", deviceId));

                // A job that is queued or failed (not sending) was built from an older poll: drop it when its
                // ticket left the response, rebuild it when the items or quantities changed. A job absent only
                // because of the server's cap is offered again by a later poll.
                const current = new Map(data.tickets.map((ticket) => [ticket.key, ticket]));
                for (const job of queue.jobs) {
                    if (job.kind === "cancel") continue;
                    const fresh = current.get(job.key);
                    if (!fresh) {
                        if (queue.remove(job.key)) job.itemIds.forEach((id) => queued.delete(id));
                    } else if (signature(fresh) !== signature(job.ticket)) {
                        const next = { key: fresh.key, ticket: fresh, itemIds: fresh.item_ids };
                        if (queue.replace(job.key, next)) {
                            job.itemIds.forEach((id) => queued.delete(id));
                            fresh.item_ids.forEach((id) => queued.add(id));
                        }
                    }
                }

                const known = new Set([...queued, ...readUnconfirmed()]);
                for (const ticket of data.tickets) {
                    if (ticket.item_ids.some((id) => known.has(id))) continue;
                    ticket.item_ids.forEach((id) => queued.add(id));
                    queue.enqueue({ key: ticket.key, ticket, itemIds: ticket.item_ids });
                }
                const unconfirmedCancellations = new Set(readUnconfirmedCancellations());
                for (const ticket of data.cancellations ?? []) {
                    if (ticket.cancellation_ids.some((id) => unconfirmedCancellations.has(id))) continue;
                    queue.enqueue({
                        key: ticket.key,
                        kind: "cancel",
                        ticket: { ...ticket, kind: "cancel" },
                        cancellationIds: ticket.cancellation_ids,
                    });
                }
            } catch (error) {
                // A failed poll is silent and retried; an expired session or a foreign device ends it.
                if ([401, 404, 419].includes(error?.response?.status)) halted = true;
            }
            if (!halted) queue.drain();
        };

        tick();
        const timer = setInterval(tick, POLL_MS);
        window.addEventListener("online", tick);
        return () => {
            halted = true;
            clearInterval(timer);
            window.removeEventListener("online", tick);
        };
    }, [printing, deviceId]);

    return { device, printerKey, paper, autoPrint, otherTab, setDevice, setPrinterKey, setPaper, setAutoPrint, testPrint };
}
