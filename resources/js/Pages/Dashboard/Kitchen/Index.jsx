import React, { useEffect, useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, usePoll } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import { IconClock, IconPrinter, IconWifiOff } from "@tabler/icons-react";
import { useKitchenPrintStation } from "@/Hooks/useKitchenPrintStation";
import PrintStationPanel from "./PrintStationPanel";

const POLL_MS = 5000;
const STALE_MS = 15000;

export default function Index({ orders, outlet }) {
    const { t } = useTranslation();
    const [lastOk, setLastOk] = useState(() => Date.now());
    const [now, setNow] = useState(() => Date.now());
    const [offline, setOffline] = useState(() => navigator.onLine === false);
    const [panelOpen, setPanelOpen] = useState(false);
    // Runs for the whole page, so tickets print with the panel closed.
    const printStation = useKitchenPrintStation(outlet);

    usePoll(POLL_MS, {
        only: ["orders"],
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

            {!outlet ? (
                <p className="py-16 text-center text-slate-500 dark:text-slate-400">{t("kitchen.noOutlet")}</p>
            ) : orders.length === 0 ? (
                <p className="py-16 text-center text-slate-500 dark:text-slate-400">{t("kitchen.empty")}</p>
            ) : (
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {orders.map((order) => (
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
                                </div>
                            </header>
                            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                                {order.items.map((item) => (
                                    <li key={item.id} className="flex min-h-[44px] items-center justify-between gap-3 px-4 py-2">
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
                                    </li>
                                ))}
                            </ul>
                        </article>
                    ))}
                </div>
            )}
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
