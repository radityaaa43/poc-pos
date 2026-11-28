import React from "react";
import { useTranslation } from "react-i18next";
import { IconClock } from "@tabler/icons-react";

// One order on the kitchen display, used by the working grid and the pickup list; all state stays on the page.
export default function OrderCard({
    order, orders, now, multi, showAll, chosen, activeIds, canPrepare, canHandOver, stepButton, notPrinted, hasPrinter, printAction,
}) {
    const { t } = useTranslation();

    return (
        <article
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
                    {notPrinted(order) && (
                        <span className="rounded-full bg-warning-100 px-2.5 py-0.5 text-xs font-medium text-warning-700 dark:bg-warning-900/50 dark:text-warning-400">
                            {t("kitchen.print.notPrinted")}
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
                {order.items.map((item, index) => {
                    const addOn = item.add_on;
                    // An add-on's lines sit under a Tambahan label with their own age and unpaid mark (#314).
                    const startsAddOn = addOn && addOn.id !== order.items[index - 1]?.add_on?.id;

                    return (
                        <React.Fragment key={item.id}>
                            {startsAddOn && (
                                <li className="flex min-h-[36px] flex-wrap items-center justify-between gap-3 bg-slate-50 px-4 py-2 dark:bg-slate-800/50">
                                    <h3 className="text-sm font-bold uppercase tracking-wide text-slate-700 dark:text-slate-200">
                                        {t("addOn.label")}
                                    </h3>
                                    <span className="flex flex-wrap items-center gap-2">
                                        {addOn.unpaid && (
                                            <span className="rounded-full bg-danger-100 px-2 py-0.5 text-xs font-medium text-danger-700 dark:bg-danger-900/50 dark:text-danger-400">
                                                {t("kitchen.unpaid")}
                                            </span>
                                        )}
                                        <span className="inline-flex items-center gap-1 text-sm font-medium text-slate-600 dark:text-slate-300">
                                            <IconClock size={14} aria-hidden="true" />
                                            {t("kitchen.minutes", {
                                                count: Math.max(0, Math.floor((now - Date.parse(addOn.since)) / 60000)),
                                            })}
                                        </span>
                                    </span>
                                </li>
                            )}
                            <li key={item.id} className="flex min-h-[44px] flex-wrap items-center justify-between gap-3 px-4 py-2">
                        <div>
                            <p className={`font-medium ${item.status === "cancelled" ? "text-slate-500 line-through dark:text-slate-400" : "text-slate-900 dark:text-white"}`}>
                                {item.qty} × {item.title}
                            </p>
                            {(item.modifiers ?? []).map((option, index) => (
                                <p key={index} className="text-sm text-slate-600 dark:text-slate-300">+ {option}</p>
                            ))}
                            {item.note && (
                                <p className="text-sm italic text-slate-600 dark:text-slate-300">{item.note}</p>
                            )}
                        </div>
                        <span
                            className={`shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium ${item.status === "cancelled" ? "bg-danger-100 text-danger-700 dark:bg-danger-900/50 dark:text-danger-400" : "bg-primary-100 text-primary-700 dark:bg-primary-900/50 dark:text-primary-400"}`}
                        >
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
                        {canHandOver && item.status === "ready" &&
                            stepButton(`hand-over-${item.id}`, "kitchen.items.hand-over", item.id, t("kitchen.actions.handOver"), "bg-primary-500 hover:bg-primary-600 focus-visible:ring-primary-500")}
                    </li>
                        </React.Fragment>
                    );
                })}
            </ul>
            {/* empty:hidden drops the footer when no button applies */}
            <footer className="flex flex-col gap-3 border-t border-slate-100 p-4 empty:hidden dark:border-slate-800">
                {canPrepare && order.items.some((item) => item.status === "accepted" || item.status === "preparing") &&
                    stepButton(`all-${order.id}`, "kitchen.orders.all-ready", order.id, t("kitchen.actions.allReady"), "w-full bg-success-600 hover:bg-success-700 focus-visible:ring-success-600", showAll ? {} : { station_ids: chosen })}
                {canHandOver && order.items.some((item) => item.status === "ready") &&
                    stepButton(`hand-over-all-${order.id}`, "kitchen.orders.hand-over-all", order.id, t("kitchen.actions.handOverAll"), "w-full bg-primary-500 hover:bg-primary-600 focus-visible:ring-primary-500", showAll ? {} : { station_ids: chosen })}
            </footer>
            {hasPrinter && printAction(orders.find((each) => each.id === order.id))}
        </article>
    );
}
