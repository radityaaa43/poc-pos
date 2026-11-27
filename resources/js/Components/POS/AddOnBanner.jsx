import React from "react";
import { router, usePage } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import { useOnlineStatus } from "@/Context/OnlineStatusContext";
import i18n from "@/i18n";

// The order this visit adds to (#251), read from the page's own URL: per tab, and it works offline.
const pinned = () => new URLSearchParams(window.location.search).get("add_on_to") || undefined;
export const withAddOn = (data) => ({ ...data, add_on_to: pinned() });

/** Why a checkout cannot be queued offline: add-ons, split and pay later need the server. */
export function offlineRefusal(splitMode, payLater) {
    if (pinned()) return i18n.t("addOn.offline");
    if (splitMode) return "Split pembayaran tidak tersedia saat offline";
    return payLater ? i18n.t("pos.payLater.offline") : null;
}

/** What the POS cart will do with a pinned add-on; `addOn` is the server's preview, null for a normal sale. */
export default function AddOnBanner() {
    const { addOn } = usePage().props;
    const { t } = useTranslation();
    const online = useOnlineStatus();

    if (!addOn) return null;

    const known = {
        linked: t("addOn.banner", { invoice: addOn.invoice }),
        completed: t("addOn.completed"),
        cancelled: t("addOn.cancelled"),
    };
    const text = online ? (known[addOn.state] ?? addOn.reason) : t("addOn.offline");
    const blocked = !online || !["linked", "completed"].includes(addOn.state);

    // A client-side visit, so the add-on can also be dropped offline and the payment panel keeps its state.
    const drop = () =>
        router.replace({
            url: route("transactions.index", {}, false),
            props: (props) => ({ ...props, addOn: null }),
            preserveState: true,
            preserveScroll: true,
        });

    return (
        <div
            role={blocked ? "alert" : "status"}
            className={`flex flex-shrink-0 items-center justify-between gap-3 border-b px-3 py-2 text-sm font-medium ${
                blocked
                    ? "border-danger-200 bg-danger-100 text-danger-700 dark:border-danger-900 dark:bg-danger-900/50 dark:text-danger-400"
                    : "border-primary-200 bg-primary-100 text-primary-700 dark:border-primary-900 dark:bg-primary-900/50 dark:text-primary-400"
            }`}
        >
            <p className="min-w-0 break-words">{text}</p>
            <button
                type="button"
                onClick={drop}
                className="min-h-[44px] shrink-0 rounded-lg px-3 underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
            >
                {t("addOn.cancel")}
            </button>
        </div>
    );
}
