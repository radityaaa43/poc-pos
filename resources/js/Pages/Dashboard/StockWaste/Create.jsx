import React, { useState } from "react";
import { Head, Link, router, useForm } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import { IconArrowLeft, IconPlus, IconTrash } from "@tabler/icons-react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Button from "@/Components/Dashboard/Button";
import Input from "@/Components/Dashboard/Input";
import InputSelect from "@/Components/Dashboard/InputSelect";
import Select from "@/Components/Dashboard/Select";
import TextArea from "@/Components/Dashboard/TextArea";

const rupiah = (value) => Number(value).toLocaleString("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 });

// Priced as StockWasteService does: each stock product's qty in whole units, then its value in whole rupiah.
const estimate = (item, qty) =>
    (item?.parts ?? []).reduce((sum, [cost, per]) => sum + Math.round(cost * Math.round(per * (Number(qty) || 0))), 0);

let lineKey = 0;
const blankLine = () => ({ key: ++lineKey, product_id: "", qty: "" });

export default function Create({ warehouses, warehouseId, reasons, items }) {
    const { t } = useTranslation();
    // One key per opened form, kept across refusals, so a double submit records once.
    const [clientKey] = useState(() => crypto.randomUUID());
    const { data, setData, post, processing, errors } = useForm({
        warehouse_id: warehouseId ?? "",
        reason: "",
        note: "",
        client_key: clientKey,
        lines: [blankLine()],
    });
    const itemOf = (line) => items.find((item) => item.id === line.product_id);
    const total = data.lines.reduce((sum, line) => sum + estimate(itemOf(line), line.qty), 0);
    const setLine = (key, changes) => setData("lines", data.lines.map((line) => (line.key === key ? { ...line, ...changes } : line)));

    const chooseWarehouse = (id) => {
        setData("warehouse_id", id);
        // Stock and average cost differ per warehouse.
        router.reload({ only: ["items"], data: { warehouse_id: id } });
    };

    const submit = (event) => {
        event.preventDefault();
        post(route("stock-waste.store"));
    };

    return (
        <>
            <Head title={t("waste.create")} />
            <div className="mb-6">
                <Link href={route("stock-waste.index")} className="mb-3 inline-flex items-center gap-2 text-sm text-slate-500 hover:text-primary-600">
                    <IconArrowLeft size={16} /> {t("waste.back")}
                </Link>
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white">{t("waste.create")}</h1>
            </div>

            <form onSubmit={submit} noValidate className="max-w-3xl space-y-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                {(errors.stock || errors.lines || errors.client_key) && (
                    <div role="alert" className="rounded-xl border border-danger-500 p-3 text-sm text-danger-600 dark:text-danger-400">
                        {errors.stock || errors.lines || errors.client_key}
                    </div>
                )}
                <div className="grid gap-4 md:grid-cols-2">
                    <Select id="warehouse_id" label={t("waste.warehouse")} error={errors.warehouse_id} value={data.warehouse_id} onChange={(e) => chooseWarehouse(e.target.value)}>
                        {warehouses.map((warehouse) => (
                            <option key={warehouse.id} value={warehouse.id}>{warehouse.code} — {warehouse.name}</option>
                        ))}
                    </Select>
                    <Select id="reason" label={t("waste.reason")} error={errors.reason} value={data.reason} onChange={(e) => setData("reason", e.target.value)}>
                        <option value="">{t("waste.chooseReason")}</option>
                        {reasons.map((reason) => (
                            <option key={reason} value={reason}>{t(`waste.reasons.${reason}`)}</option>
                        ))}
                    </Select>
                </div>

                {data.lines.map((line, index) => {
                    const item = itemOf(line);
                    const error = errors[`lines.${index}.product_id`] || errors[`lines.${index}.qty`];

                    return (
                        <fieldset key={line.key} className="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                            <legend className="px-1 text-sm font-semibold text-slate-700 dark:text-slate-200">{t("waste.itemNumber", { number: index + 1 })}</legend>
                            <div className="grid items-end gap-3 md:grid-cols-[1fr_10rem_auto]">
                                <InputSelect
                                    label={t("waste.item")}
                                    placeholder={t("waste.chooseItem")}
                                    data={items}
                                    selected={item ?? null}
                                    setSelected={(picked) => setLine(line.key, { product_id: picked.id })}
                                    displayKey="title"
                                    searchable
                                />
                                <Input
                                    type="number"
                                    inputMode="numeric"
                                    min="1"
                                    step="1"
                                    label={item?.unit_symbol ? `${t("waste.qty")} (${item.unit_symbol})` : t("waste.qty")}
                                    value={line.qty}
                                    onChange={(e) => setLine(line.key, { qty: e.target.value })}
                                />
                                <button
                                    type="button"
                                    onClick={() => setData("lines", data.lines.filter((other) => other.key !== line.key))}
                                    disabled={data.lines.length === 1}
                                    aria-label={t("waste.removeItem", { number: index + 1 })}
                                    className="flex h-11 w-11 items-center justify-center rounded-xl text-danger-500 hover:bg-danger-50 disabled:opacity-40 dark:hover:bg-slate-800"
                                >
                                    <IconTrash size={18} />
                                </button>
                            </div>
                            {item && (
                                <p className="mt-2 flex flex-wrap justify-between gap-2 text-sm text-slate-600 dark:text-slate-400">
                                    <span>{item.stock === null ? t("waste.menuStock") : t("waste.stockLeft", { stock: `${item.stock} ${item.unit_symbol ?? ""}`.trim() })}</span>
                                    <span>{rupiah(estimate(item, line.qty))}</span>
                                </p>
                            )}
                            {error && <p role="alert" className="mt-1 text-xs text-danger-500 dark:text-danger-400">{error}</p>}
                        </fieldset>
                    );
                })}
                <Button
                    type="button"
                    icon={<IconPlus size={18} />}
                    label={t("waste.addItem")}
                    onClick={() => setData("lines", [...data.lines, blankLine()])}
                    disabled={data.lines.length >= 50}
                    className="border border-slate-200 text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                />

                <TextArea label={t("waste.noteOptional")} aria-label={t("waste.noteOptional")} maxLength={500} rows={3} value={data.note} errors={errors.note} onChange={(e) => setData("note", e.target.value)} />

                <div aria-live="polite">
                    <p className="text-lg font-semibold text-slate-900 dark:text-white">{t("waste.total", { value: rupiah(total) })}</p>
                    <p className="text-xs text-slate-500 dark:text-slate-400">{t("waste.estimateNote")}</p>
                </div>
                <Button type="submit" label={t("waste.save")} disabled={processing} aria-busy={processing} className="bg-primary-500 text-white hover:bg-primary-600 disabled:opacity-60" />
            </form>
        </>
    );
}

Create.layout = (page) => <DashboardLayout children={page} />;
