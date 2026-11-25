import React, { useState } from "react";
import { router } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import toast from "react-hot-toast";
import Modal from "@/Components/Dashboard/Modal";
import { useOnlineStatus } from "@/Context/OnlineStatusContext";

const formatPrice = (value = 0) =>
    Number(value || 0).toLocaleString("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    });

const inputClass =
    "w-full h-11 rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-800 dark:text-white";

/**
 * Settles an unpaid bill: a dine-in order's bill (POST dine-orders.pay) or a pay-later POS sale
 * (POST transactions.settle), with the same tenders[] payload as POS checkout. It never creates a new
 * transaction, and it is online only (a settle is never queued).
 * Gateway methods (QRIS, Midtrans, Xendit) leave the bill pending and open the receipt with the QR.
 * Used by the POS "Belum Lunas" section and by Transactions History.
 */
export default function OpenBillPayModal({ bill, bankAccounts = [], paymentGateways = [], onClose }) {
    const { t } = useTranslation();
    const online = useOnlineStatus();
    const [method, setMethod] = useState("cash");
    const [cashReceived, setCashReceived] = useState("");
    const [bankAccountId, setBankAccountId] = useState(bankAccounts[0]?.id ?? "");
    const [isSubmitting, setIsSubmitting] = useState(false);

    if (!bill) {
        return null;
    }

    const total = Number(bill.amount ?? bill.grand_total ?? 0);
    const received = Number(cashReceived || 0);
    const change = Math.max(received - total, 0);

    const handleSubmit = (event) => {
        event.preventDefault();

        if (method === "cash" && received < total) {
            toast.error("Uang tunai kurang dari total tagihan");
            return;
        }

        setIsSubmitting(true);
        router.post(
            bill.order_id ? route("dine-orders.pay", bill.order_id) : route("transactions.settle", bill.id),
            {
                tenders: [
                    {
                        method,
                        amount: total,
                        cash_received: method === "cash" ? received : null,
                        bank_account_id: method === "bank_transfer" ? bankAccountId : null,
                    },
                ],
            },
            {
                onError: (errors) => toast.error(errors.bill ?? Object.values(errors)[0] ?? "Gagal membayar tagihan"),
                onFinish: () => setIsSubmitting(false),
            }
        );
    };

    return (
        <Modal show onClose={onClose} title={`${t("pos.unpaidBills.pay")} ${bill.label ?? bill.table ?? "Meja"} · ${bill.invoice}`} maxWidth="md">
            <form onSubmit={handleSubmit} className="space-y-4">
                <div className="flex items-center justify-between rounded-xl bg-slate-50 px-4 py-3 dark:bg-slate-800">
                    <span className="text-sm text-slate-500 dark:text-slate-400">Total tagihan</span>
                    <span className="text-lg font-bold text-slate-900 dark:text-white">{formatPrice(total)}</span>
                </div>

                <div>
                    <label htmlFor="open-bill-method" className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">
                        Metode pembayaran
                    </label>
                    <select id="open-bill-method" className={inputClass} value={method} onChange={(e) => setMethod(e.target.value)}>
                        <option value="cash">Tunai</option>
                        {paymentGateways.map((gateway) => (
                            <option key={gateway.value} value={gateway.value}>
                                {gateway.label}
                            </option>
                        ))}
                        {/* QRIS is charged through whichever of Midtrans or Xendit is ready */}
                        {paymentGateways.some((gateway) => ["midtrans", "xendit"].includes(gateway.value)) && (
                            <option value="qris">QRIS</option>
                        )}
                    </select>
                </div>

                {method === "cash" && (
                    <div>
                        <label htmlFor="open-bill-cash" className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">
                            Uang diterima
                        </label>
                        <input
                            id="open-bill-cash"
                            type="number"
                            inputMode="numeric"
                            min="0"
                            className={inputClass}
                            value={cashReceived}
                            onChange={(e) => setCashReceived(e.target.value)}
                        />
                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Kembalian: {formatPrice(change)}</p>
                    </div>
                )}

                {method === "bank_transfer" && (
                    <div>
                        <label htmlFor="open-bill-bank" className="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">
                            Rekening tujuan
                        </label>
                        <select id="open-bill-bank" className={inputClass} value={bankAccountId} onChange={(e) => setBankAccountId(e.target.value)}>
                            {bankAccounts.map((bank) => (
                                <option key={bank.id} value={bank.id}>
                                    {bank.bank_name} · {bank.account_number}
                                </option>
                            ))}
                        </select>
                    </div>
                )}

                <div className="flex justify-end gap-2">
                    <button
                        type="button"
                        onClick={onClose}
                        className="h-11 rounded-xl border border-slate-200 px-4 text-sm font-medium text-slate-600 dark:border-slate-700 dark:text-slate-300"
                    >
                        Batal
                    </button>
                    <button
                        type="submit"
                        disabled={isSubmitting || !online}
                        className="h-11 rounded-xl bg-primary-500 px-5 text-sm font-semibold text-white hover:bg-primary-600 disabled:opacity-50"
                    >
                        {t("pos.unpaidBills.pay")}
                    </button>
                </div>
            </form>
        </Modal>
    );
}
