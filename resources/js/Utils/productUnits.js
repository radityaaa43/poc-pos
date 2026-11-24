// One unit row of the product form changed: base and purchase default are single-choice.
export function updateUnitRows(units, index, field, value) {
    return units.map((row, i) => {
        // The base unit's content is always 1 and its field is locked, so set it here.
        if (i === index && field === "is_base" && value) return { ...row, is_base: true, conversion_factor: 1 };
        if (i === index) return { ...row, [field]: value };
        if (value && (field === "is_base" || field === "is_purchase_default")) return { ...row, [field]: false };
        return row;
    });
}
