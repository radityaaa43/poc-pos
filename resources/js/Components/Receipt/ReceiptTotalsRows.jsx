import React from "react";
import { useTranslation } from "react-i18next";
import { addedTax, includedTax, includedTaxLabel, taxLabel } from "@/Utils/billTotals";

function Row({ label, value, plus = true }) {
    return (
        <div className="flex justify-between text-slate-600 dark:text-slate-400">
            <span>{label}</span>
            <span>{plus ? "+ " : ""}{value}</span>
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
            {addedTax(transaction) > 0 && (
                <Row label={taxLabel(transaction, t, { withPpnRate: true })} value={format(addedTax(transaction))} />
            )}
            {includedTax(transaction) > 0 && (
                <Row label={includedTaxLabel(transaction, t)} value={format(includedTax(transaction))} plus={false} />
            )}
        </>
    );
}
