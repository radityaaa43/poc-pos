import React from "react";
import { useTranslation } from "react-i18next";
import { IconAlertCircle, IconAlertTriangle } from "@tabler/icons-react";

const formatPrice = (value = 0) =>
    Number(value || 0).toLocaleString("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    });

// The text carries the meaning; colour and icon only add to it.
const STATES = {
    near: { Icon: IconAlertTriangle, className: "text-amber-700 dark:text-amber-300" },
    over: { Icon: IconAlertCircle, className: "text-rose-700 dark:text-rose-300" },
};

/** The "Batas" and "Terpakai %" cells of one receivable row. `credit` is null when there is nothing to show. */
export default function CreditCells({ credit }) {
    const { t } = useTranslation();
    const noLimit = credit?.state === "none";
    const limit = credit && !noLimit ? formatPrice(credit.limit) : null;
    const percent = credit?.percent ?? null;
    const state = STATES[credit?.state];

    return (
        <>
            <div className="text-right text-sm text-slate-700 dark:text-slate-200">
                {noLimit ? t("receivables.credit.noLimit") : limit ?? "—"}
            </div>
            <div className="text-right text-sm text-slate-700 dark:text-slate-200">
                {percent === null ? (
                    <span>—</span>
                ) : (
                    <span role="img" aria-label={t("receivables.credit.usedAria", { percent, limit })}>
                        {percent}%
                    </span>
                )}
                {state && (
                    // Inline, so the icon stays with the first word when the label wraps.
                    <span className={`block text-[11px] font-semibold ${state.className}`}>
                        <state.Icon size={12} aria-hidden="true" className="mr-1 inline-block align-[-2px]" />
                        {t(`receivables.credit.${credit.state}`)}
                    </span>
                )}
            </div>
        </>
    );
}
