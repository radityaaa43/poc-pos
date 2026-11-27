import React from "react";
import { useTranslation } from "react-i18next";

const formatCurrency = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value);

const CHIP_COLORS = {
    item: "bg-primary-100 text-primary-700 dark:bg-primary-900/50 dark:text-primary-300",
    bill: "bg-danger-100 text-danger-700 dark:bg-danger-900/50 dark:text-danger-300",
};

/** What holds the shift close (REQ-003 AC-12): food not handed over and unpaid pay-later bills. */
export default function ShiftCloseBlockedList({ blockers = [] }) {
    const { t } = useTranslation();

    if (blockers.length === 0) return null;

    return (
        <section
            aria-labelledby="close-blocked-title"
            className="rounded-2xl border border-amber-300 bg-white p-5 dark:border-amber-800 dark:bg-slate-900"
        >
            <h2 id="close-blocked-title" className="text-lg font-semibold text-slate-900 dark:text-white">
                {t("cashierShifts.closeBlocked.title")}
            </h2>
            <ul className="mt-3 divide-y divide-slate-100 dark:divide-slate-800">
                {blockers.map((blocker, index) => (
                    // One order can hold the close with several identical lines, so the index is part of the key.
                    <li key={`${blocker.type}-${blocker.order}-${index}`} className="flex items-start justify-between gap-3 py-3">
                        <div className="min-w-0">
                            <p className="break-words text-sm font-semibold text-slate-900 dark:text-white">{blocker.order}</p>
                            {blocker.label && (
                                <p className="break-words text-sm text-slate-600 dark:text-slate-300">{blocker.label}</p>
                            )}
                            <span className={`mt-1 inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${CHIP_COLORS[blocker.type]}`}>
                                {t(`cashierShifts.closeBlocked.${blocker.type}`)}
                            </span>
                        </div>
                        <span className="shrink-0 text-sm font-semibold text-slate-900 dark:text-white">
                            {formatCurrency(blocker.amount)}
                        </span>
                    </li>
                ))}
            </ul>
        </section>
    );
}
