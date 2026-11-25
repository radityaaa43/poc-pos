import React, { useState } from "react";
import { Link } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import { IconChevronDown, IconChevronUp } from "@tabler/icons-react";
import OpenBillPayModal from "./OpenBillPayModal";

const formatPrice = (value = 0) =>
    Number(value || 0).toLocaleString("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    });

/**
 * Unpaid bills: accepted dine-in orders and pay-later POS sales still to be paid. Collapsed to a badge like
 * HeldTransactions; "Bayar" opens the payment modal for the transaction (dine-in: created at accept).
 */
// Fulfillment state of the order, shown next to the amount.
const FULFILLMENT_LABELS = { accepted: "Menunggu Pembayaran", preparing: "Sedang dibuat", ready: "Siap", served: "Sudah diserahkan" };

// Age of a bill in whole minutes, or hours from one hour on.
const billAge = (createdAt, t) => {
    const minutes = Math.max(Math.floor((Date.now() - new Date(createdAt)) / 60000), 0);
    return minutes < 60 ? t("pos.unpaidBills.ageMinutes", { count: minutes }) : t("pos.unpaidBills.ageHours", { count: Math.floor(minutes / 60) });
};

export default function OpenBills({ openBills = [], bankAccounts = [], paymentGateways = [] }) {
    const { t } = useTranslation();
    const [isExpanded, setIsExpanded] = useState(false);
    const [payingBill, setPayingBill] = useState(null);

    if (openBills.length === 0) {
        return null;
    }

    const Chevron = isExpanded ? IconChevronUp : IconChevronDown;

    return (
        <div className="border-b border-sky-200 bg-sky-50 dark:border-sky-800/50 dark:bg-sky-950/30" data-testid="open-bills">
            <button
                type="button"
                onClick={() => setIsExpanded(!isExpanded)}
                aria-expanded={isExpanded}
                className="flex w-full items-center justify-between px-3 py-2"
            >
                <span className="flex items-center gap-2">
                    <span className="flex h-6 w-6 items-center justify-center rounded-md bg-sky-500 text-xs font-bold text-white">
                        {openBills.length}
                    </span>
                    <span className="text-sm font-medium text-sky-700 dark:text-sky-300">{t("pos.unpaidBills.title")}</span>
                </span>
                <Chevron size={16} className="text-sky-600" />
            </button>

            {isExpanded && (
                <ul className="max-h-[140px] overflow-y-auto">
                    {openBills.map((bill) => (
                        <li
                            key={bill.id}
                            className="flex items-center justify-between gap-2 border-t border-sky-100/60 px-3 py-2 dark:border-sky-900/30"
                        >
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium text-slate-800 dark:text-slate-200">
                                    {bill.label ?? "Meja"} · {bill.invoice}
                                </p>
                                <p className="text-xs text-slate-500 dark:text-slate-400">
                                    {formatPrice(bill.amount ?? bill.grand_total)}
                                    {bill.order_status && ` · ${FULFILLMENT_LABELS[bill.order_status] ?? bill.order_status}`} · {billAge(bill.created_at, t)}
                                </p>
                            </div>
                            {bill.pending ? (
                                // a gateway payment is already waiting: show its QR instead of charging again
                                <Link
                                    href={route("transactions.print", bill.invoice)}
                                    className="inline-flex h-9 items-center rounded-lg bg-slate-500 px-3 text-xs font-semibold text-white hover:bg-slate-600"
                                >
                                    Lihat QR
                                </Link>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => setPayingBill(bill)}
                                    className="h-9 rounded-lg bg-sky-500 px-3 text-xs font-semibold text-white hover:bg-sky-600"
                                >
                                    {t("pos.unpaidBills.pay")}
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {payingBill && (
                <OpenBillPayModal bill={payingBill} bankAccounts={bankAccounts} paymentGateways={paymentGateways} onClose={() => setPayingBill(null)} />
            )}
        </div>
    );
}
