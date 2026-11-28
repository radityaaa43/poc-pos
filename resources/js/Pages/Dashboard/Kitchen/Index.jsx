import React, { useEffect, useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, router, usePoll } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import { IconPrinter, IconWifiOff } from "@tabler/icons-react";
import toast from "react-hot-toast";
import { useAuthorization } from "@/Utils/authorization";
import { useKitchenPrintDevice } from "@/Hooks/useKitchenPrintDevice";
import { isNotPrinted } from "@/Utils/printerStatus";
import OrderCard from "@/Components/Kitchen/OrderCard";
import PrinterStatusBanner from "@/Components/Kitchen/PrinterStatusBanner";
import ReadyForPickup from "@/Components/Kitchen/ReadyForPickup";
import PrintDevicePanel from "./PrintDevicePanel";

const POLL_MS = 5000;
const STALE_MS = 15000;
const STORAGE_KEY = "kitchenStations";
const STEP_BUTTON =
    "min-h-14 rounded-xl px-5 text-lg font-semibold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:opacity-50";
const CHIP =
    "min-h-[44px] rounded-full px-4 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2";
const CHIP_ON = "bg-primary-500 text-white";
const CHIP_OFF = "bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700";
const PRINT_BUTTON =
    "min-h-[44px] w-full rounded-xl border border-slate-300 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800";

// Anything but an array of integers (corrupt value, blocked storage) means Semua stasiun.
const readSaved = () => {
    try {
        const saved = JSON.parse(localStorage.getItem(STORAGE_KEY));
        return Array.isArray(saved) && saved.every(Number.isInteger) ? saved : [];
    } catch {
        return [];
    }
};

// The sales in one card: the original and each add-on, each its own printed ticket (#314).
function groupSales(order) {
    const addOns = new Map();
    for (const item of order.items) {
        if (!item.add_on) continue;
        if (!addOns.has(item.add_on.id)) {
            addOns.set(item.add_on.id, {
                id: item.add_on.id, number: order.number, type: item.add_on.type ?? order.type,
                unpaid: item.add_on.unpaid, since: item.add_on.since, kind: "addon", items: [],
            });
        }
        addOns.get(item.add_on.id).items.push(item);
    }

    return [
        {
            id: order.id, number: order.number, type: order.type,
            unpaid: order.unpaid, since: order.since, kind: "order",
            items: order.items.filter((item) => !item.add_on),
        },
        ...addOns.values(),
        // Drop a sale with no live line: an empty original, or only-cancelled lines, would print blank (#314).
    ].filter((sale) => sale.items.some((item) => item.status !== "cancelled"));
}

// The one print footer of a card (REQ-004 K2): Coba lagi re-sends a ticket that never printed, Cetak ulang prints a
// CETAK ULANG copy of the ones that did. `order` is the whole order, whatever the station filter shows. A grouped card
// can hold both: an unprinted add-on keeps Coba lagi while the printed original keeps Cetak ulang (#540).
function PrintAction({ order, notPrinted, printed, printDevice }) {
    const { t } = useTranslation();
    if (!notPrinted && printed.length === 0) return null;

    // One card may hold several sales: match the running/failed job against the ones this button reprints (#314).
    const busy = printed.some((sale) => printDevice.reprinting?.includes(sale.id));
    const failed = printed.some((sale) => printDevice.reprintFailed === sale.id);

    return (
        <div className="space-y-2 border-t border-slate-100 p-4 dark:border-slate-800">
            {notPrinted && (
                <button type="button" onClick={printDevice.retry} className={PRINT_BUTTON}>
                    {t("kitchen.print.retry")}
                </button>
            )}
            {printed.length > 0 && (
                <button
                    type="button"
                    disabled={printDevice.headFailed || busy}
                    onClick={() => {
                        // A plain order is one ticket, unchanged; a grouped or add-on-only card reprints one ticket per printed sale (#314).
                        if (printed.length === 1 && printed[0].id === order.id) {
                            printDevice.reprint(order);
                        } else {
                            printed.forEach((sale) => printDevice.reprint(sale));
                        }
                    }}
                    className={PRINT_BUTTON}
                >
                    {t("kitchen.print.reprintButton")}
                </button>
            )}
            {failed && (
                <p role="status" className="mt-2 text-sm text-danger-700 dark:text-danger-400">
                    {t("kitchen.print.reprintFailed")}
                </p>
            )}
        </div>
    );
}

