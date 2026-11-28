import React from "react";
import { useTranslation } from "react-i18next";

const formatPrice = (value = 0) =>
    Number(value || 0).toLocaleString("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 });

// The sale's total and, when returns lowered what an unpaid or pending sale is asked, that amount (`amount_due`).
export default function InvoiceTotal({ transaction }) {
    const { t } = useTranslation();

    return (
        <>
            <div className="flex justify-between text-lg font-bold text-slate-900 dark:text-white pt-2 border-t border-slate-200 dark:border-slate-700">
                <span>Total</span>
                <span>{formatPrice(transaction.grand_total)}</span>
            </div>
            {transaction.amount_due != null && (
                <div className="flex justify-between text-lg font-bold text-primary-600 dark:text-primary-400">
                    <span>{t("pos.retryPayment.due")}</span>
                    <span>{formatPrice(transaction.amount_due)}</span>
                </div>
            )}
        </>
    );
}
