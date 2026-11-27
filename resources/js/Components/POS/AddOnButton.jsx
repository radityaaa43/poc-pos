import React from "react";
import { router } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import { IconPlus } from "@tabler/icons-react";
import { useAuthorization } from "@/Utils/authorization";
import { useOnlineStatus } from "@/Context/OnlineStatusContext";

/**
 * "Tambah pesanan" on a sale's receipt (#251): opens the POS with this order pinned, under the original order's
 * invoice. `allowed` is the server's word that the order is not cancelled (BR-12); offline there is no add-on.
 */
export default function AddOnButton({ transaction, allowed }) {
    const { t } = useTranslation();
    const { can } = useAuthorization();
    const online = useOnlineStatus();

    if (!can("transactions-access") || !allowed) return null;

    return (
        <div className="flex flex-col gap-1">
            <button
                type="button"
                disabled={!online}
                aria-describedby={online ? undefined : "add-on-offline"}
                onClick={() => router.get(route("transactions.index"), { add_on_to: transaction.parent?.invoice ?? transaction.invoice })}
                className="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-xl bg-primary-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-primary-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
            >
                <IconPlus size={18} aria-hidden="true" />
                {t("addOn.add")}
            </button>
            {!online && (
                <p id="add-on-offline" role="status" className="max-w-xs text-xs text-slate-600 dark:text-slate-300">
                    {t("addOn.offline")}
                </p>
            )}
        </div>
    );
}
