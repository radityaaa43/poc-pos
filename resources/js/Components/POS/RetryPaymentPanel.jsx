import React, { useState } from "react";
import { router } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import Input from "@/Components/Dashboard/Input";
import Select from "@/Components/Dashboard/Select";
import { useAuthorization } from "@/Utils/authorization";

const buttonClass =
    "h-11 rounded-xl px-4 text-sm font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-50";

const SAME_METHOD_RETRY = ["qris", "midtrans", "xendit"];

const formatPrice = (value = 0) =>
    Number(value || 0).toLocaleString("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    });

/**
 * Recovery actions for a POS sale whose payment failed or expired: the same method again, or any other one.
 * `retryable` comes from the server (failed or expired, and not auto-expired and restocked).
 */
export default function RetryPaymentPanel({ transaction, retryable, bankAccounts = [], paymentGateways = [] }) {
    const { t } = useTranslation();
    const { can } = useAuthorization();
    const [choosing, setChoosing] = useState(false);
    const [method, setMethod] = useState("cash");
    const [cashReceived, setCashReceived] = useState("");
    const [bankAccountId, setBankAccountId] = useState("");
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState({});

    if (!can("transactions-access")) {
        return null;
    }

    const errorAlert = Object.keys(errors).length > 0 && (
        <div role="alert" className="text-sm text-danger-600 dark:text-danger-400">
            {Object.values(errors).map((message) => (
                <p key={message}>{message}</p>
            ))}
        </div>
    );

    // A refused retry reloads the page with retryable=false (the sale was restocked meanwhile): the refusal must stay visible.
    if (!retryable) {
        return errorAlert || null;
    }

    // From the server: a pay-later bill with returns is asked less than its grand total.
    const total = Number(transaction.retry_amount ?? transaction.grand_total ?? 0);
    const change = Math.max(Number(cashReceived || 0) - total, 0);

    const submit = (payload) =>
        router.post(route("transactions.retry-payment", transaction.id), payload, {
            preserveScroll: true,
            onStart: () => {
                setProcessing(true);
                setErrors({});
            },
            onError: setErrors,
            onFinish: () => setProcessing(false),
        });

    const payOtherWay = (event) => {
        event.preventDefault();
        submit({
            payment_method: method,
            ...(method === "cash" && { cash_amount: Number(cashReceived) }),
            ...(method === "bank_transfer" && { bank_account_id: Number(bankAccountId || bankAccounts[0]?.id) }),
        });
    };

    return (
        <div className="mt-4 space-y-3 rounded-xl border border-danger-200 bg-danger-50/60 p-3 dark:border-danger-900/50 dark:bg-danger-950/20 sm:p-4">
            <p className="text-sm font-semibold text-danger-700 dark:text-danger-400">
                {t(`pos.retryPayment.${transaction.payment_status === "expired" ? "expired" : "failed"}`)}
            </p>

            {total < Number(transaction.grand_total || 0) && (
                <p className="text-sm font-semibold text-slate-900 dark:text-white">
                    {t("pos.retryPayment.due")}: {formatPrice(total)}
                </p>
            )}

            <div className="flex flex-wrap gap-2">
                {/* Split and bank-transfer sales need more than the method (tenders, an account): they go through "other way". */}
                {SAME_METHOD_RETRY.includes(transaction.payment_method) && (
                    <button
                        type="button"
                        disabled={processing}
                        onClick={() => submit({ payment_method: transaction.payment_method })}
                        className={`${buttonClass} bg-primary-500 text-white hover:bg-primary-600`}
                    >
                        {t("pos.retryPayment.retry")}
                    </button>
                )}
                <button
                    type="button"
                    disabled={processing}
                    aria-expanded={choosing}
                    onClick={() => setChoosing((open) => !open)}
                    className={`${buttonClass} border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800`}
                >
                    {t("pos.retryPayment.other")}
                </button>
            </div>

            {choosing && (
                <form onSubmit={payOtherWay} className="space-y-3">
                    <Select
                        id="retry-payment-method"
                        label={t("pos.retryPayment.method")}
                        value={method}
                        onChange={(e) => setMethod(e.target.value)}
                    >
                        <option value="cash">{t("pos.retryPayment.cash")}</option>
                        {paymentGateways.map((gateway) => (
                            <option key={gateway.value} value={gateway.value}>
                                {gateway.label}
                            </option>
                        ))}
                        {/* QRIS is charged through whichever of Midtrans or Xendit is ready */}
                        {paymentGateways.some((gateway) => ["midtrans", "xendit"].includes(gateway.value)) && (
                            <option value="qris">{t("pos.retryPayment.qris")}</option>
                        )}
                    </Select>

                    {method === "cash" && (
                        <div>
                            <Input
                                type="number"
                                inputMode="numeric"
                                min="0"
                                label={t("pos.retryPayment.cashReceived")}
                                aria-label={t("pos.retryPayment.cashReceived")}
                                value={cashReceived}
                                onChange={(e) => setCashReceived(e.target.value)}
                            />
                            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                {t("pos.retryPayment.change")}: {formatPrice(change)}
                            </p>
                        </div>
                    )}

                    {method === "bank_transfer" && (
                        <Select
                            id="retry-payment-bank"
                            label={t("pos.retryPayment.bankAccount")}
                            value={bankAccountId}
                            onChange={(e) => setBankAccountId(e.target.value)}
                        >
                            {bankAccounts.map((bank) => (
                                <option key={bank.id} value={bank.id}>
                                    {bank.bank_name} · {bank.account_number}
                                </option>
                            ))}
                        </Select>
                    )}

                    <button
                        type="submit"
                        disabled={processing}
                        className={`${buttonClass} bg-primary-500 text-white hover:bg-primary-600`}
                    >
                        {t("pos.retryPayment.pay")}
                    </button>
                </form>
            )}

            {errorAlert}
        </div>
    );
}
