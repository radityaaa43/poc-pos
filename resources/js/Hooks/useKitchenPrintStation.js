import { useCallback, useEffect, useState } from "react";
import axios from "axios";
import i18n from "@/i18n";
import { createPrintQueue } from "@/Utils/printQueue";
import { buildKitchenTicketBytes, printBytes } from "@/Utils/escpos";

const POLL_MS = 5000;
// Per-device settings (BR-3). printerKey is a plain string because escpos.js reads it directly.
const STATION = "kitchen.station";
const PRINTER_KEY = "kitchen.printerKey";
const PAPER = "kitchen.paper";
const AUTO_PRINT = "kitchen.autoPrint";
// Item ids printed but not yet confirmed to the server (print first, record after).
const UNCONFIRMED = "kitchen.unconfirmed";

const readJson = (key, fallback) => {
    try {
        return JSON.parse(localStorage.getItem(key)) ?? fallback;
    } catch {
        return fallback;
    }
};
const readUnconfirmed = () => readJson(UNCONFIRMED, []);
const writeUnconfirmed = (ids) => localStorage.setItem(UNCONFIRMED, JSON.stringify(ids));

const labelsFor = (type) => ({
    type: i18n.t(`kitchen.types.${type}`, { defaultValue: type }),
    unpaid: i18n.t("kitchen.print.unpaid"),
});

const buildBytes = (ticket) =>
    buildKitchenTicketBytes(ticket, localStorage.getItem(PAPER) || "80mm", labelsFor(ticket.type));

const deviceKey = () => ({ deviceKey: localStorage.getItem(PRINTER_KEY) || undefined });

// Tells the server which items were printed, then forgets them locally. Throws when the POST fails,
// leaving the ids in kitchen.unconfirmed for the next tick.
async function confirm(ids) {
    const station = readJson(STATION, null);
    if (!station || ids.length === 0) return;
    await axios.post(route("kitchen.stations.prints", station.id), { item_ids: ids });
    writeUnconfirmed(readUnconfirmed().filter((id) => !ids.includes(id)));
}

export function useKitchenPrintStation(outlet) {
    const [station, setStationState] = useState(() => readJson(STATION, null));
    const [printerKey, setPrinterKeyState] = useState(() => localStorage.getItem(PRINTER_KEY) || "");
    const [paper, setPaperState] = useState(() => localStorage.getItem(PAPER) || "80mm");
    const [autoPrint, setAutoPrintState] = useState(() => localStorage.getItem(AUTO_PRINT) === "true");

    const setStation = useCallback((value) => {
        localStorage.setItem(STATION, JSON.stringify(value));
        setStationState(value);
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

    const active = Boolean(outlet) && Boolean(station) && autoPrint;
    const stationId = station?.id;

    useEffect(() => {
        if (!active) return undefined;
        let halted = false;
        // Items of tickets that are queued (or printing) and not yet in kitchen.unconfirmed.
        const queued = new Set();

        const queue = createPrintQueue({
            send: async (job) => {
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
                const { data } = await axios.get(route("kitchen.stations.tickets", stationId));
                const known = new Set([...queued, ...readUnconfirmed()]);
                for (const ticket of data.tickets) {
                    if (ticket.item_ids.some((id) => known.has(id))) continue;
                    ticket.item_ids.forEach((id) => queued.add(id));
                    queue.enqueue({ key: ticket.key, ticket, itemIds: ticket.item_ids });
                }
            } catch (error) {
                // A failed poll is silent and retried; an expired session or a foreign station ends it.
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
    }, [active, stationId]);

    return { station, printerKey, paper, autoPrint, setStation, setPrinterKey, setPaper, setAutoPrint, testPrint };
}
