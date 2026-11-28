// What the client derives from a sale's bill.

const amount = (value) => Number(value || 0);

// A sale stores no subtotal: every receipt derives it from the total.
export const billSubtotal = (sale, { promo = 0, loyalty = 0, voucher = 0 } = {}) =>
    amount(sale?.grand_total) +
    amount(sale?.discount) -
    amount(sale?.shipping_cost) -
    amount(sale?.tax_total) -
    amount(sale?.service_charge) +
    promo +
    loyalty +
    voucher;

// A null kind (a sale from before the bill rules) reads as PPN; `withPpnRate` keeps each surface's PPN wording.
export function taxLabel(sale, t, { withPpnRate = false } = {}) {
    if (sale?.tax_kind === "pb1") return t("pos.bill.taxPb1", { rate: amount(sale.tax_rate) });

    return withPpnRate ? `${t("pos.bill.taxPpn")} ${Number(sale?.tax_rate || 11).toFixed(0)}%` : t("pos.bill.taxPpn");
}

// The thermal printer's bytes are built without i18n, so the labels travel with the data.
export const receiptBillData = (sale, t) => ({
    service_charge: amount(sale.service_charge),
    service_charge_label: t("pos.bill.serviceCharge"),
    tax_label: taxLabel(sale, t),
});