export default function Index({ orders, outlet, stations = [] }) {
    const { t } = useTranslation();
    const [lastOk, setLastOk] = useState(() => Date.now());
    const [now, setNow] = useState(() => Date.now());
    const [offline, setOffline] = useState(() => navigator.onLine === false);
    const [panelOpen, setPanelOpen] = useState(false);
    // Runs for the whole page, so tickets print with the panel closed.
    const printDevice = useKitchenPrintDevice(outlet);

    const [busy, setBusy] = useState(null);
    const { can } = useAuthorization();
    const canPrepare = can("dine-orders-prepare");
    const canHandOver = can("dine-orders-handover");
    // shortcut: two tabs on one device do not sync until reload; add a storage listener if a shop needs it.
    const [selected, setSelected] = useState(readSaved);
    // On the page so the 5 s poll keeps the choice (#361 BR-4).
    const [expanded, setExpanded] = useState(false);

    const multi = stations.length > 1;
    const chosen = selected.filter((id) => stations.some((s) => s.id === id));
    const showAll = !multi || chosen.length === 0;
    const staleChoice = multi && selected.length > 0 && chosen.length === 0;
    const activeIds = new Set(stations.map((s) => s.id));
    const defaultId = stations.find((s) => s.is_default)?.id;
    // An item of a deleted station (or with none) is filtered as the default station.
    const isShown = (item) => showAll || chosen.includes(activeIds.has(item.station_id) ? item.station_id : defaultId);
    const shownOrders = orders
        .map((order) => ({ ...order, items: order.items.filter(isShown) }))
        .filter((order) => order.items.length > 0);
    // A station screen sets a fully ready order aside, longest ready first; without ready_at, since counts (#361).
    // A cancelled line is not an unmade one: it neither holds the order back nor dates it.
    const live = (order) => order.items.filter((item) => item.status !== "cancelled");
    const waitsForPickup = (order) =>
        !showAll && live(order).length > 0 && live(order).every((item) => item.status === "ready");
    const readySince = (order) => Math.max(...live(order).map((item) => Date.parse(item.ready_at ?? order.since)));
    const working = shownOrders.filter((order) => !waitsForPickup(order));
    const pickup = shownOrders.filter(waitsForPickup).sort((a, b) => readySince(a) - readySince(b));

    const choose = (next) => {
        setSelected(next);
        try {
            if (next.length) localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
            else localStorage.removeItem(STORAGE_KEY);
        } catch {
            // Storage blocked: the choice lasts until reload.
        }
    };
    const toggle = (id) => choose(chosen.includes(id) ? chosen.filter((c) => c !== id) : [...chosen, id]);

    // No offline queue: a tap that cannot reach the server is refused, never replayed later.
    const step = (key, routeName, id, body = {}) => {
        const saveFailed = () => toast.error(t("kitchen.actions.saveFailed"));
        if (navigator.onLine === false) return saveFailed();
        setBusy(key);
        router.post(route(routeName, id), body, {
            preserveScroll: true,
            onError: (errors) => toast.error(errors.item ?? errors.message ?? t("kitchen.actions.saveFailed")),
            onNetworkError: saveFailed,
            onFinish: () => setBusy(null),
        });
    };

    const stepButton = (key, routeName, id, label, color, body) => (
        <button
            type="button"
            disabled={busy === key}
            onClick={() => step(key, routeName, id, body)}
            className={`${STEP_BUTTON} ${color}`}
        >
            {label}
        </button>
    );

    usePoll(POLL_MS, {
        only: ["orders", "stations"],
        onSuccess: () => {
            setLastOk(Date.now());
            setOffline(false);
        },
    });

    // The 1 s ticker drives both the stale-poll check and the elapsed minutes.
    useEffect(() => {
        const timer = setInterval(() => setNow(Date.now()), 1000);
        const goOffline = () => setOffline(true);
        const goOnline = () => setOffline(false);
        window.addEventListener("offline", goOffline);
        window.addEventListener("online", goOnline);
        return () => {
            clearInterval(timer);
            window.removeEventListener("offline", goOffline);
            window.removeEventListener("online", goOnline);
        };
    }, []);

    const connectionLost = offline || now - lastOk > STALE_MS;
    // The print UI only exists on a display with a print device, and the warnings only while it auto-prints.
    const hasPrinter = Boolean(outlet && printDevice.device);
    const queuedEntry = (order) =>
        printDevice.autoPrint
            ? printDevice.unprinted?.find((entry) => groupSales(order).some((sale) => entry.key === `t${sale.id}`))
            : undefined;
    const notPrinted = (order) => isNotPrinted(queuedEntry(order), now);
    // Cetak ulang is only for the sales on the card whose ticket this device printed (REQ-004 decision 12). A grouped
    // card keeps it for the printed sales even when an unprinted add-on joined them (#540); a sale still waiting to
    // print keeps Coba lagi only.
    const printed = new Set(printDevice.printedIds);
    // Only a ticket the auto-print queue still holds counts as not printed; with auto-print off nothing is queued.
    const queuedSales = new Set(
        (printDevice.autoPrint ? printDevice.unprinted ?? [] : []).map((entry) => Number(String(entry.key).replace(/^t/, "")))
    );
    const printedSales = (order) =>
        hasTarget()
            ? groupSales(order)
                  .filter((sale) => !queuedSales.has(sale.id))
                  // A cancelled line is not printed and does not hold the card back, as before (#540 keeps that rule).
                  .filter((sale) => sale.items.filter((item) => item.status !== "cancelled").every((item) => printed.has(item.id)))
            : [];
    function hasTarget() {
        // A tab that is not the printing one can't reach the printer: the leader tab holds it.
        return Boolean(printDevice.printerKey || printDevice.autoPrint) && !printDevice.otherTab;
    }
    // The sales the card shows: Semua siap marks only these, an add-on that arrived after the poll stays open (#540).
    const saleIdsFor = (order) => groupSales(order).map((sale) => sale.id);
    // `order` here is the whole order: a reprint is the same ticket, whatever the station filter shows.
    const printAction = (order) => (
        <PrintAction order={order} notPrinted={notPrinted(order)} printed={printedSales(order)} printDevice={printDevice} />
    );

    const cardProps = { orders, now, multi, showAll, chosen, activeIds, canPrepare, canHandOver, stepButton, notPrinted, hasPrinter, printAction, saleIdsFor };
    const card = (order) => <OrderCard key={order.id} order={order} {...cardProps} />;

    return (
        <>
            <Head title={t("kitchen.title")} />

            <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 dark:text-white">{t("kitchen.title")}</h1>
                    {outlet && <p className="text-sm text-slate-500 dark:text-slate-400">{outlet.name}</p>}
                </div>
                {outlet && (
                    <button
                        type="button"
                        aria-expanded={panelOpen}
                        aria-controls="kitchen-print-panel"
                        onClick={() => setPanelOpen((open) => !open)}
                        className="inline-flex min-h-[44px] items-center gap-2 rounded-xl border border-slate-300 px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800"
                    >
                        <IconPrinter size={18} aria-hidden="true" />
                        {t("kitchen.print.open")}
                    </button>
                )}
            </div>

            {panelOpen && <PrintDevicePanel printDevice={printDevice} />}

            {connectionLost && (
                <div
                    role="alert"
                    className="mb-4 flex items-center gap-2 rounded-xl bg-warning-100 px-4 py-3 text-sm font-medium text-warning-700 dark:bg-warning-900/50 dark:text-warning-400"
                >
                    <IconWifiOff size={18} aria-hidden="true" />
                    {t("kitchen.connectionLost")}
                </div>
            )}

            {hasPrinter && printDevice.autoPrint && <PrinterStatusBanner status={printDevice.status} onRetry={printDevice.retry} />}

            {multi && (
                <div role="group" aria-label={t("kitchen.stations.label")} className="mb-4 flex flex-wrap gap-2">
                    <button type="button" aria-pressed={showAll} onClick={() => choose([])} className={`${CHIP} ${showAll ? CHIP_ON : CHIP_OFF}`}>
                        {t("kitchen.stations.all")}
                    </button>
                    {stations.map((station) => (
                        <button
                            key={station.id}
                            type="button"
                            aria-pressed={chosen.includes(station.id)}
                            onClick={() => toggle(station.id)}
                            className={`${CHIP} ${chosen.includes(station.id) ? CHIP_ON : CHIP_OFF}`}
                        >
                            {station.name}
                        </button>
                    ))}
                </div>
            )}

            {staleChoice && (
                <p role="status" className="mb-4 text-sm text-slate-600 dark:text-slate-300">
                    {t("kitchen.stations.removed")}
                </p>
            )}

            {!outlet ? (
                <p className="py-16 text-center text-slate-500 dark:text-slate-400">{t("kitchen.noOutlet")}</p>
            ) : orders.length === 0 ? (
                <p className="py-16 text-center text-slate-500 dark:text-slate-400">{t("kitchen.empty")}</p>
            ) : shownOrders.length === 0 ? (
                <p className="py-16 text-center text-slate-500 dark:text-slate-400">{t("kitchen.stations.emptyForSelection")}</p>
            ) : (
                <>
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">{working.map(card)}</div>
                    <ReadyForPickup orders={pickup} expanded={expanded} onToggle={() => setExpanded((open) => !open)} renderCard={card} />
                </>
            )}
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
