import React, { useEffect, useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, router, usePoll } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import { IconClock, IconPrinter, IconWifiOff } from "@tabler/icons-react";
import toast from "react-hot-toast";
import { useAuthorization } from "@/Utils/authorization";
import { useKitchenPrintStation } from "@/Hooks/useKitchenPrintStation";
import PrintStationPanel from "./PrintStationPanel";

const POLL_MS = 5000;
const STALE_MS = 15000;
const STORAGE_KEY = "kitchenStations";
const STEP_BUTTON =
    "min-h-14 rounded-xl px-5 text-lg font-semibold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:opacity-50";
const CHIP =
    "min-h-[44px] rounded-full px-4 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2";
const CHIP_ON = "bg-primary-500 text-white";
const CHIP_OFF = "bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700";

// Anything but an array of integers (corrupt value, blocked storage) means Semua stasiun.
const readSaved = () => {
    try {
        const saved = JSON.parse(localStorage.getItem(STORAGE_KEY));
        return Array.isArray(saved) && saved.every(Number.isInteger) ? saved : [];
    } catch {
        return [];
    }
};

export default function Index({ orders, outlet, stations = [] }) {
    const { t } = useTranslation();
    const [lastOk, setLastOk] = useState(() => Date.now());
    const [now, setNow] = useState(() => Date.now());
    const [offline, setOffline] = useState(() => navigator.onLine === false);
    const [panelOpen, setPanelOpen] = useState(false);
    // Runs for the whole page, so tickets print with the panel closed.
    const printStation = useKitchenPrintStation(outlet);

    const [busy, setBusy] = useState(null);
    const canPrepare = useAuthorization().can("dine-orders-prepare");
    // shortcut: two tabs on one device do not sync until reload; add a storage listener if a shop needs it.
    const [selected, setSelected] = useState(readSaved);

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

            {panelOpen && <PrintStationPanel printStation={printStation} />}

            {connectionLost && (
                <div
                    role="alert"
                    className="mb-4 flex items-center gap-2 rounded-xl bg-warning-100 px-4 py-3 text-sm font-medium text-warning-700 dark:bg-warning-900/50 dark:text-warning-400"
                >
                    <IconWifiOff size={18} aria-hidden="true" />
                    {t("kitchen.connectionLost")}
                </div>
            )}

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
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {shownOrders.map((order) => (
                        <article
                            key={order.id}
                            className="rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900"
                        >
                            <header className="border-b border-slate-100 p-4 dark:border-slate-800">
                                <div className="flex items-start justify-between gap-3">
                                    <h2 className="text-2xl font-bold text-slate-900 dark:text-white">{order.number}</h2>
                                    <span className="inline-flex shrink-0 items-center gap-1 text-sm font-medium text-slate-600 dark:text-slate-300">
                                        <IconClock size={16} aria-hidden="true" />
                                        {t("kitchen.minutes", {
                                            count: Math.max(0, Math.floor((now - Date.parse(order.since)) / 60000)),
                                        })}
                                    </span>
                                </div>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                        {t(`kitchen.types.${order.type}`, { defaultValue: order.type })}
                                    </span>
                                    {order.unpaid && (
                                        <span className="rounded-full bg-danger-100 px-2.5 py-0.5 text-xs font-medium text-danger-700 dark:bg-danger-900/50 dark:text-danger-400">
                                            {t("kitchen.unpaid")}
                                        </span>
                                    )}
                                    {multi && showAll && (
                                        <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                            {t("kitchen.stations.progress", {
                                                ready: order.items.filter((i) => i.status === "ready").length,
                                                total: order.items.length,
                                            })}
                                        </span>
                                    )}
                                </div>
                            </header>
                            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                                {order.items.map((item) => (
                                    <li key={item.id} className="flex min-h-[44px] flex-wrap items-center justify-between gap-3 px-4 py-2">
                                        <div>
                                            <p className="font-medium text-slate-900 dark:text-white">
                                                {item.qty} × {item.title}
                                            </p>
                                            {item.note && (
                                                <p className="text-sm italic text-slate-600 dark:text-slate-300">{item.note}</p>
                                            )}
                                        </div>
                                        <span className="shrink-0 rounded-full bg-primary-100 px-2.5 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-900/50 dark:text-primary-400">
                                            {t(`kitchen.status.${item.status}`, { defaultValue: item.status })}
                                        </span>
                                        {/* An item of a removed station on a filtered device keeps its old name: the kitchen sees where it came from (B1). */}
                                        {multi && (showAll || !activeIds.has(item.station_id)) && item.station && (
                                            <span className="shrink-0 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                                {item.station}
                                            </span>
                                        )}
                                        {canPrepare && item.status === "accepted" &&
                                            stepButton(`start-${item.id}`, "kitchen.items.start", item.id, t("kitchen.actions.start"), "bg-primary-500 hover:bg-primary-600 focus-visible:ring-primary-500")}
                                        {canPrepare && item.status === "preparing" &&
                                            stepButton(`ready-${item.id}`, "kitchen.items.ready", item.id, t("kitchen.actions.ready"), "bg-success-600 hover:bg-success-700 focus-visible:ring-success-600")}
                                    </li>
                                ))}
                            </ul>
                            {canPrepare && order.items.some((item) => item.status === "accepted" || item.status === "preparing") && (
                                <footer className="border-t border-slate-100 p-4 dark:border-slate-800">
                                    {stepButton(`all-${order.id}`, "kitchen.orders.all-ready", order.id, t("kitchen.actions.allReady"), "w-full bg-success-600 hover:bg-success-700 focus-visible:ring-success-600", showAll ? {} : { station_ids: chosen })}
                                </footer>
                            )}
                        </article>
                    ))}
                </div>
            )}
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
