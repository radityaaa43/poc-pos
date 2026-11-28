import { useCallback, useEffect, useRef, useState } from "react";
import axios from "axios";
import i18n from "@/i18n";
import { createPrintQueue } from "@/Utils/printQueue";
import { buildKitchenTicketBytes, printBytes, printerKey as keyOf } from "@/Utils/escpos";
import { STALE_PRINT_MS, classifyPrinterError, derivePrinterStatus, isStale, unprintedFromJobs, webUsbSupport } from "@/Utils/printerStatus";
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
// Item ids whose original ticket this device printed: Cetak ulang is offered for those orders only.
const PRINTED = "kitchen.printed";
// shortcut: the newest 2000 items; raise it if a shop keeps older orders open on the display.
const PRINTED_LIMIT = 2000;
const PRINT_LOCK = "kitchen-print";
const NO_JOBS = { unprinted: [], headFailed: false, lastError: null };

const readJson = (key, fallback) => {
    try {
        return JSON.parse(localStorage.getItem(key)) ?? fallback;
    } catch {
        return fallback;
    }
};
const readPrinted = () => readJson(PRINTED, []);
// Answers that end the printing: the session is gone, or the device is not this user's.
const ENDED = [401, 404, 419];

// A record call that gives no answer must fail, or every later poll would wait behind it.
const RECORD = { timeout: 10000 };

// Sends still finishing in stopped queues (auto-print off, page left) or made as reprints only. A new queue waits
// for them, so each ticket is recorded before it is listed again and only one queue ever sends. Module level: it
// has to outlive the page component.
let leaving = Promise.resolve();

// The server takes at most 200 ids per record call (the two Store*PrintsRequest rules).
const batches = (ids) => Array.from({ length: Math.ceil(ids.length / 200) }, (_, i) => ids.slice(i * 200, (i + 1) * 200));

const readUnconfirmed = () => readJson(UNCONFIRMED, []);
const writeUnconfirmed = (ids) => localStorage.setItem(UNCONFIRMED, JSON.stringify(ids));
const readUnconfirmedCancellations = () => readJson(UNCONFIRMED_CANCELLATIONS, []);
const writeUnconfirmedCancellations = (ids) => localStorage.setItem(UNCONFIRMED_CANCELLATIONS, JSON.stringify(ids));

const labelsFor = (type) => ({
    type: i18n.t(`kitchen.types.${type}`, { defaultValue: type }),
    unpaid: i18n.t("kitchen.print.unpaid"),
    addon: i18n.t("kitchen.print.addon"),
});

const buildBytes = (ticket, extraLabels) =>
    buildKitchenTicketBytes(ticket, localStorage.getItem(PAPER) || "80mm", { ...labelsFor(ticket.type), ...extraLabels });

// Same items and quantities: a queued original is only rebuilt when the kitchen would see something else.
const signature = (ticket) => ticket.items.map((item) => `${item.id}:${item.qty}`).join(",");

// Without a chosen kitchen printer nothing is sent: the first granted device may be the receipt printer.
const deviceKey = () => {
    const key = localStorage.getItem(PRINTER_KEY);
    if (!key) throw Object.assign(new Error("Printer belum terhubung."), { code: "not_connected" });
    return { deviceKey: key };
};

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

// A split shop reprints one ticket per station, so one sale can have two jobs in flight: drop only one (#345).
const dropOne = (ids, id) => {
    const at = ids.indexOf(id);
    return at === -1 ? ids : ids.filter((_, index) => index !== at);
};

const reprintTicket = (order, key) => ({
    key,
    number: order.number,
    type: order.type,
    ...(order.kind ? { kind: order.kind } : {}),
    unpaid: order.unpaid,
    since: order.since,
    ...(order.station ? { station: order.station } : {}),
    reprint: true,
    items: order.items.filter((item) => item.status !== "cancelled").map(({ title, qty, note }) => ({ title, qty, note })),
});

// Tells the server which items were printed, then forgets them locally. Throws when the POST fails,
// leaving the ids in kitchen.unconfirmed for the next tick.
async function confirm(ids) {
    const device = readJson(DEVICE, null);
    if (!device) return;
    for (const batch of batches(ids)) {
        await axios.post(route("kitchen.print-devices.prints", device.id), { item_ids: batch }, RECORD);
        writeUnconfirmed(readUnconfirmed().filter((id) => !batch.includes(id)));
    }
}

