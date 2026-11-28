// The bill on the client: the offline mirror of the server's total, and what documents derive from a sale.

const amount = (value) => Number(value || 0);

/**
 * BillTotalService::compute on the basis the preview returned; tests/fixtures/bill-totals.json holds both to the
 * same numbers. Math.round matches PHP's round for these non-negative amounts.
 */
export function computeBill(basis, settings, dineIn) {
    const pb1 = settings.tax_kind === "pb1";
    const service = dineIn ? Math.round((basis.after_discount * settings.service_charge_rate) / 100) : 0;
    const rate = pb1 ? settings.pb1_rate : basis.effective_rate;
    const tax = pb1
        ? Math.round(((basis.after_discount + service) * rate) / 100)
        : basis.line_tax_total + basis.shipping_tax + Math.round((service * rate) / 100);

    return {
        service_charge: service,
        tax_kind: settings.tax_kind,
        tax_total: tax,
        tax_rate: rate || null,
        grand_total: basis.after_discount + basis.shipping + service + tax,
    };
}

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
