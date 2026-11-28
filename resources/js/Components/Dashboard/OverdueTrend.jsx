import React from "react";
import { useTranslation } from "react-i18next";
import { IconChartLine } from "@tabler/icons-react";

const rupiah = (value) =>
    new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 }).format(value);

const monthLabel = (month, language) =>
    new Intl.DateTimeFormat(language === "en" ? "en-GB" : "id-ID", { month: "long", year: "numeric" }).format(
        new Date(`${month}-01T00:00:00`)
    );

/** REQ-007 "Piutang macet": the overdue total at each of the last 12 month-ends (last row = this month). */
export default function OverdueTrend({ rows = [] }) {
    const { t, i18n } = useTranslation();

    return (
        <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <h2 className="flex items-center gap-2 font-semibold text-slate-800 dark:text-white">
                <IconChartLine size={22} className="text-primary-500" />
                {t("overdueTrend.title")}
            </h2>

            {rows.length === 0 ? (
                <p className="mt-3 text-sm text-slate-500 dark:text-slate-400">{t("overdueTrend.empty")}</p>
            ) : (
                <table className="mt-4 w-full text-left">
                    <caption className="mb-2 text-left text-xs text-slate-500 dark:text-slate-400">{t("overdueTrend.restated")}</caption>
                    <thead>
                        <tr>
                            <th scope="col" className="px-3 py-2 text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{t("overdueTrend.month")}</th>
                            <th scope="col" className="px-3 py-2 text-right text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{t("overdueTrend.bills")}</th>
                            <th scope="col" className="px-3 py-2 text-right text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{t("overdueTrend.overdue")}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr key={row.month} className="border-t border-slate-100 dark:border-slate-800">
                                <td className="px-3 py-2 text-sm text-slate-700 dark:text-slate-300">{monthLabel(row.month, i18n.language)}</td>
                                <td className="px-3 py-2 text-right text-sm text-slate-700 dark:text-slate-300">{row.count}</td>
                                <td className="px-3 py-2 text-right text-sm font-semibold text-slate-900 dark:text-white">{rupiah(row.overdue)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </section>
    );
}
