import React from "react";
import { useTranslation } from "react-i18next";
import { IconDatabaseOff } from "@tabler/icons-react";

const cardClass =
    "bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800";
const thClass =
    "px-4 py-4 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase whitespace-nowrap";
const tdClass = "px-4 py-4 text-right text-sm text-slate-600 dark:text-slate-400 whitespace-nowrap";

const WasteRecorderDays = ({ rows = [], approvalLimit = null, formatCurrency }) => {
    const { t } = useTranslation();

    return (
        <section aria-labelledby="waste-recorder-days" className={`${cardClass} overflow-hidden`}>
            <h2
                id="waste-recorder-days"
                className="px-4 py-4 text-sm font-semibold text-slate-700 dark:text-slate-300"
            >
                {t("wasteReport.recorderDays")}
            </h2>
            <div className="overflow-x-auto">
                <table className="w-full">
                    <caption className="px-4 pb-3 text-left text-xs text-slate-500 dark:text-slate-400">
                        {approvalLimit !== null
                            ? t("wasteReport.limitNote", { amount: formatCurrency(approvalLimit) })
                            : t("wasteReport.recorderDays")}
                    </caption>
                    <thead>
                        <tr className="border-b border-slate-100 dark:border-slate-800">
                            <th scope="col" className={`${thClass} text-left`}>
                                {t("wasteReport.recorder")}
                            </th>
                            <th scope="col" className={`${thClass} text-left`}>
                                {t("wasteReport.date")}
                            </th>
                            <th scope="col" className={`${thClass} text-right`}>
                                {t("wasteReport.records")}
                            </th>
                            <th scope="col" className={`${thClass} text-right`}>
                                {t("wasteReport.value")}
                            </th>
                            <th scope="col" className={`${thClass} text-right`}>
                                {t("wasteReport.nearLimit")}
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                        {rows.length === 0 ? (
                            <tr>
                                <td
                                    colSpan={5}
                                    className="px-4 py-16 text-center text-sm text-slate-500 dark:text-slate-400"
                                >
                                    <IconDatabaseOff
                                        size={32}
                                        className="mx-auto mb-2 text-slate-400"
                                    />
                                    {t("wasteReport.empty")}
                                </td>
                            </tr>
                        ) : (
                            rows.map((row) => (
                                <tr
                                    key={`${row.date}-${row.user_id ?? "deleted"}`}
                                    className="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors"
                                >
                                    <th
                                        scope="row"
                                        className="px-4 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white whitespace-nowrap"
                                    >
                                        {row.user_name ?? t("wasteReport.deletedUser")}
                                    </th>
                                    <td className="px-4 py-4 text-left text-sm text-slate-600 dark:text-slate-400 whitespace-nowrap">
                                        {row.date}
                                    </td>
                                    <td className={tdClass}>{row.records}</td>
                                    <td className={tdClass}>{formatCurrency(row.value)}</td>
                                    <td className={tdClass}>
                                        {row.near_limit}
                                        {row.near_limit > 0 && (
                                            <span className="ml-2 px-2 py-0.5 text-xs font-medium bg-amber-100 dark:bg-amber-900/50 text-amber-700 dark:text-amber-400 rounded-full">
                                                {t("wasteReport.nearLimit")}
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>
        </section>
    );
};

export default WasteRecorderDays;
