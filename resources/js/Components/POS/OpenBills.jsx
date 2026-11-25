import React, { useState } from "react";
import { IconChevronDown, IconChevronUp } from "@tabler/icons-react";
import OpenBillPayModal from "./OpenBillPayModal";

const formatPrice = (value = 0) =>
    Number(value || 0).toLocaleString("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    });

/**
 * Open Bills: accepted dine-in orders still to be paid. Collapsed to a badge like HeldTransactions;
 * "Bayar" opens the payment modal for the transaction created at accept.
 */
export default function OpenBills({ openBills = [], bankAccounts = [] }) {
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
                    <span className="text-sm font-medium text-sky-700 dark:text-sky-300">Tagihan Terbuka</span>
                </span>
                <Chevron size={16} className="text-sky-600" />
            </button>

            {isExpanded && (
                <ul className="max-h-[140px] overflow-y-auto">
                    {openBills.map((bill) => (
                        <li
                            key={bill.order_id}
                            className="flex items-center justify-between gap-2 border-t border-sky-100/60 px-3 py-2 dark:border-sky-900/30"
                        >
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium text-slate-800 dark:text-slate-200">
                                    {bill.table ?? "Meja"} · {bill.invoice}
                                </p>
                                <p className="text-xs text-slate-500 dark:text-slate-400">
                                    {formatPrice(bill.grand_total)} · {bill.served ? "Sudah disajikan" : "Belum disajikan"}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setPayingBill(bill)}
                                className="h-9 rounded-lg bg-sky-500 px-3 text-xs font-semibold text-white hover:bg-sky-600"
                            >
                                Bayar
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            {payingBill && (
                <OpenBillPayModal bill={payingBill} bankAccounts={bankAccounts} onClose={() => setPayingBill(null)} />
            )}
        </div>
    );
}
