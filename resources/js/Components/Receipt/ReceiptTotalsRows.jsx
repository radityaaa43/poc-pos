import React from "react";
import { useTranslation } from "react-i18next";
import { taxLabel } from "@/Utils/billTotals";

function Row({ label, value }) {
    return (
        <div className="flex justify-between text-slate-600 dark:text-slate-400">
            <span>{label}</span>
            <span>+ {value}</span>
        </div>
    );
}

/** What the invoice view adds to a sale's subtotal: shipping, service charge and tax. */
export default function ReceiptTotalsRows({ transaction, format }) {
    const { t } = useTranslation();

    return (
        <>
            {transaction.shipping_cost > 0 && <Row label="Ongkos Kirim" value={format(transaction.shipping_cost)} />}
            {transaction.service_charge > 0 && <Row label={t("pos.bill.serviceCharge")} value={format(transaction.service_charge)} />}
            {transaction.tax_total > 0 && (
                <Row label={taxLabel(transaction, t, { withPpnRate: true })} value={format(transaction.tax_total)} />
            )}
        </>
    );
}
