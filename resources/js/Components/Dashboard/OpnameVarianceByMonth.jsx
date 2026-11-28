import React from "react";
import { useTranslation } from "react-i18next";

const formatCurrency = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value);

const thClass =
    "px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500 dark:text-slate-400 whitespace-nowrap";
const tdClass = "px-4 py-3 text-sm text-slate-700 dark:text-slate-300 whitespace-nowrap";

export default function OpnameVarianceByMonth({ variance }) {
    const { t, i18n } = useTranslation();
    const months = variance?.months ?? [];
    const started = variance?.waste_started_month ?? null;
    const hasOpnames = months.some((row) => row.opnames > 0);
    const hasEstimate = months.some((row) => row.estimated);
    const startedBeforeWindow = started !== null && months.length > 0 && started < months[0].month;

    const locale = i18n.language === "en" ? "en-US" : "id-ID";
    const formatMonth = (month) =>
        new Intl.DateTimeFormat(locale, { month: "long", year: "numeric" }).format(
            new Date(`${month}-01T00:00:00`)
        );

    return (
        <section
            aria-labelledby="opname-variance-heading"
            className="mb-6 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"
        >
            <h2
                id="opname-variance-heading"
                className="text-lg font-semibold text-slate-900 dark:text-white"
            >
                {t("opnameVariance.title")}
            </h2>
            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {t("opnameVariance.description")}
            </p>

            {!hasOpnames && (
                <p className="mt-3 text-sm text-slate-500 dark:text-slate-400">
                    {t("opnameVariance.empty")}
                </p>
            )}
            {started === null && (
                <p className="mt-3 text-sm text-slate-500 dark:text-slate-400">
                    {t("opnameVariance.noWaste")}
                </p>
            )}
            {startedBeforeWindow && (
                <p className="mt-3 text-sm text-slate-500 dark:text-slate-400">
                    {t("opnameVariance.startedIn", { month: formatMonth(started) })}
                </p>
            )}
            {hasEstimate && (
                <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    {t("opnameVariance.estimatedNote")}
                </p>
            )}

            <div className="mt-4 overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="border-b bg-slate-50 dark:border-slate-800 dark:bg-slate-950">
                        <tr>
                            <th scope="col" className={thClass}>
                                {t("opnameVariance.month")}
                            </th>
                            <th scope="col" className={thClass}>
                                {t("opnameVariance.phase")}
                            </th>
                            <th scope="col" className={thClass}>
                                {t("opnameVariance.opnames")}
                            </th>
                            <th scope="col" className={thClass}>
                                {t("opnameVariance.avgValue")}
                            </th>
                            <th scope="col" className={thClass}>
                                {t("opnameVariance.avgItemsOff")}
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y dark:divide-slate-800">
                        {months.map((row) => (
                            <tr key={row.month}>
                                <td className={tdClass}>
                                    <span>{formatMonth(row.month)}</span>
                                    {started === row.month && (
                                        <span className="ml-2 inline-flex rounded-full bg-primary-100 px-2 py-0.5 text-xs font-semibold text-primary-700 dark:bg-primary-950/40 dark:text-primary-400">
                                            {t("opnameVariance.wasteStarted")}
                                        </span>
                                    )}
                                </td>
                                <td className={tdClass}>
                                    {t(row.phase === "after" ? "opnameVariance.after" : "opnameVariance.before")}
                                </td>
                                <td className={tdClass}>{row.opnames}</td>
                                <td className={tdClass}>
                                    {row.avg_value === null ? "-" : formatCurrency(row.avg_value)}
                                    {row.estimated && (
                                        <span className="ml-2 inline-flex rounded-full bg-warning-100 px-2 py-0.5 text-xs font-semibold text-warning-700 dark:bg-warning-950/40 dark:text-warning-400">
                                            {t("opnameVariance.estimated")}
                                        </span>
                                    )}
                                </td>
                                <td className={tdClass}>
                                    {row.avg_items_off === null ? "-" : row.avg_items_off}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}