// Same as confirm() for cancellation ids; the list is kitchen.unconfirmedCancellations.
async function confirmCancellations(ids) {
    const device = readJson(DEVICE, null);
    if (!device) return;
    for (const batch of batches(ids)) {
        await axios.post(route("kitchen.print-devices.cancellation-prints", device.id), { cancellation_ids: batch }, RECORD);
        writeUnconfirmedCancellations(readUnconfirmedCancellations().filter((id) => !batch.includes(id)));
    }
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
    const [printedIds, setPrintedIds] = useState(readPrinted);
    const [reprinting, setReprinting] = useState([]);
    const [reprintFailed, setReprintFailed] = useState(null);
    const [now, setNow] = useState(() => Date.now());
    // When a ticket was last overdue; the offline-or-asleep warning is held a little after that.
    const [staleAt, setStaleAt] = useState(null);
    // No ticket fetch has succeeded for that long (AC-10, BR-4): orders may be waiting that this tab never heard of.
    const [unfetched, setUnfetched] = useState(false);
    // The queue that is polling (null while this tab does not print) and a reprint-only one for auto-print off.
    const queueRef = useRef(null);
    const reprintQueueRef = useRef(null);
    const reprintCount = useRef(0);
    const connectErrorRef = useRef(null);
    const [recordFailed, setRecordFailed] = useState(false);
    const [ended, setEnded] = useState(false);

    const setDevice = useCallback((value) => {
        // Unconfirmed ids belong to the old device: posting them to the new one would 404 and halt printing.
        if (readJson(DEVICE, null)?.id !== value?.id) {
            localStorage.removeItem(UNCONFIRMED);
            localStorage.removeItem(UNCONFIRMED_CANCELLATIONS);
            localStorage.removeItem(PRINTED);
            setPrintedIds([]);
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
        async () =>
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
    // This tab still holds the lock only to let its own send finish: that is not "another tab is printing".
    const [releasing, setReleasing] = useState(false);
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
            // Not before a send in flight is over: the tab that takes over would print that ticket too. The print
            // effect's cleanup runs after this one, hence the microtask.
            setReleasing(true);
            queueMicrotask(() =>
                leaving.finally(() => {
                    release?.();
                    setReleasing(false);
                })
            );
            setLeader(false);
        };
    }, [active, hasLocks]);

    const printing = active && leader;
    const otherTab = active && !leader && !releasing;

    // A reprint is a one-shot job: no POST, no kitchen.unconfirmed entry, dropped by the queue when it fails.
    const sendManual = useCallback(async (job) => {
        try {
            await print(job.ticket, { reprint: i18n.t("kitchen.print.reprint") });
            setDisconnected(false);
        } catch (error) {
            setReprintFailed(job.orderId);
            throw error;
        } finally {
            setReprinting((ids) => dropOne(ids, job.orderId));
        }
    }, []);

    useEffect(() => {
        if (!printing) return undefined;
        let halted = false;
        // A tab that took over the printing reads what the earlier one printed.
        setPrintedIds(readPrinted());
        // Items of tickets that are queued (or printing) and not yet in kitchen.unconfirmed.
        const queued = new Set();
        // Ids this tab has sent: a poll already in flight can still list one after Coba lagi printed and recorded it.
        const printed = new Set();
        const printedCancellations = new Set();

        const queue = createPrintQueue({
            send: async (job) => {
                if (job.manual) return sendManual(job);
                if (job.kind === "cancel") {
                    await print(job.ticket, { cancelled: i18n.t("kitchen.print.cancelled") });
                    setDisconnected(false);
                    job.cancellationIds.forEach((id) => printedCancellations.add(id));
                    writeUnconfirmedCancellations([...new Set([...readUnconfirmedCancellations(), ...job.cancellationIds])]);
                    await confirmCancellations(job.cancellationIds).catch(() => {});
                    return;
                }
                await print(job.ticket); // the queue logs a failure and keeps the job
                setDisconnected(false);
                // Record locally before the POST so a crash or a lost connection cannot double the ticket.
                writeUnconfirmed([...new Set([...readUnconfirmed(), ...job.itemIds])]);
                job.itemIds.forEach((id) => {
                    queued.delete(id);
                    printed.add(id);
                });
                const ids = [...new Set([...readPrinted(), ...job.itemIds])].slice(-PRINTED_LIMIT);
                localStorage.setItem(PRINTED, JSON.stringify(ids));
                setPrintedIds(ids);
                await confirm(job.itemIds).catch(() => {});
            },
        });

        let fetchedAt = Date.now();
        // Every second, apart from the poll: a backlog printed at once on waking still warns, and so does a hung poll.
        const clock = () => {
            const at = Date.now();
            setNow(at);
            // Offline is the connection banner's to say; the 30 s start again once the browser is back.
            if (!navigator.onLine) fetchedAt = at;
            setUnfetched(at - fetchedAt > STALE_PRINT_MS);
            if (isStale(unprintedFromJobs(queue.jobs), at)) setStaleAt(at);
        };
        const publish = () => {
            clock();
            const failed = queue.status === "failed";
            setQueueState({
                unprinted: unprintedFromJobs(queue.jobs),
                headFailed: failed,
                lastError: failed ? (queue.jobs[0]?.lastError ?? null) : null,
            });
        };
        // Reprints still waiting when the queue stops are dropped as failed, never printed minutes later.
        const dropReprints = () => {
            for (const job of queue.jobs) {
                if (job.manual && queue.remove(job.key)) {
                    setReprinting((ids) => dropOne(ids, job.orderId));
                    setReprintFailed(job.orderId);
                }
            }
        };
        const run = async () => {
            await leaving; // a stopped queue's send, or a reprint made while this one did not poll, ends first
            await queue.drain();
            if (halted) return;
            if (queue.status === "failed") dropReprints();
            publish();
        };
        // A driver lock and a device without WebUSB need the person, not another try (the button still can).
        const blocked = () =>
            webUsbSupport() !== "ok" ||
            connectErrorRef.current === "driver_locked" ||
            (queue.status === "failed" && queue.jobs[0]?.lastError?.code === "driver_locked");
        queueRef.current = { queue, run };

        const tick = async () => {
            await leaving;
            if (halted || !navigator.onLine) return;
            try {
                // A record call that fails keeps its ids for the next tick and warns; the poll still runs, and
                // the unconfirmed ids keep their tickets from printing again. A dead session ends it, as below.
                // The two record calls fail separately: one never skips the other.
                const records = await Promise.allSettled([
                    confirm(readUnconfirmed()),
                    confirmCancellations(readUnconfirmedCancellations()),
                ]);
                const failures = records.filter((record) => record.status === "rejected").map((record) => record.reason);
                const ended = failures.find((error) => ENDED.includes(error?.response?.status));
                if (ended) throw ended;
                setRecordFailed(failures.length > 0);
                const { data } = await axios.get(route("kitchen.print-devices.tickets", deviceId));
                fetchedAt = Date.now();

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

                const known = new Set([...queued, ...printed, ...readUnconfirmed()]);
                for (const ticket of data.tickets) {
                    if (ticket.item_ids.some((id) => known.has(id))) continue;
                    ticket.item_ids.forEach((id) => queued.add(id));
                    queue.enqueue({ key: ticket.key, ticket, itemIds: ticket.item_ids });
                }
                const knownCancellations = new Set([...printedCancellations, ...readUnconfirmedCancellations()]);
                for (const ticket of data.cancellations ?? []) {
                    if (ticket.cancellation_ids.some((id) => knownCancellations.has(id))) continue;
                    queue.enqueue({
                        key: ticket.key,
                        kind: "cancel",
                        ticket: { ...ticket, kind: "cancel" },
                        cancellationIds: ticket.cancellation_ids,
                    });
                }
            } catch (error) {
                // A failed poll is silent and retried; an expired session or a foreign device ends it.
                if (!halted && ENDED.includes(error?.response?.status)) {
                    halted = true;
                    queue.stop();
                    dropReprints();
                    // A reprint needs no server: from here it goes as with auto-print off, after the send in flight.
                    queueRef.current = null;
                    leaving = Promise.all([leaving, queue.drain()]);
                    setEnded(true);
                    // No publish follows a halt: a failed ticket must not keep every Cetak ulang disabled.
                    setQueueState((state) => ({ ...state, headFailed: false }));
                }
            }
            if (halted) return;
            publish();
            if (!blocked()) run();
        };

        tick();
        const timer = setInterval(tick, POLL_MS);
        const clockTimer = setInterval(clock, 1000);
        window.addEventListener("online", tick);
        return () => {
            halted = true;
            queue.stop();
            dropReprints(); // switching auto-print off must not drop a waiting reprint without saying so
            leaving = Promise.all([leaving, queue.drain()]); // settles once a send in flight is over
            clearInterval(timer);
            clearInterval(clockTimer);
            window.removeEventListener("online", tick);
            queueRef.current = null;
            setQueueState(NO_JOBS);
            setReprinting([]);
            setStaleAt(null);
            setUnfetched(false);
            setRecordFailed(false);
            setEnded(false);
        };
    }, [printing, deviceId, sendManual]);

    // A saved printer that is off at page load fires no event: look for it among the connected ones.
    useEffect(() => {
        const saved = localStorage.getItem(PRINTER_KEY);
        let live = true;
        if (saved) {
            navigator.usb
                ?.getDevices?.()
                .then((devices) => {
                    if (live && !devices.some((device) => keyOf(device) === saved)) setDisconnected(true);
                })
                .catch(() => {});
        }
        return () => {
            live = false;
        };
    }, []);

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
            // Nothing is polling: a queue of its own, taking its turn in `leaving` like a stopped queue.
            if (!reprintQueueRef.current) reprintQueueRef.current = createPrintQueue({ send: sendManual });
            reprintQueueRef.current.enqueue(job);
            leaving = leaving.then(() => reprintQueueRef.current.drain());
            return leaving;
        },
        [sendManual]
    );

    const { unprinted, headFailed, lastError } = queueState;
    const status = derivePrinterStatus({
        supported: webUsbSupport() === "ok",
        autoPrint: printing,
        errorCode: connectError ?? lastError?.code ?? (recordFailed || ended ? "record" : null),
        disconnected: disconnected || (printing && !printerKey),
        unprinted,
        wakeLock,
        now,
        staleAt,
        unfetched,
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
        printedIds,
        reprint,
        reprinting,
        reprintFailed,
        reportError,
    };
}
