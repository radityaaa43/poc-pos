// Helpers for purchase-unit rows. `product.units` comes from Product::units():
// each unit carries pivot { is_base, is_purchase_default, conversion_factor, buy_price }.

export const baseUnit = (product) => product.units?.find((u) => u.pivot.is_base) ?? null;

// Default purchase unit, else the base unit, else null (product without units).
export const defaultPurchaseUnit = (product) =>
    product.units?.find((u) => u.pivot.is_purchase_default) ?? baseUnit(product);

export const unitFactor = (unit) => Number(unit?.pivot.conversion_factor) || 1;

// Price per that unit: its own buy_price, else the stock-unit reference price x factor.
export const defaultUnitPrice = (product, unit) => {
    const own = Number(unit?.pivot.buy_price) || 0;
    if (own > 0) return own;
    return Math.round((Number(product.buy_price) || 0) * unitFactor(unit));
};

// "5.000 g" for qty x factor, or null when the unit is the stock unit itself.
export const stockEquivalent = (qty, factor, stockSymbol) => {
    if (!factor || Number(factor) === 1) return null;
    const value = new Intl.NumberFormat("id-ID", { maximumFractionDigits: 4 }).format(qty * factor);
    return `${value} ${stockSymbol ?? ""}`.trim();
};
