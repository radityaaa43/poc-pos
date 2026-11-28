import React from "react";
import { useTranslation } from "react-i18next";
import { IconPlus, IconTrash } from "@tabler/icons-react";
import Input from "@/Components/Dashboard/Input";
import Select from "@/Components/Dashboard/Select";

const textButton = "inline-flex items-center gap-2 min-h-11 px-3 rounded-xl text-sm font-medium transition-colors";

// One option of a group. `prefix` ("options.0") is where the server puts this row's validation messages.
export default function OptionRow({ prefix, number, option, outlets, ingredients, errors, onChange, onRemove }) {
    const { t } = useTranslation();
    const set = (field, value) => onChange({ ...option, [field]: value });
    const error = (field) => errors[`${prefix}.${field}`];
    const setLine = (index, patch) =>
        set("components", option.components.map((line, i) => (i === index ? { ...line, ...patch } : line)));
    const off = option.disabled_outlet_ids;
    const toggleOutlet = (id) =>
        set("disabled_outlet_ids", off.includes(id) ? off.filter((other) => other !== id) : [...off, id]);
    const outletError = Object.entries(errors).find(([key]) => key.startsWith(`${prefix}.disabled_outlet_ids`))?.[1];
    const rowError = error("id") ?? error("components") ?? outletError;

    return (
        <fieldset className="rounded-2xl border border-slate-200 dark:border-slate-800 p-4 space-y-4">
            <legend className="px-1 text-sm font-semibold text-slate-700 dark:text-slate-300">
                {t("modifiers.optionNumber", { number })}
            </legend>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <Input
                    label={t("modifiers.optionName")}
                    value={option.name}
                    onChange={(e) => set("name", e.target.value)}
                    errors={error("name")}
                    maxLength={100}
                />
                <Input
                    type="number"
                    min="0"
                    label={t("modifiers.price")}
                    value={option.price_delta}
                    onChange={(e) => set("price_delta", e.target.value)}
                    errors={error("price_delta")}
                />
            </div>

            <label className="inline-flex min-h-11 items-center gap-2 cursor-pointer text-sm text-slate-700 dark:text-slate-300">
                <input
                    type="checkbox"
                    checked={option.is_default}
                    onChange={(e) => set("is_default", e.target.checked)}
                    className="size-5 rounded border-slate-300 text-primary-600 focus:ring-primary-500"
                />
                {t("modifiers.default")}
            </label>

            <div className="space-y-3">
                <p className="text-sm font-medium text-slate-700 dark:text-slate-300">{t("modifiers.recipe")}</p>
                {option.components.map((line, index) => {
                    const unit = ingredients.find((product) => product.id === Number(line.product_id))?.unit_symbol;

                    return (
                        <div key={index} className="grid grid-cols-[1fr_8rem_auto] items-start gap-2">
                            <Select
                                id={`${prefix}.components.${index}.product_id`}
                                label={t("modifiers.ingredient")}
                                value={line.product_id}
                                onChange={(e) => setLine(index, { product_id: e.target.value })}
                                error={error(`components.${index}.product_id`)}
                            >
                                <option value="">{t("modifiers.chooseIngredient")}</option>
                                {ingredients.map((product) => (
                                    <option key={product.id} value={product.id}>
                                        {product.title}
                                    </option>
                                ))}
                            </Select>
                            <Input
                                type="number"
                                min="1"
                                label={unit ? t("modifiers.qtyUnit", { unit }) : t("modifiers.qty")}
                                // Stored as a decimal, so a saved 50 arrives as 50 and a typed value stays a string.
                                value={line.qty}
                                onChange={(e) => setLine(index, { qty: e.target.value })}
                                errors={error(`components.${index}.qty`)}
                            />
                            <button
                                type="button"
                                aria-label={t("modifiers.removeIngredient", { number: index + 1 })}
                                onClick={() => set("components", option.components.filter((_, i) => i !== index))}
                                className="mt-7 size-11 inline-flex items-center justify-center rounded-xl text-danger-500 hover:bg-danger-50 dark:hover:bg-danger-900/20"
                            >
                                <IconTrash size={18} />
                            </button>
                        </div>
                    );
                })}
                <button
                    type="button"
                    onClick={() => set("components", [...option.components, { product_id: "", qty: "" }])}
                    className={`${textButton} text-primary-600 hover:bg-primary-50 dark:hover:bg-primary-900/20`}
                >
                    <IconPlus size={16} />
                    {t("modifiers.addIngredient")}
                </button>
            </div>

            {outlets.length > 0 && (
                <div role="group" aria-label={t("modifiers.activeAt")} className="space-y-1">
                    <p className="text-sm font-medium text-slate-700 dark:text-slate-300">{t("modifiers.activeAt")}</p>
                    <div className="flex flex-wrap gap-x-6">
                        {outlets.map((outlet) => {
                            const on = !off.includes(outlet.id);

                            return (
                                <button
                                    key={outlet.id}
                                    type="button"
                                    role="switch"
                                    aria-checked={on}
                                    onClick={() => toggleOutlet(outlet.id)}
                                    className="flex min-h-11 items-center gap-3 text-sm text-slate-700 dark:text-slate-300"
                                >
                                    <span
                                        aria-hidden="true"
                                        className={`relative h-6 w-11 shrink-0 rounded-full transition-colors ${on ? "bg-primary-500" : "bg-slate-300 dark:bg-slate-600"}`}
                                    >
                                        <span
                                            className={`absolute top-0.5 h-5 w-5 rounded-full bg-white transition-all ${on ? "left-[22px]" : "left-0.5"}`}
                                        />
                                    </span>
                                    {outlet.name}
                                </button>
                            );
                        })}
                    </div>
                </div>
            )}

            {rowError && (
                <p role="alert" className="text-xs text-danger-500 dark:text-danger-400">
                    {rowError}
                </p>
            )}

            <button type="button" onClick={onRemove} className={`${textButton} text-danger-500 hover:bg-danger-50 dark:hover:bg-danger-900/20`}>
                <IconTrash size={16} />
                {t("modifiers.removeOption")}
            </button>
        </fieldset>
    );
}
