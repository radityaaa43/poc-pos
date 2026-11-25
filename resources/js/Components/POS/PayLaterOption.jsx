import React, { useEffect } from "react";
import { useTranslation } from "react-i18next";
import Input from "@/Components/Dashboard/Input";
import { useOnlineStatus } from "@/Context/OnlineStatusContext";

/**
 * "Bayar Belakangan" on the POS payment panel. Only offered when the outlet allows it (`enabled`)
 * and the POS is online. A credit customer picks a due date (a receivable is created); a guest must
 * leave a note, since the unpaid sale has no customer to chase.
 */
export default function PayLaterOption({
    enabled,
    payLater,
    onPayLaterChange,
    customer,
    dueDate,
    onDueDateChange,
    note,
    onNoteChange,
}) {
    const { t } = useTranslation();
    const online = useOnlineStatus();

    // Offline hides the toggle, so a choice made while online must not stay switched on unseen.
    useEffect(() => {
        if (!online && payLater) onPayLaterChange(false);
    }, [online, payLater, onPayLaterChange]);

    if (!enabled) return null;

    if (!online) {
        return (
            <p role="status" className="rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-500 dark:border-slate-700 dark:bg-slate-800">
                {t("pos.payLater.offline")}
            </p>
        );
    }

    return (
        <>
            <label className="flex cursor-pointer items-center justify-between rounded-xl border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800">
                <span>
                    <span className="block text-sm font-semibold text-slate-800 dark:text-white">{t("pos.payLater.label")}</span>
                    <span className="block text-xs text-slate-500">{t("pos.payLater.hint")}</span>
                </span>
                <input
                    type="checkbox"
                    className="peer sr-only"
                    checked={payLater}
                    onChange={(e) => onPayLaterChange(e.target.checked)}
                />
                <span className="flex h-6 w-11 items-center rounded-full bg-slate-300 p-1 transition peer-checked:bg-primary-500 peer-focus-visible:ring-2 peer-focus-visible:ring-primary-500/40">
                    <span className={`h-4 w-4 transform rounded-full bg-white shadow transition ${payLater ? "translate-x-5" : ""}`} />
                </span>
            </label>

            {payLater && customer?.may_buy_on_credit && (
                <Input type="date" label={t("pos.payLater.dueDate")} value={dueDate} onChange={(e) => onDueDateChange(e.target.value)} />
            )}

            {payLater && !customer && (
                <Input
                    label={t("pos.payLater.noteLabel")}
                    placeholder={t("pos.payLater.notePlaceholder")}
                    value={note}
                    onChange={(e) => onNoteChange(e.target.value)}
                    required
                    minLength={2}
                    maxLength={1000}
                />
            )}
        </>
    );
}
