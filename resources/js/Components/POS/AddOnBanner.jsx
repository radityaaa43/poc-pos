import React from "react";
import { router } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import { useOnlineStatus } from "@/Context/OnlineStatusContext";

/** What the POS cart will do with a pinned add-on (#251); `addOn` is the server's preview, null for a normal sale. */
export default function AddOnBanner({ addOn }) {
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
