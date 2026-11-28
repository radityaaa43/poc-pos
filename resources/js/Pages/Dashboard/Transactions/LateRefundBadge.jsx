import React from "react";
import { useTranslation } from "react-i18next";

// A paid callback of a superseded payment attempt arrived after the retry was paid: the money needs a manual refund.
export default function LateRefundBadge({ transaction }) {
    const { t } = useTranslation();

    if (!transaction.late_superseded_payment_at) {
        return null;
    }

    return (
        <span className="inline-flex items-center ml-1 px-2 py-1 text-xs font-medium bg-danger-100 dark:bg-danger-900/30 text-danger-700 dark:text-danger-400 rounded-full">
            {t("transactions.lateRefund.badge")}
        </span>
    );
}

export function LateRefundNotice({ transaction }) {
    const { t, i18n } = useTranslation();

    if (!transaction.late_superseded_payment_at) {
        return null;
    }

    const overpaid = transaction.overpaid_amount > 0;
    // The sentences carry "Rp" themselves: only the number is formatted.
    const formatAmount = (value) => new Intl.NumberFormat(i18n.language).format(value);

    return (
        <p role="status" className="mt-3 text-sm font-medium text-danger-700 dark:text-danger-400">
            {/* An overpaying payment was booked: "not booked" could get all of it refunded. */}
            {t(overpaid ? "transactions.lateRefund.checkHistory" : "transactions.lateRefund.notice")}
            {overpaid && (
                <span className="block">
                    {t("transactions.lateRefund.overpaid", { amount: formatAmount(transaction.overpaid_amount) })}
                </span>
            )}
            {transaction.cash_excess > 0 && (
                <span className="block">
                    {t(`transactions.lateRefund.${transaction.flagged_shift_open ? "cashOpen" : "cashClosed"}`, {
                        cash: formatAmount(transaction.cash_excess),
                    })}
                </span>
            )}
        </p>
    );
}
