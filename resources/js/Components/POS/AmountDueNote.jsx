import React from "react";
import { useTranslation } from "react-i18next";

const formatPrice = (value = 0) =>
    Number(value || 0).toLocaleString("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 });

// Under the total of an unpaid or pending sale whose returns lowered what is asked. `amount_due` comes from the server.
export default function AmountDueNote({ transaction }) {
    const { t } = useTranslation();

    if (transaction.amount_due == null) {
        return null;
    }

    return (
        <p className="text-xs font-semibold text-primary-600 dark:text-primary-400">
            {t("pos.retryPayment.due")}: {formatPrice(transaction.amount_due)}
        </p>
    );
}
