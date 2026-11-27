import React, { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { IconAlertTriangle } from "@tabler/icons-react";
import { useOnlineStatus } from "@/Context/OnlineStatusContext";

const formatPrice = (value = 0) =>
    Number(value || 0).toLocaleString("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    });

function Figure({ label, value }) {
    return (
        <div>
            <dt className="text-slate-500 dark:text-slate-400">{label}</dt>
            <dd className="font-semibold text-slate-900 dark:text-white">{value}</dd>
        </div>
    );
}

/**
 * Batas / Terpakai / Sisa of the credit customer picked at the POS. Information only: it never blocks
 * checkout. Hidden offline, where pay later is unavailable.
 */
export default function CustomerCreditPanel({ customer }) {
    const { t } = useTranslation();
    const online = useOnlineStatus();
    const id = online && customer?.may_buy_on_credit ? customer.id : null;
    const [result, setResult] = useState(null);
    const [attempt, setAttempt] = useState(0);

    useEffect(() => {
        if (!id) return;
        // Aborted on a new pick, so a slow answer never shows under another customer.
        const controller = new AbortController();
        setResult(null);
        fetch(route("customers.credit-summary", id), {
            headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
            signal: controller.signal,
        })
            .then((response) => {
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                return response.json();
            })
            .then((data) => {
                if (!controller.signal.aborted) setResult({ id, data });
            })
            .catch((error) => {
                if (controller.signal.aborted) return;
                console.error("Customer credit summary error:", error);
                setResult({ id, failed: true });
            });
        return () => controller.abort();
    }, [id, attempt]);

    if (!id) return null;

    const current = result?.id === id ? result : null;
    const data = current?.data;
    // The picker's list is a page-load snapshot: the summary knows if the permission was revoked since.
    if (data && !data.may_buy_on_credit) return null;

    return (
        <div
            role="status"
            aria-label={t("customers.credit.limitLabel")}
            aria-busy={!current}
            className="mt-2 rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs dark:border-slate-700 dark:bg-slate-800"
        >
            {!current && <div className="h-8 animate-pulse rounded bg-slate-200 dark:bg-slate-700" />}
            {current?.failed && (
                <div className="flex items-center justify-between gap-2">
                    <span className="text-danger-600 dark:text-danger-400">{t("pos.credit.loadError")}</span>
                    <button
                        type="button"
                        onClick={() => setAttempt((n) => n + 1)}
                        className="min-h-11 rounded-lg px-3 font-semibold text-primary-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-400"
                    >
                        {t("pos.credit.retry")}
                    </button>
                </div>
            )}
            {data && (
                <>
                    {data.credit_limit === null && (
                        <p className="mb-1 font-semibold text-slate-900 dark:text-white">{t("pos.credit.noLimit")}</p>
                    )}
                    <dl className="grid grid-cols-3 gap-2">
                        {data.credit_limit !== null && <Figure label={t("pos.credit.limit")} value={formatPrice(data.credit_limit)} />}
                        <Figure label={t("pos.credit.used")} value={formatPrice(data.used)} />
                        {data.credit_limit !== null && <Figure label={t("pos.credit.remaining")} value={formatPrice(data.remaining)} />}
                    </dl>
                    {data.has_overdue && (
                        <p className="mt-2 flex items-center gap-1.5 font-semibold text-amber-700 dark:text-amber-300">
                            <IconAlertTriangle size={14} aria-hidden="true" />
                            {t("pos.credit.overdue")}
                        </p>
                    )}
                </>
            )}
        </div>
    );
}
