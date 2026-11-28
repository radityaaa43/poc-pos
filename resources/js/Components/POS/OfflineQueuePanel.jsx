import React, { useCallback, useEffect, useState } from "react";
import axios from "axios";
import { useTranslation } from "react-i18next";
import { IconCloudUpload } from "@tabler/icons-react";
import Modal from "@/Components/Dashboard/Modal";
import { useOnlineStatus } from "@/Context/OnlineStatusContext";
import { getCachedProducts, getPendingTransactions } from "@/Utils/offlineDb";
import { beginDiscard, finishDiscard, QUEUE_EVENT, restore } from "@/Utils/offlineQueue";

const REMOVABLE = ["failed", "conflict", "discarding"];
const PAYMENT_KEYS = { cash: "cash", bank_transfer: "bankTransfer" };

const formatPrice = (value = 0) =>
    Number(value || 0).toLocaleString("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    });

// What the row was before a removal began: it decides which warning applies.
const originStatus = (row) => (row.status === "discarding" ? row.discard_from : row.status);

// The audit entry keeps exactly this; the bounds match DiscardOfflineSaleRequest.
function auditPayload(row) {
    const { grand_total, payment_method, customer_id, items } = row.data;

    return {
        client_uuid: row.client_uuid,
        queue_status: row.status,
        reason: row.last_error?.slice(0, 500) ?? null,
        attempts: row.attempts,
        queued_at: row.created_at,
        sale: {
            grand_total,
            payment_method,
            customer_id,
            items: items.map(({ product_id, unit_id, qty }) => ({ product_id, unit_id, qty })),
        },
    };
}

function SaleSummary({ row, names }) {
    const { t } = useTranslation();
    const { grand_total, payment_method, items = [] } = row.data ?? {};

    return (
        <div className="space-y-1 text-sm text-slate-700 dark:text-slate-300">
            <div className="flex items-center justify-between gap-2">
                <time dateTime={row.created_at}>
                    {new Date(row.created_at).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" })}
                </time>
                <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold dark:bg-slate-800">
                    {t(`pos.offlineQueue.status.${row.status}`)}
                </span>
            </div>
            {items.map((item, index) => (
                <p key={index}>
                    {item.qty} × {names[item.product_id] ?? t("pos.offlineQueue.product", { id: item.product_id })}
                </p>
            ))}
            <p className="font-semibold text-slate-900 dark:text-white">
                {t("common.labels.total")} {formatPrice(grand_total)} ·{" "}
                {PAYMENT_KEYS[payment_method]
                    ? t(`transactions.paymentMethods.${PAYMENT_KEYS[payment_method]}`)
                    : payment_method}
            </p>
            {row.last_error && <p className="break-words text-danger-700 dark:text-danger-400">{row.last_error}</p>}
        </div>
    );
}

/** The queued sales of this cashier that the server does not have as queued: see why, send again, or remove. */
export default function OfflineQueuePanel({ scopeKey, count, onRetry, onChange }) {
    const { t } = useTranslation();
    const isOnline = useOnlineStatus();
    const [open, setOpen] = useState(false);
    const [rows, setRows] = useState(null); // null while the queue is being read
    const [names, setNames] = useState({});
    const [confirming, setConfirming] = useState(null);
    const [busy, setBusy] = useState(false);
    const [retrying, setRetrying] = useState(false);
    const [error, setError] = useState(null); // key under pos.offlineQueue

    const load = useCallback(async () => {
        try {
            const [queued, products] = await Promise.all([
                getPendingTransactions(scopeKey),
                getCachedProducts().catch(() => []), // names only: the list shows ids without them
            ]);
            setNames(Object.fromEntries(products.map((product) => [product.id, product.title])));
            setRows(
                queued
                    .filter((row) => !["synced", "duplicate"].includes(row.status))
                    .sort((a, b) => Date.parse(b.created_at) - Date.parse(a.created_at) || b.id - a.id)
            );
        } catch {
            setError("loadError");
        }
    }, [scopeKey]);

    useEffect(() => {
        if (!open) return undefined;

        load();
        window.addEventListener(QUEUE_EVENT, load);
        return () => window.removeEventListener(QUEUE_EVENT, load);
    }, [open, load]);

    const close = () => {
        setOpen(false);
        setConfirming(null);
        setError(null);
    };

    const retry = async () => {
        setRetrying(true);
        setError(null);
        try {
            await onRetry();
        } catch {
            setError("retryError");
        }
        setRetrying(false);
        await load();
    };

    // The audit entry reaches the server before the row leaves the device.
    const remove = async () => {
        setBusy(true);
        setError(null);
        let unaudited = null;
        try {
            // shortcut: a page closed between the POST and the delete leaves the row discarding; removing it again
            // writes a second audit entry. Accepted: it over-records and never under-records.
            unaudited = await beginDiscard(confirming.id);
            if (!unaudited) {
                setError("changed");
            } else {
                const response = await axios.post(route("transactions.offline-discard"), auditPayload(unaudited), {
                    headers: { Accept: "application/json" },
                });
                // Only this proves the entry exists: a captive portal or a followed redirect answers 200.
                if (response.status !== 201 || !response.data?.data?.audit_id) throw new Error("not audited");
                unaudited = null; // from here on the row must never go back into the send queue
                await finishDiscard(confirming.id);
            }
        } catch {
            // If even this write fails the row stays discarding: listed, never sent, removable again.
            if (unaudited) await restore(unaudited.id, unaudited.status).catch(() => {});
            setError("removeError");
        }
        setConfirming(null);
        setBusy(false);
        await load();
        onChange?.();
    };

    const flushing = retrying || Boolean(rows?.some((row) => row.status === "syncing"));
    const title = t("pos.offlineQueue.button", { count: rows?.length ?? count });
    const secondary =
        "min-h-[44px] rounded-lg border border-slate-300 px-4 text-sm font-semibold text-slate-700 disabled:opacity-50 dark:border-slate-600 dark:text-slate-200";
    const danger =
        "min-h-[44px] rounded-lg bg-danger-600 px-4 text-sm font-semibold text-white hover:bg-danger-700 disabled:opacity-50";

    return (
        <>
            {count > 0 && (
                <button
                    type="button"
                    onClick={() => setOpen(true)}
                    aria-haspopup="dialog"
                    className="flex min-h-[44px] w-full flex-shrink-0 items-center gap-2 border-b border-warning-200 bg-warning-50 px-3 text-sm font-semibold text-warning-800 dark:border-warning-800/50 dark:bg-warning-950/30 dark:text-warning-300"
                >
                    <IconCloudUpload size={18} aria-hidden="true" />
                    {t("pos.offlineQueue.button", { count })}
                </button>
            )}
            <Modal show={open} onClose={close} closeable={!busy} title={title} maxWidth="lg">
                <div className="space-y-3">
                    {error && (
                        <p role="alert" className="text-sm font-semibold text-danger-700 dark:text-danger-400">
                            {t(`pos.offlineQueue.${error}`)}
                        </p>
                    )}
                    {!isOnline && (
                        <p className="text-sm text-slate-600 dark:text-slate-400">{t("pos.offlineQueue.onlineOnly")}</p>
                    )}

                    {confirming ? (
                        <>
                            <div className="max-h-[50vh] overflow-y-auto">
                                <SaleSummary row={confirming} names={names} />
                            </div>
                            {originStatus(confirming) === "conflict" ? (
                                <p className="text-sm text-slate-700 dark:text-slate-300">{t("pos.offlineQueue.conflict")}</p>
                            ) : (
                                <p className="rounded-lg bg-warning-50 p-3 text-sm font-semibold text-warning-800 dark:bg-warning-950/30 dark:text-warning-300">
                                    {t("pos.offlineQueue.confirmFailed")}
                                </p>
                            )}
                            <p className="text-sm text-slate-700 dark:text-slate-300">{t("pos.offlineQueue.confirm")}</p>
                            <div className="flex justify-end gap-2">
                                <button type="button" autoFocus onClick={() => setConfirming(null)} disabled={busy} className={secondary}>
                                    {t("common.buttons.cancel")}
                                </button>
                                <button type="button" onClick={remove} disabled={busy || !isOnline} className={danger}>
                                    {t("pos.offlineQueue.remove")}
                                </button>
                            </div>
                        </>
                    ) : (
                        <>
                            {rows === null ? (
                                !error && <p className="text-sm text-slate-500">{t("common.labels.loading")}</p>
                            ) : rows.length === 0 ? (
                                <p className="text-sm text-slate-500">{t("pos.offlineQueue.empty")}</p>
                            ) : (
                                <ul className="max-h-[60vh] space-y-2 overflow-y-auto">
                                    {rows.map((row) => (
                                        <li key={row.id} className="space-y-2 rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                                            <SaleSummary row={row} names={names} />
                                            {originStatus(row) === "conflict" && (
                                                <p className="text-sm text-slate-700 dark:text-slate-300">{t("pos.offlineQueue.conflict")}</p>
                                            )}
                                            {REMOVABLE.includes(row.status) && (
                                                <div className="flex justify-end">
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setError(null);
                                                            setConfirming(row);
                                                        }}
                                                        disabled={!isOnline}
                                                        className={danger}
                                                    >
                                                        {t("pos.offlineQueue.remove")}
                                                    </button>
                                                </div>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <div className="flex flex-wrap justify-end gap-2">
                                <button type="button" onClick={close} className={secondary}>
                                    {t("common.buttons.close")}
                                </button>
                                <button type="button" onClick={retry} disabled={!isOnline || flushing || !rows?.length} className={secondary}>
                                    {t("pos.offlineQueue.retryNow")}
                                </button>
                            </div>
                        </>
                    )}
                </div>
            </Modal>
        </>
    );
}
