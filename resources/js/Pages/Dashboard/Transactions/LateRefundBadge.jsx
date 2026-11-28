import React from "react";
import { useTranslation } from "react-i18next";

const formatPrice = (value = 0) =>
    Number(value || 0).toLocaleString("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 });

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
    const { t } = useTranslation();

    if (!transaction.late_superseded_payment_at) {
        return null;
    }

    return (
        <p role="status" className="mt-3 text-sm font-medium text-danger-700 dark:text-danger-400">
            {t("transactions.lateRefund.notice")}
            {transaction.overpaid_amount > 0 && (
                <span className="block">
                    {t("transactions.lateRefund.overpaid", { amount: formatPrice(transaction.overpaid_amount) })}
                </span>
            )}
        </p>
    );
}
