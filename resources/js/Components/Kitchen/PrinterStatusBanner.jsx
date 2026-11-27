import React, { useId, useState } from "react";
import { useTranslation } from "react-i18next";
import { IconAlertTriangle } from "@tabler/icons-react";

const BUTTON =
    "min-h-[44px] rounded-xl border border-current px-4 text-sm font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2";
// Status -> translation key of its wording. `unsupported` has no entry on purpose: it is shown in the
// settings panel only, so a display that never prints stays quiet.
const BANNERS = {
    driver_locked: "kitchen.print.driverLocked",
    not_connected: "kitchen.print.kitchenNotConnected",
    failed: "kitchen.print.failed",
};

// "Lihat langkah": the WinUSB replacement steps inline, no external link (staff cannot open the repo).
export function DriverLockedHelp() {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const listId = useId();

    return (
        <>
            <button
                type="button"
                aria-expanded={open}
                aria-controls={open ? listId : undefined}
                onClick={() => setOpen((value) => !value)}
                className={BUTTON}
            >
                {t("kitchen.print.driverLockedSteps")}
            </button>
            {open && (
                <ol id={listId} className="basis-full list-decimal space-y-1 pl-6 font-normal">
                    {[1, 2, 3, 4, 5].map((n) => (
                        <li key={n}>{t(`kitchen.print.winusbSteps.${n}`)}</li>
                    ))}
                </ol>
            )}
        </>
    );
}

// The one printer banner of the kitchen display: the single highest-priority status, in one alert region.
export default function PrinterStatusBanner({ status, onRetry }) {
    const { t } = useTranslation();
    const banner = BANNERS[status];
    if (!banner) return null;

    return (
        <div role="alert" className="mb-4 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl bg-danger-100 px-4 py-3 text-sm font-medium text-danger-700 dark:bg-danger-900/50 dark:text-danger-400">
            <IconAlertTriangle size={18} aria-hidden="true" />
            <span>{t(banner)}</span>
            {status === "failed" && (
                <button type="button" onClick={onRetry} className={BUTTON}>
                    {t("kitchen.print.retry")}
                </button>
            )}
            {status === "driver_locked" && <DriverLockedHelp />}
        </div>
    );
}
