import { useId } from "react";
import { useTranslation } from "react-i18next";
import Input from "@/Components/Dashboard/Input";

const hintClass = "mt-1 text-xs text-slate-500 dark:text-slate-400";

// The Tagihan section of the outlet edit form (REQ-010 AC-01). The number inputs carry no min/max: the server
// refuses out-of-range values, so its message appears under the field in the app's language, not the browser's.
export default function OutletBillSettings({ value, onChange, errors = {} }) {
    const { t } = useTranslation();
    const id = useId();
    const errorFor = (field) => errors[field] && (
        <small id={`${id}-${field}-error`} className="mt-1 block text-xs text-danger-500 dark:text-danger-400">{errors[field]}</small>
    );
    const errorId = (field) => (errors[field] ? `${id}-${field}-error` : undefined);
    const rate = (field, key, disabled = false) => (
        <div>
            <Input type="number" step="any" inputMode="decimal" required disabled={disabled} label={t(`outlets.bill.${key}.label`)} value={value[field]} onChange={(event) => onChange(field, event.target.value)} errors={errors[field]} aria-describedby={`${id}-${field}-hint`} />
            <p id={`${id}-${field}-hint`} className={hintClass}>{t(`outlets.bill.${key}.hint`)}</p>
        </div>
    );

    return (
        <section aria-labelledby={`${id}-title`} className="space-y-4 border-t border-slate-200 pt-4 dark:border-slate-800">
            <div>
                <h3 id={`${id}-title`} className="font-semibold text-slate-800 dark:text-white">{t("outlets.bill.title")}</h3>
                <p className={hintClass}>{t("outlets.bill.appliesToNew")}</p>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                {rate("service_charge_rate", "serviceCharge")}
                <fieldset>
                    <legend className="mb-2 text-sm font-medium text-slate-700 dark:text-slate-300">{t("outlets.bill.taxKind.label")}</legend>
                    <div className="space-y-2">
                        {["ppn", "pb1"].map((kind) => (
                            <label key={kind} className="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300">
                                <input type="radio" name={`${id}-tax-kind`} checked={value.tax_kind === kind} onChange={() => onChange("tax_kind", kind)} aria-describedby={errorId("tax_kind")} className="mt-0.5 border-slate-300 text-primary-600" />
                                <span>{t(`outlets.bill.taxKind.${kind}`)} <span className="block text-xs text-slate-500 dark:text-slate-400">{t(`outlets.bill.taxKind.${kind}Hint`)}</span></span>
                            </label>
                        ))}
                    </div>
                    {errorFor("tax_kind")}
                </fieldset>
                {rate("pb1_rate", "pb1Rate", value.tax_kind !== "pb1")}
                <div className="flex flex-col gap-2">
                    <label htmlFor={`${id}-rounding`} className="text-sm font-medium text-slate-700 dark:text-slate-300">{t("outlets.bill.rounding.label")}</label>
                    <select id={`${id}-rounding`} value={value.cash_rounding_unit} onChange={(event) => onChange("cash_rounding_unit", event.target.value)} aria-invalid={errors.cash_rounding_unit ? true : undefined} aria-describedby={errorId("cash_rounding_unit")} className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                        <option value={0}>{t("outlets.bill.rounding.none")}</option>
                        <option value={100}>100</option>
                        <option value={500}>500</option>
                    </select>
                    {errorFor("cash_rounding_unit")}
                </div>
            </div>
        </section>
    );
}
