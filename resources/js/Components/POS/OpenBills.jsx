import React, { useEffect, useRef, useState } from "react";
import axios from "axios";
import { Link } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import { IconChevronDown, IconChevronUp, IconClock } from "@tabler/icons-react";
import OpenBillPayModal from "./OpenBillPayModal";

const POLL_MS = 10000;
const NO_BILLS = [];

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

export default function OpenBills({ openBills: initialBills = NO_BILLS, bankAccounts = [], paymentGateways = [] }) {
    const { t } = useTranslation();
    const [openBills, setOpenBills] = useState(initialBills);
    const [isExpanded, setIsExpanded] = useState(false);
    const [payingBill, setPayingBill] = useState(null);

    // Counts list updates: a poll response is applied only if nothing newer started or arrived since it was sent.
    const latest = useRef(0);

    // A new prop (after a payment or any Inertia visit) replaces the polled list, and drops polls still in flight.
    useEffect(() => {
        latest.current += 1;
        setOpenBills(initialBills);
    }, [initialBills]);

    // The guest may start or finish paying online while the cashier looks at the list: refresh it every 10 s.
    // A failed request keeps the last list: stale is safe, the server re-checks the bill when it is paid.
    // These effects sit above the early return so a bill appears even when the list is empty now.
    useEffect(() => {
        const refresh = async () => {
            if (document.hidden) {
                return;
            }

            const request = ++latest.current;

            try {
                const { data } = await axios.get(route("transactions.open-bills"));
                // An older response must not overwrite a newer list; a 200 that is not our JSON (captive portal) is no list.
                if (request === latest.current && Array.isArray(data?.openBills)) {
                    setOpenBills(data.openBills);
                }
            } catch {
                // offline or a server error: keep what is on screen
            }
        };

        // Coming back to the tab: the list may be minutes old (timers are throttled while hidden).
        const onVisible = () => refresh();

        const timer = setInterval(refresh, POLL_MS);
        document.addEventListener("visibilitychange", onVisible);

        return () => {
            clearInterval(timer);
            document.removeEventListener("visibilitychange", onVisible);
        };
    }, []);

    // The dialog outlives a poll that no longer lists its bill (paid or cancelled meanwhile): keep it, say so, and do not submit.
    const liveBill = payingBill && openBills.find((bill) => bill.id === payingBill.id);
    const modal = payingBill && (
        <OpenBillPayModal
            bill={liveBill ?? payingBill}
            gone={!liveBill}
            bankAccounts={bankAccounts}
            paymentGateways={paymentGateways}
            onClose={() => setPayingBill(null)}
        />
    );

    if (openBills.length === 0) {
        // The same two slots as below, so an open dialog is not remounted (and keeps what was typed) when the list empties.
        return <>{null}{modal}</>;
    }

    const Chevron = isExpanded ? IconChevronUp : IconChevronDown;

    return (
        <>
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
                                        {/* the chip below says more than "Menunggu Pembayaran" while the guest pays online */}
                                        {bill.order_status && !bill.awaiting_online && ` · ${FULFILLMENT_LABELS[bill.order_status] ?? bill.order_status}`} · {billAge(bill.created_at, t)}
                                    </p>
                                    {(bill.awaiting_online || bill.late_payment) && (
                                        <p className="mt-1 flex flex-wrap items-center gap-1">
                                            {bill.awaiting_online && (
                                                <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">
                                                    <IconClock size={12} aria-hidden="true" />
                                                    {t("pos.unpaidBills.awaitingOnline")}
                                                </span>
                                            )}
                                            {bill.late_payment && (
                                                <span className="inline-flex items-center rounded-full bg-danger-100 px-2 py-0.5 text-xs font-medium text-danger-700 dark:bg-danger-900/30 dark:text-danger-400">
                                                    {t("transactions.lateRefund.badge")}
                                                </span>
                                            )}
                                        </p>
                                    )}
                                </div>
                                {bill.pending && !bill.awaiting_online ? (
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
            </div>
            {modal}
        </>
    );
}
