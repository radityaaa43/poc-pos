import React, { useEffect, useState } from "react";
import toast from "react-hot-toast";
import { useTranslation } from "react-i18next";
import i18n from "@/i18n";
import { getPendingTransactions, queueTransaction } from "@/Utils/offlineDb";

const QUEUED_EVENT = "pos:offline-kitchen-queued";

export const needsKitchen = (carts = []) => carts.some((cart) => Boolean(cart.product?.needs_preparation));

/** Queues an offline sale and, when the kitchen has to make part of it, says it has not arrived there (REQ-003 AC-14). */
export async function queueOfflineSale(payload, scopeKey, carts) {
    const kitchen = needsKitchen(carts);
    // Marked on the queue record, not the payload: the server decides for itself what the kitchen makes.
    const id = await queueTransaction(payload, scopeKey, { needs_kitchen: kitchen });

    if (kitchen) {
        window.dispatchEvent(new Event(QUEUED_EVENT));
        toast(
            (notice) => (
                <div role="alert" className="flex items-center gap-3">
                    <span className="font-medium">{i18n.t("pos.offlineKitchen.notice")}</span>
                    <button
                        type="button"
                        onClick={() => toast.dismiss(notice.id)}
                        className="min-h-[44px] shrink-0 rounded-lg border border-slate-300 px-3 font-semibold dark:border-slate-600"
                    >
                        {i18n.t("pos.offlineKitchen.close")}
                    </button>
                </div>
            ),
            // One id: several offline sales must not stack notices over the POS. The marker below counts them.
            { id: "offline-kitchen", duration: Infinity }
        );
    }

    return id;
}

/** The pending-sync marker: how many queued sales the kitchen is still waiting for. */
export function OfflineKitchenMarker({ scopeKey }) {
    const { t } = useTranslation();
    const [count, setCount] = useState(0);

    useEffect(() => {
        const refresh = () =>
            getPendingTransactions(scopeKey)
                .then((rows) =>
                    // A conflict row is never sent again and the server holds that sale (BR-17): nothing left to wait for.
                    setCount(rows.filter((row) => row.needs_kitchen && !["synced", "duplicate", "conflict"].includes(row.status)).length)
                )
                .catch(() => {});

        refresh();
        window.addEventListener(QUEUED_EVENT, refresh);
        // Same cadence as the layout's pending-sync count: a sync removes rows without an event.
        const timer = setInterval(refresh, 15000);

        return () => {
            window.removeEventListener(QUEUED_EVENT, refresh);
            clearInterval(timer);
        };
    }, [scopeKey]);

    if (count === 0) return null;

    return (
        <div className="bg-amber-100 px-4 py-1 text-center text-xs font-semibold text-amber-900 dark:bg-amber-900/40 dark:text-amber-200">
            {t("pos.offlineKitchen.pending", { count })}
        </div>
    );
}
