import React, { useCallback, useEffect, useState } from "react";
import { router } from "@inertiajs/react";
import axios from "axios";
import toast from "react-hot-toast";
import { useTranslation } from "react-i18next";
import { IconArmchair } from "@tabler/icons-react";
import Modal from "@/Components/Dashboard/Modal";
import TableStatusBadge from "@/Components/Dine/TableStatusBadge";

const formatPrice = (value = 0) =>
    Number(value).toLocaleString("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 });

const ring = "focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500";
const button = `flex min-h-[44px] items-center justify-center gap-2 rounded-xl px-4 text-sm font-semibold disabled:cursor-not-allowed disabled:opacity-50 ${ring}`;
const secondary = `${button} border border-slate-200 text-slate-700 dark:border-slate-700 dark:text-slate-200`;

/** One radio group; `options` are [value, label] pairs. */
function Choices({ legend, options, value, onChange }) {
    return (
        <fieldset>
            <legend className="mb-1 text-sm font-semibold text-slate-800 dark:text-white">{legend}</legend>
            {options.map(([option, label]) => (
                <label key={String(option)} className="flex min-h-[44px] cursor-pointer items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                    <input
                        type="radio"
                        name={legend}
                        checked={value === option}
                        onChange={() => onChange(option)}
                        className="text-primary-500 focus:ring-primary-500"
                    />
                    {label}
                </label>
            ))}
        </fieldset>
    );
}

/**
 * "Pesanan meja" on the POS (REQ-009 AC-01..AC-04, AC-21): pick a table and send the server cart to it. A table order
 * lives on the server, so offline it is refused and never queued. Not offered at an outlet without tables.
 */
export default function TableOrderModal({ carts }) {
    const { t } = useTranslation();
    const [list, setList] = useState(null);
    const [failed, setFailed] = useState(false);
    const [show, setShow] = useState(false);
    const [tableId, setTableId] = useState(null);
    const [addOnId, setAddOnId] = useState(null);
    const [payment, setPayment] = useState("pay_at_counter");
    const [sending, setSending] = useState(false);

    const load = useCallback(async () => {
        setFailed(false);
        try {
            const { data } = await axios.get(route("transactions.tables"));
            setList(data);
        } catch {
            setFailed(true);
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    const refusedOffline = () => {
        if (navigator.onLine) return false;
        toast.error(t("pos.tableOrder.offline"));
        return true;
    };

    // Statuses move while the POS stays open, so every opening asks again.
    const open = () => {
        if (refusedOffline()) return;
        setTableId(null);
        setAddOnId(null);
        setPayment("pay_at_counter");
        setShow(true);
        load();
    };

    // A failed first load keeps the button: offline, it must still say why table orders do not work.
    if (!show && !failed && !list?.tables.length) return null;

    const table = list?.tables.find(({ id }) => id === tableId);

    const send = () => {
        if (refusedOffline()) return;
        setSending(true);
        router.post(
            route("transactions.table-orders.store"),
            { dine_table_id: table.id, payment_option: payment, add_on_to_dine_order_id: addOnId },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setShow(false);
                    toast.success(t("pos.tableOrder.sent", { table: table.name }));
                },
                onError: (errors) => toast.error(Object.values(errors)[0]),
                onNetworkError: () => {
                    toast.error(t("pos.tableOrder.failed"));
                    return false;
                },
                onFinish: () => setSending(false),
            }
        );
    };

    return (
        <>
            <button
                type="button"
                onClick={open}
                className={`${button} w-full border border-primary-500 text-primary-600 hover:bg-primary-50 dark:text-primary-400 dark:hover:bg-primary-900/30`}
            >
                <IconArmchair size={18} aria-hidden="true" />
                {t("pos.tableOrder.button")}
            </button>

            <Modal show={show} onClose={() => setShow(false)} title={t("pos.tableOrder.button")} maxWidth="lg">
                <div className="space-y-4">
                    {failed ? (
                        <div role="alert" className="flex items-center justify-between gap-3 text-sm text-danger-600 dark:text-danger-400">
                            <p>{t("pos.tableOrder.loadFailed")}</p>
                            <button type="button" onClick={load} className={secondary}>
                                {t("pos.tableOrder.retry")}
                            </button>
                        </div>
                    ) : !list?.tables.length ? (
                        <p role="status" className="text-sm text-slate-500 dark:text-slate-400">
                            {t(list ? "pos.tableOrder.empty" : "pos.tableOrder.loading")}
                        </p>
                    ) : (
                        <ul className="grid max-h-72 grid-cols-2 gap-2 overflow-y-auto p-1 sm:grid-cols-3">
                            {list.tables.map((item) => (
                                <li key={item.id}>
                                    <button
                                        type="button"
                                        aria-pressed={item.id === tableId}
                                        onClick={() => {
                                            setTableId(item.id);
                                            setAddOnId(null);
                                        }}
                                        className={`flex h-full min-h-[44px] w-full flex-col items-start gap-1 rounded-xl border p-3 text-left ${ring} ${
                                            item.id === tableId
                                                ? "border-primary-500 bg-primary-50 dark:bg-primary-900/30"
                                                : "border-slate-200 dark:border-slate-700"
                                        }`}
                                    >
                                        <span className="break-all text-sm font-semibold text-slate-800 dark:text-white">{item.name}</span>
                                        <span className="text-xs text-slate-500 dark:text-slate-400">
                                            {item.area_name ? `${item.area_name} · ` : ""}
                                            {t("pos.tableOrder.capacity", { total: item.capacity })}
                                        </span>
                                        <TableStatusBadge status={item.status} openBills={item.open_bills} />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}

                    {table && !failed && (
                        <>
                            {carts.length === 0 ? (
                                <p role="status" className="text-sm text-warning-700 dark:text-warning-400">{t("pos.tableOrder.cartEmpty")}</p>
                            ) : (
                                <div className="rounded-xl bg-slate-50 p-3 text-sm text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                                    <ul className="max-h-32 overflow-y-auto">
                                        {carts.map((cart) => (
                                            <li key={cart.id} className="flex justify-between gap-3">
                                                <span>{cart.product?.title}</span>
                                                <span>× {cart.qty}</span>
                                            </li>
                                        ))}
                                    </ul>
                                    <p className="mt-2 flex justify-between border-t border-slate-200 pt-2 font-semibold dark:border-slate-700">
                                        <span>{t("pos.tableOrder.total")}</span>
                                        <span>{formatPrice(list.cartTotal)}</span>
                                    </p>
                                </div>
                            )}
                            {table.orders.length > 0 && (
                                <Choices
                                    legend={t("pos.tableOrder.order")}
                                    value={addOnId}
                                    onChange={setAddOnId}
                                    options={[
                                        [null, t("pos.tableOrder.newOrder")],
                                        ...table.orders.map((order) => [order.id, t("pos.tableOrder.addTo", { order: order.id, total: order.item_count })]),
                                    ]}
                                />
                            )}
                            <Choices
                                legend={t("pos.tableOrder.payment")}
                                value={payment}
                                onChange={setPayment}
                                options={[
                                    ["pay_at_counter", t("pos.tableOrder.payAtCounter")],
                                    ...(list.payLaterEnabled ? [["pay_later", t("pos.payLater.label")]] : []),
                                ]}
                            />
                        </>
                    )}

                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => setShow(false)} className={secondary}>
                            {t("pos.tableOrder.cancel")}
                        </button>
                        <button
                            type="button"
                            onClick={send}
                            disabled={!table || failed || carts.length === 0 || sending}
                            className={`${button} bg-primary-500 text-white hover:bg-primary-600`}
                        >
                            {t(sending ? "pos.tableOrder.sending" : "pos.tableOrder.submit")}
                        </button>
                    </div>
                </div>
            </Modal>
        </>
    );
}
