import React from "react";
import { useTranslation } from "react-i18next";

const LIMIT = 10;

// A station screen's finished orders (#361), already sorted longest waiting first; hidden when there are none.
export default function ReadyForPickup({ orders, expanded, onToggle, renderCard }) {
    const { t } = useTranslation();
    if (orders.length === 0) return null;

    return (
        <section aria-labelledby="kitchen-pickup-title" className="mt-8">
            <h2 id="kitchen-pickup-title" className="mb-4 text-xl font-bold text-slate-900 dark:text-white">
                {t("kitchen.pickup.title", { count: orders.length })}
            </h2>
            <div id="kitchen-pickup-list" className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                {(expanded ? orders : orders.slice(0, LIMIT)).map(renderCard)}
            </div>
            {orders.length > LIMIT && (
                <button
                    type="button"
                    aria-expanded={expanded}
                    aria-controls="kitchen-pickup-list"
                    onClick={onToggle}
                    className="mt-4 min-h-[44px] w-full rounded-xl border border-slate-300 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800"
                >
                    {expanded ? t("kitchen.pickup.less") : t("kitchen.pickup.all", { count: orders.length })}
                </button>
            )}
        </section>
    );
}
