import Input from "@/Components/Dashboard/Input";
import { IconTrash } from "@tabler/icons-react";

// One row of the product form's units block, shared by Create and Edit.
// Every control is h-11, matching Input. The card sits beside the sidebar, so it
// is only ~500px wide until xl; four columns wait for xl, two columns below.
export default function UnitRow({ row, index, options, errors, onChange, onRemove }) {
    const fieldError = (field) => errors[`units.${index}.${field}`];
    const selectId = `unit-${index}-unit_id`;

    return (
        <div className="grid grid-cols-2 gap-3 py-4 first:pt-0 last:pb-0 xl:grid-cols-4">
            <div className="col-span-2 flex flex-col gap-2 xl:col-span-1">
                <label
                    htmlFor={selectId}
                    className="text-sm font-medium text-slate-700 dark:text-slate-300"
                >
                    Satuan
                </label>
                <div className="flex gap-2">
                    <select
                        id={selectId}
                        value={row.unit_id}
                        onChange={(e) => onChange("unit_id", Number(e.target.value) || "")}
                        className={`h-11 w-full min-w-0 rounded-xl border bg-slate-50 px-3 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:bg-slate-800 dark:text-slate-200 ${
                            fieldError("unit_id")
                                ? "border-danger-500"
                                : "border-slate-200 dark:border-slate-700"
                        }`}
                    >
                        <option value="">Pilih satuan</option>
                        {options.map((unit) => (
                            <option key={unit.id} value={unit.id}>
                                {unit.code}
                            </option>
                        ))}
                    </select>
                    <button
                        type="button"
                        onClick={onRemove}
                        aria-label="Hapus satuan"
                        title="Hapus satuan"
                        className="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-danger-600 transition-colors hover:bg-danger-50 focus:outline-none focus:ring-2 focus:ring-danger-500/20 dark:hover:bg-danger-950/30"
                    >
                        <IconTrash size={18} />
                    </button>
                </div>
                {fieldError("unit_id") && (
                    <small className="text-xs text-danger-500 dark:text-danger-400">
                        {fieldError("unit_id")}
                    </small>
                )}
            </div>

            <Input
                label="Konversi"
                type="number"
                min="0.0001"
                step="0.0001"
                inputMode="decimal"
                value={row.conversion_factor}
                onChange={(e) => onChange("conversion_factor", e.target.value)}
                errors={fieldError("conversion_factor")}
                placeholder="1"
                disabled={row.is_base}
                className="disabled:cursor-not-allowed disabled:text-slate-500 dark:disabled:text-slate-400"
            />
            <Input
                label="Harga Beli"
                type="number"
                min="0"
                inputMode="numeric"
                value={row.buy_price}
                onChange={(e) => onChange("buy_price", e.target.value)}
                errors={fieldError("buy_price")}
                placeholder="0"
            />
            <Input
                label="Harga Jual"
                type="number"
                min="0"
                inputMode="numeric"
                value={row.sell_price}
                onChange={(e) => onChange("sell_price", e.target.value)}
                errors={fieldError("sell_price")}
                placeholder="0"
            />

            <div className="xl:col-span-2">
                <Input
                    label="Barcode"
                    type="text"
                    value={row.barcode}
                    onChange={(e) => onChange("barcode", e.target.value)}
                    errors={fieldError("barcode")}
                    placeholder="Opsional"
                />
            </div>

            <div className="col-span-2 flex items-end gap-2">
                <UnitToggle
                    checked={row.is_base}
                    onChange={(checked) => onChange("is_base", checked)}
                    label="Dasar"
                />
                <UnitToggle
                    checked={row.is_purchase_default}
                    onChange={(checked) => onChange("is_purchase_default", checked)}
                    label="Beli default"
                />
            </div>
        </div>
    );
}

// Checkbox as an h-11 chip so it lines up with the inputs and is a full touch target.
function UnitToggle({ checked, onChange, label }) {
    return (
        <label
            className={`inline-flex h-11 min-w-0 flex-1 cursor-pointer items-center justify-center gap-2 rounded-xl border px-3 text-sm font-medium transition-colors ${
                checked
                    ? "border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-950/30 dark:text-primary-300"
                    : "border-slate-200 bg-slate-50 text-slate-600 hover:border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300"
            }`}
        >
            <input
                type="checkbox"
                checked={checked}
                onChange={(e) => onChange(e.target.checked)}
                className="rounded border-slate-300 text-primary-600 focus:ring-primary-500"
            />
            <span className="truncate">{label}</span>
        </label>
    );
}
