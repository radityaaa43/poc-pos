import React from "react";
import { useTranslation } from "react-i18next";
import OrderTypeLock from "@/Components/POS/OrderTypeLock";

const ORDER_TYPES = [
    { value: "in_store", label: "Di Tempat" },
    { value: "takeaway", label: "Bawa Pulang" },
    { value: "delivery", label: "Diantar" },
];
const NAME_MAX = 20; // the server rule and the column length
const COUNT_FROM = 15;

/** Order type and the optional order name (a customer or buzzer number) of the POS checkout. */
export default function OrderDetails({ orderType, onOrderTypeChange, orderName, onOrderNameChange }) {
    const { t } = useTranslation();
    const counting = orderName.length >= COUNT_FROM;

    return (
        <>
            <OrderTypeLock>
                <label className="mb-2 block text-xs font-medium text-slate-600 dark:text-slate-400">
                    Tipe Pesanan
                </label>
                <div className="grid grid-cols-3 gap-2">
                    {ORDER_TYPES.map((type) => (
                        <button
                            key={type.value}
                            type="button"
                            onClick={() => onOrderTypeChange(type.value)}
                            className={`min-h-10 rounded-lg px-1 py-2 text-xs font-semibold transition-all ${
                                orderType === type.value
                                    ? "bg-primary-500 text-white"
                                    : "bg-white text-slate-600 hover:bg-slate-100 dark:bg-slate-800 dark:text-slate-400 dark:hover:bg-slate-700"
                            }`}
                        >
                            {type.label}
                        </button>
                    ))}
                </div>
            </OrderTypeLock>

            <div>
                <label htmlFor="pos-order-name" className="mb-2 block text-xs font-medium text-slate-600 dark:text-slate-400">
                    {t("pos.orderName.label")}
                </label>
                <input
                    id="pos-order-name"
                    type="text"
                    autoComplete="off"
                    maxLength={NAME_MAX}
                    value={orderName}
                    onChange={(e) => onOrderNameChange(e.target.value)}
                    placeholder={t("pos.orderName.placeholder")}
                    aria-describedby={counting ? "pos-order-name-count" : undefined}
                    className="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"
                />
                <div className="flex justify-between gap-2 text-xs text-slate-500 dark:text-slate-400">
                    {/* Only the limit warning is announced; a live counter would speak on every key. */}
                    <span role="status" className="text-warning-700 dark:text-warning-400">
                        {orderName.length >= NAME_MAX ? t("pos.orderName.limit") : ""}
                    </span>
                    {counting && <span id="pos-order-name-count">{`${orderName.length}/${NAME_MAX}`}</span>}
                </div>
            </div>
        </>
    );
}
