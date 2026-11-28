import React from "react";
import { useTranslation } from "react-i18next";
import { taxLabel } from "@/Utils/billTotals";

function Row({ label, value, tone = "font-medium" }) {
    return (
        <div className="flex justify-between items-center mb-2 text-sm">
            <span className="text-slate-500">{label}</span>
            <span className={tone}>{value}</span>
        </div>
    );
}

/** The checkout summary between the promo rows and the total. `bill` comes from useBillTotals. */
export default function CheckoutSummaryRows({ voucher, loyalty, discount, shipping, bill, format }) {
    const { t } = useTranslation();

    return (
        <>
            {voucher > 0 && <Row label="Voucher" value={`-${format(voucher)}`} tone="text-primary-600" />}
            {loyalty > 0 && <Row label="Redeem Poin" value={`-${format(loyalty)}`} tone="text-primary-600" />}
            {discount > 0 && <Row label="Diskon Manual" value={`-${format(discount)}`} tone="text-danger-500" />}
            {shipping > 0 && <Row label="Ongkir" value={`+${format(shipping)}`} />}
            {bill.service_charge > 0 && <Row label={t("pos.bill.serviceCharge")} value={`+${format(bill.service_charge)}`} />}
            {bill.tax_total > 0 && <Row label={taxLabel(bill, t)} value={`+${format(bill.tax_total)}`} />}
        </>
    );
}
