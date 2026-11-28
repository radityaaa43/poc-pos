import React from "react";
import { useTranslation } from "react-i18next";
import { IconCircleCheck, IconClock, IconUsers } from "@tabler/icons-react";

// A word and its own icon, so a status never rests on colour alone (REQ-009 AC-04).
const LOOKS = {
    free: [IconCircleCheck, "bg-success-100 text-success-700 dark:bg-success-900/50 dark:text-success-400"],
    occupied: [IconUsers, "bg-warning-100 text-warning-700 dark:bg-warning-900/50 dark:text-warning-400"],
    reserved: [IconClock, "bg-primary-100 text-primary-700 dark:bg-primary-900/50 dark:text-primary-400"],
};

/** A table's computed status (Kosong, Terisi, Dipesan) with its number of unpaid bills. */
export default function TableStatusBadge({ status, openBills = 0 }) {
    const { t } = useTranslation();

    if (!LOOKS[status]) return null;

    const [Icon, colors] = LOOKS[status];

    return (
        <span className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1">
            <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium ${colors}`}>
                <Icon size={14} aria-hidden="true" />
                {t(`dine.tableStatus.${status}`)}
            </span>
            <span className="text-xs text-slate-500 dark:text-slate-400">{t("dine.tableStatus.openBills", { total: openBills })}</span>
        </span>
    );
}
