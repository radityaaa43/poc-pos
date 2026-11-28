import React, { useState } from "react";
import { router } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import toast from "react-hot-toast";

const formatCurrency = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value);

const CHIP_COLORS = {
    item: "bg-primary-100 text-primary-700 dark:bg-primary-900/50 dark:text-primary-300",
    bill: "bg-danger-100 text-danger-700 dark:bg-danger-900/50 dark:text-danger-300",
    split: "bg-danger-100 text-danger-700 dark:bg-danger-900/50 dark:text-danger-300",
    discount: "bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300",
};

const DECIDE_BUTTONS = {
    approve: "bg-success-500 text-white hover:bg-success-600",
    deny: "border border-danger-200 text-danger-600 hover:bg-danger-50 dark:border-danger-800 dark:text-danger-400",
};

/**
 * What holds the shift close: food not handed over, unpaid pay-later bills (REQ-003 AC-12), partly paid splits (#396)
 * and sales whose discount still waits for approval (#542). A holder of `discounts-approve` decides the last from here.
 */
export default function ShiftCloseBlockedList({ blockers = [], canDecide = false }) {
    const { t } = useTranslation();
    const [busy, setBusy] = useState(null);

    if (blockers.length === 0) return null;

    const decide = (blocker, action) => {
        const label = t(`cashierShifts.closeBlocked.${action}`);
        if (!window.confirm(`${label} ${blocker.order}?`)) return;

        const key = `${action}-${blocker.id}`;
        setBusy(key);
        router.post(route(`discount-approvals.${action}`, blocker.id), {}, {
            preserveScroll: true,
            onError: () => toast.error(t("cashierShifts.closeBlocked.decideFailed")),
            onFinish: () => setBusy(null),
        });
    };

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
                                {t(`cashierShifts.closeBlocked.${blocker.type}`, { amount: formatCurrency(blocker.amount) })}
                            </span>
                            {blocker.type === "discount" && canDecide && (
                                <span className="mt-2 flex flex-wrap gap-2">
                                    {["approve", "deny"].map((action) => (
                                        <button
                                            key={action}
                                            type="button"
                                            aria-label={`${t(`cashierShifts.closeBlocked.${action}`)} ${blocker.order}`}
                                            aria-busy={busy === `${action}-${blocker.id}`}
                                            disabled={busy !== null}
                                            onClick={() => decide(blocker, action)}
                                            className={`inline-flex items-center rounded-xl px-3 py-1.5 text-xs font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${DECIDE_BUTTONS[action]}`}
                                        >
                                            {t(`cashierShifts.closeBlocked.${action}`)}
                                        </button>
                                    ))}
                                </span>
                            )}
                        </div>
                        {/* A split's and a discount's chip already say the amount. */}
                        {!["split", "discount"].includes(blocker.type) && (
                            <span className="shrink-0 text-sm font-semibold text-slate-900 dark:text-white">
                                {formatCurrency(blocker.amount)}
                            </span>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}
