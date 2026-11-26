import { useCallback, useEffect, useRef, useState } from "react";
import axios from "axios";
import i18n from "@/i18n";
import { createPrintQueue } from "@/Utils/printQueue";
import { buildKitchenTicketBytes, printBytes, printerKey as keyOf } from "@/Utils/escpos";
import { classifyPrinterError, derivePrinterStatus, isStale, unprintedFromJobs, webUsbSupport } from "@/Utils/printerStatus";
import { useWakeLock } from "@/Hooks/useWakeLock";

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
const NO_JOBS = { unprinted: [], headFailed: false, lastError: null };

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

// The queue and the status read `error.code`: classify here, where the user agent is known.
const classified = (error) =>
    Object.assign(new Error(error?.message, { cause: error }), { code: classifyPrinterError(error, navigator.userAgent) });

async function print(ticket, extraLabels) {
    try {
        await printBytes(buildBytes(ticket, extraLabels), deviceKey());
    } catch (error) {
        throw classified(error);
    }
}

// A reprint ticket is built from the card: same items, minus the cancelled ones, marked reprint.
const reprintTicket = (order, key) => ({
    key,
    number: order.number,
    type: order.type,
    unpaid: order.unpaid,
    since: order.since,
    reprint: true,
    items: order.items.filter((item) => item.status !== "cancelled").map(({ title, qty, note }) => ({ title, qty, note })),
});

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

    // Printer health (REQ-004 K2). queueState mirrors the live queue; the rest is what the page needs to tell
    // the kitchen why nothing prints.
    const [queueState, setQueueState] = useState(NO_JOBS);
    const [connectError, setConnectError] = useState(null);
    const [disconnected, setDisconnected] = useState(false);
    const [reprinting, setReprinting] = useState([]);
    const [reprintFailed, setReprintFailed] = useState(null);
    const [now, setNow] = useState(() => Date.now());
    // The queue that is polling (null while this tab does not print) and a reprint-only one for auto-print off.
    const queueRef = useRef(null);
    const reprintQueueRef = useRef(null);
    const connectErrorRef = useRef(null);
    const reprintCount = useRef(0);
    const staleAt = useRef(null);

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
        setDisconnected(false);
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

    // A reprint is a one-shot job: no POST, no kitchen.unconfirmed entry, dropped by the queue when it fails.
    const sendManual = useCallback(async (job) => {
        try {
            await print(job.ticket, { reprint: i18n.t("kitchen.print.reprint") });
            setDisconnected(false);
        } catch (error) {
            setReprintFailed(job.orderId);
            throw error;
        } finally {
            setReprinting((ids) => ids.filter((id) => id !== job.orderId));
        }
    }, []);

    useEffect(() => {
        if (!printing) return undefined;
        let halted = false;
        // Items of tickets that are queued (or printing) and not yet in kitchen.unconfirmed.
        const queued = new Set();

        const queue = createPrintQueue({
            send: async (job) => {
                if (job.manual) return sendManual(job);
                if (job.kind === "cancel") {
                    await print(job.ticket, { cancelled: i18n.t("kitchen.print.cancelled") });
                    setDisconnected(false);
                    writeUnconfirmedCancellations([...new Set([...readUnconfirmedCancellations(), ...job.cancellationIds])]);
                    await confirmCancellations(job.cancellationIds).catch(() => {});
                    return;
                }
                await print(job.ticket); // the queue logs a failure and keeps the job
                setDisconnected(false);
                // Record locally before the POST so a crash or a lost connection cannot double the ticket.
                writeUnconfirmed([...new Set([...readUnconfirmed(), ...job.itemIds])]);
                job.itemIds.forEach((id) => queued.delete(id));
                await confirm(job.itemIds).catch(() => {});
            },
        });

        const publish = () => {
            const failed = queue.status === "failed";
            setQueueState({
                unprinted: unprintedFromJobs(queue.jobs),
                headFailed: failed,
                lastError: failed ? (queue.jobs[0]?.lastError ?? null) : null,
            });
        };
        const run = async () => {
            await queue.drain();
            // A reprint is a one-shot tap: if the queue stopped on a failed ticket, the reprints queued behind it are
            // dropped (the button re-enables with the failure shown), not printed minutes later when the head recovers.
            if (queue.status === "failed") {
                for (const job of queue.jobs.slice(1)) {
                    if (job.manual && queue.remove(job.key)) {
                        setReprinting((ids) => ids.filter((id) => id !== job.orderId));
                        setReprintFailed(job.orderId);
                    }
                }
            }
            publish();
        };
        // A driver lock and a device without WebUSB need the person, not another try (the button still can).
        const blocked = () =>
            webUsbSupport() !== "ok" ||
            connectErrorRef.current === "driver_locked" ||
            (queue.status === "failed" && queue.jobs[0]?.lastError?.code === "driver_locked");
        queueRef.current = { queue, run };

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
                    if (job.kind === "cancel" || job.manual) continue;
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
            if (halted) return;
            publish();
            if (!blocked()) run();
        };

        tick();
        const timer = setInterval(tick, POLL_MS);
        window.addEventListener("online", tick);
        return () => {
            halted = true;
            clearInterval(timer);
            window.removeEventListener("online", tick);
            queueRef.current = null;
            setQueueState(NO_JOBS);
            setReprinting([]);
        };
    }, [printing, deviceId, sendManual]);

    // The page's 1 s clock for the stale warning, only while this tab prints.
    useEffect(() => {
        if (!printing) return undefined;
        const timer = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(timer);
    }, [printing]);

    // The printer's own plug and unplug events: not_connected at once, before a ticket has to fail.
    useEffect(() => {
        const usb = navigator.usb;
        if (!usb) return undefined;
        const mine = (event) => !printerKey || !event.device || keyOf(event.device) === printerKey;
        const onDisconnect = (event) => {
            if (mine(event)) setDisconnected(true);
        };
        const onConnect = (event) => {
            if (!mine(event)) return;
            setDisconnected(false);
            // A printer that re-appears (also after a driver swap) deserves a fresh try.
            connectErrorRef.current = null;
            setConnectError(null);
            queueRef.current?.run();
        };
        usb.addEventListener("disconnect", onDisconnect);
        usb.addEventListener("connect", onConnect);
        return () => {
            usb.removeEventListener("disconnect", onDisconnect);
            usb.removeEventListener("connect", onConnect);
        };
    }, [printerKey]);

    const wakeLock = useWakeLock(active);

    // The button and the poll feed one queue, so a double tap cannot send twice.
    const retry = useCallback(() => queueRef.current?.run() ?? Promise.resolve(), []);

    // The connect button reports its error here: only a driver lock is kept (the panel shows the steps);
    // a good connect clears it and gives a stuck ticket another try.
    const reportError = useCallback((error) => {
        const code = error ? classifyPrinterError(error, navigator.userAgent) : null;
        const locked = code === "driver_locked" ? code : null;
        connectErrorRef.current = locked;
        setConnectError(locked);
        if (!error) {
            setDisconnected(false);
            queueRef.current?.run();
        }
    }, []);

    const reprint = useCallback(
        (order) => {
            reprintCount.current += 1;
            const key = `r${order.id}-${reprintCount.current}`;
            const job = { key, manual: true, orderId: order.id, ticket: reprintTicket(order, key) };
            setReprintFailed(null);
            setReprinting((ids) => [...ids, order.id]);
            if (queueRef.current) {
                queueRef.current.queue.enqueue(job);
                return queueRef.current.run();
            }
            // Auto-print off: nothing is polling, so a queue of its own keeps one send at a time.
            if (!reprintQueueRef.current) reprintQueueRef.current = createPrintQueue({ send: sendManual });
            reprintQueueRef.current.enqueue(job);
            return reprintQueueRef.current.drain();
        },
        [sendManual]
    );

    const { unprinted, headFailed, lastError } = queueState;
    // shortcut: the stale hold lives in a ref written during render (a monotone timestamp, so StrictMode
    // re-renders agree); move it into the 1 s ticker if the React compiler lint ever objects.
    if (printing && isStale(unprinted, now)) staleAt.current = now;
    const status = derivePrinterStatus({
        supported: webUsbSupport() === "ok",
        autoPrint: printing,
        errorCode: connectError ?? lastError?.code ?? null,
        disconnected: disconnected || (printing && !printerKey),
        unprinted,
        wakeLock,
        now,
        staleAt: staleAt.current,
    });

    return {
        device,
        printerKey,
        paper,
        autoPrint,
        otherTab,
        setDevice,
        setPrinterKey,
        setPaper,
        setAutoPrint,
        testPrint,
        unprinted,
        lastError,
        headFailed,
        status,
        retry,
        reprint,
        reprinting,
        reprintFailed,
        reportError,
    };
}
