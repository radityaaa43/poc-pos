import React from "react";
import { usePage } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import { useOnlineStatus } from "@/Context/OnlineStatusContext";

/**
 * Wraps the order type buttons (REQ-010 BR-5). Offline the screen cannot recompute a total, so at an outlet with a
 * service charge the type chosen before the connection dropped stays; #418 lifts this. A disabled fieldset
 * disables every button in it.
 */
export default function OrderTypeLock({ children }) {
    const { t } = useTranslation();
    const online = useOnlineStatus();
    const rate = Number(usePage().props.activeCashierShift?.service_charge_rate);
    const reason = !online && rate > 0 ? t("pos.bill.offlineOrderTypeLocked") : null;

    return (
        <div>
            <fieldset disabled={Boolean(reason)} title={reason ?? undefined} className="min-w-0 disabled:opacity-60">
                {children}
            </fieldset>
            <p role="status" className="text-xs text-warning-700 dark:text-warning-400">
                {reason}
            </p>
        </div>
    );
}
