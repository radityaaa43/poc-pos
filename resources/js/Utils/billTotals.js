// What the client derives from a sale's bill.

const amount = (value) => Number(value || 0);

// A sale stores no subtotal: every receipt derives it from the total. Only the PPN added on top stands in the
// total; the part inside a tax-inclusive price is already in the items (#576).
export const billSubtotal = (sale, { promo = 0, loyalty = 0, voucher = 0 } = {}) =>
    amount(sale?.grand_total) +
    amount(sale?.discount) -
    amount(sale?.shipping_cost) -
    addedTax(sale) -
    amount(sale?.service_charge) +
    promo +
    loyalty +
    voucher;

// The PPN charged on top of the prices, and the part a tax-inclusive price already carries.
export const addedTax = (sale) => Math.max(0, amount(sale?.tax_total) - amount(sale?.tax_included));
export const includedTax = (sale) => amount(sale?.tax_included);

const rateText = (rate) => String(Number(Number(rate || 0).toFixed(2)));

// "Termasuk PPN 11%" / "Includes 11% PPN"; without a rate when the sale stored none.
export const includedTaxLabel = (sale, t) =>
    Number(sale?.tax_rate || 0) > 0
        ? t("pos.bill.taxIncluded", { rate: rateText(sale.tax_rate) })
        : t("pos.bill.taxIncludedNoRate");

// A null kind (a sale from before the bill rules) reads as PPN; `withPpnRate` keeps each surface's PPN wording.
export function taxLabel(sale, t, { withPpnRate = false } = {}) {
    if (sale?.tax_kind === "pb1") return t("pos.bill.taxPb1", { rate: amount(sale.tax_rate) });

    return withPpnRate ? `${t("pos.bill.taxPpn")} ${Number(sale?.tax_rate || 11).toFixed(0)}%` : t("pos.bill.taxPpn");
}

// The thermal printer's bytes are built without i18n, so the labels travel with the data.
export const receiptBillData = (sale, t) => ({
    service_charge: amount(sale.service_charge),
    service_charge_label: t("pos.bill.serviceCharge"),
    tax_added: addedTax(sale),
    tax_label: taxLabel(sale, t),
    tax_included: includedTax(sale),
    tax_included_label: includedTaxLabel(sale, t),
});
