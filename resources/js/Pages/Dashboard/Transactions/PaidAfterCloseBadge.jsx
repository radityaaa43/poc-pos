import React from "react";
import { useTranslation } from "react-i18next";

// A gateway payment reached a sale after the shift that owns it was closed: the closed recap was left as it was.
// Neutral amber, not danger red: nothing is wrong with the money.
export default function PaidAfterCloseBadge({ transaction }) {
    const { t } = useTranslation();

    if (!transaction.paid_after_close_at) {
        return null;
    }

    return (
        <span className="inline-flex items-center ml-1 px-2 py-1 text-xs font-medium bg-warning-100 dark:bg-warning-900/30 text-warning-700 dark:text-warning-400 rounded-full">
            {t("transactions.paidAfterClose.badge")}
        </span>
    );
}
