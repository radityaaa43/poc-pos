import React from "react";
import { Head } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import { addedTax, billSubtotal, includedTax, includedTaxLabel, taxLabel } from "@/Utils/billTotals";
import GuestLayout from "@/Layouts/GuestLayout";

const formatPrice = (v = 0) => Number(v || 0).toLocaleString("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 });
const formatDate = (v) => v ? new Date(v).toLocaleString("id-ID", { dateStyle: "full", timeStyle: "short" }) : "-";

const statusBadge = (status) => {
    const styles = { paid: "bg-emerald-100 text-emerald-700", pending: "bg-amber-100 text-amber-700", unpaid: "bg-rose-100 text-rose-700", pending_approval: "bg-slate-100 text-slate-700", failed: "bg-rose-100 text-rose-700", expired: "bg-slate-100 text-slate-700" };
    const labels = { paid: "Lunas", pending: "Menunggu", unpaid: "Belum Lunas", pending_approval: "Menunggu Approval", failed: "Gagal", expired: "Kedaluwarsa" };
    return <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ${styles[status] || "bg-slate-100 text-slate-600"}`}>{labels[status] || status}</span>;
};

export default function TransactionDetail({ transaction, token, error }) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={`Invoice ${transaction.invoice}`} />
            <div className="min-h-screen bg-slate-50 py-8 px-4">
                <div className="max-w-lg mx-auto bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                    <div className="bg-gradient-to-r from-primary-500 to-primary-700 px-6 py-5 text-white text-center">
                        <p className="text-sm opacity-80">INVOICE</p>
                        <p className="text-xl font-bold mt-1">{transaction.invoice}</p>
                        <p className="text-sm opacity-80 mt-1">{formatDate(transaction.created_at)}</p>
                    </div>

                    <div className="p-6 space-y-4">
                        <div className="flex justify-between items-center">
                            <span className="text-slate-500">Status</span>
                            {statusBadge(transaction.payment_status)}
                        </div>

                        <div className="border-t border-slate-100 pt-4">
                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-400 mb-2">Item</p>
                            {transaction.details.map((item, i) => (
                                <div key={i} className="flex justify-between py-1.5 text-sm">
                                    <span className="text-slate-700">{item.product_title} x{item.qty}</span>
                                    <span className="font-medium">{formatPrice(item.price)}</span>
                                </div>
                            ))}
                        </div>

                        <div className="border-t border-slate-100 pt-4 space-y-1.5 text-sm">
                            <div className="flex justify-between text-slate-500"><span>Subtotal</span><span>{formatPrice(billSubtotal(transaction))}</span></div>
                            {transaction.discount > 0 && <div className="flex justify-between text-slate-500"><span>Diskon</span><span>-{formatPrice(transaction.discount)}</span></div>}
                            {transaction.service_charge > 0 && <div className="flex justify-between text-slate-500"><span>{t("pos.bill.serviceCharge")}</span><span>{formatPrice(transaction.service_charge)}</span></div>}
                            {addedTax(transaction) > 0 && <div className="flex justify-between text-slate-500"><span>{taxLabel(transaction, t)}</span><span>{formatPrice(addedTax(transaction))}</span></div>}
                            {includedTax(transaction) > 0 && <div className="flex justify-between text-slate-500"><span>{includedTaxLabel(transaction, t)}</span><span>{formatPrice(includedTax(transaction))}</span></div>}
                            {transaction.shipping_cost > 0 && <div className="flex justify-between text-slate-500"><span>Ongkir</span><span>{formatPrice(transaction.shipping_cost)}</span></div>}
                            <div className="flex justify-between font-bold text-lg pt-2 border-t border-slate-200">
                                <span>Total</span>
                                <span className="text-primary-600">{formatPrice(transaction.grand_total)}</span>
                            </div>
                            {transaction.amount_due != null && (
                                <div className="flex justify-between font-bold text-lg">
                                    <span>Sisa tagihan</span>
                                    <span className="text-primary-600">{formatPrice(transaction.amount_due)}</span>
                                </div>
                            )}
                        </div>

                        {transaction.payment_method === "cash" && transaction.cash > 0 && (
                            <div className="border-t border-slate-100 pt-4 space-y-1.5 text-sm">
                                <div className="flex justify-between text-slate-500"><span>Tunai</span><span>{formatPrice(transaction.cash)}</span></div>
                                {transaction.change > 0 && <div className="flex justify-between text-slate-500"><span>Kembali</span><span>{formatPrice(transaction.change)}</span></div>}
                            </div>
                        )}

                        {transaction.receivable && transaction.receivable.status !== "paid" && (
                            <div className="bg-amber-50 border border-amber-200 rounded-xl p-4 space-y-3">
                                <p className="text-sm font-semibold text-amber-800">Sisa Pembayaran</p>
                                <div className="flex justify-between text-sm">
                                    <span className="text-amber-700">Jatuh tempo: {formatDate(transaction.receivable.due_date)}</span>
                                    <span className="font-bold text-amber-900">{formatPrice(transaction.receivable.remaining)}</span>
                                </div>
                                {error && <p role="alert" className="text-sm font-medium text-rose-700">{error}</p>}
                                <a
                                    href={route("portal.receivable.pay", [transaction.receivable.id, { token }])}
                                    as="button"
                                    method="post"
                                    className="block w-full text-center px-4 py-2.5 rounded-xl bg-primary-500 hover:bg-primary-600 text-white text-sm font-medium transition-colors"
                                    onClick={(e) => {
                                        e.preventDefault();
                                        const form = document.createElement("form");
                                        form.method = "POST";
                                        form.action = route("portal.receivable.pay", [transaction.receivable.id, { token }]);
                                        document.body.appendChild(form);
                                        form.submit();
                                    }}
                                >
                                    Bayar Sekarang
                                </a>
                            </div>
                        )}
                    </div>

                    <div className="px-6 py-4 border-t border-slate-100 text-center text-xs text-slate-400">
                        Terima kasih telah berbelanja
                    </div>
                </div>
            </div>
        </>
    );
}

TransactionDetail.layout = (page) => <GuestLayout children={page} />;
