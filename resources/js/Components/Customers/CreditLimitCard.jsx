import React from "react";
import { useForm } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import toast from "react-hot-toast";
import { IconAlertTriangle } from "@tabler/icons-react";
import Input from "@/Components/Dashboard/Input";

const formatPrice = (value = 0) =>
    Number(value || 0).toLocaleString("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    });

/**
 * "Batas piutang" of a credit customer: the limit and what they owe. Approvers can set it, or empty the
 * field for no limit; everyone else only reads it (the server enforces this).
 */
export default function CreditLimitCard({ customer, standing, canApprove }) {
    const { t } = useTranslation();
    const { data, setData, patch, processing, errors } = useForm({
        credit_limit: standing.credit_limit,
    });

    const submit = (event) => {
        event.preventDefault();
        patch(route("customers.credit-limit", customer.id), {
            preserveScroll: true,
            onSuccess: () => toast.success(t("customers.credit.limitSaved")),
        });
    };

    return (
        <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <h2 className="text-lg font-semibold text-slate-900 dark:text-white">
                {t("customers.credit.limitLabel")}:{" "}
                {standing.credit_limit === null ? t("customers.credit.noLimit") : formatPrice(standing.credit_limit)}
            </h2>
            <p className="mt-1 text-sm text-slate-600 dark:text-slate-300">
                {t("customers.credit.used")}: {formatPrice(standing.used)}
            </p>
            {standing.has_overdue && (
                <p className="mt-2 flex items-center gap-2 text-sm font-semibold text-amber-700 dark:text-amber-300">
                    <IconAlertTriangle size={16} aria-hidden="true" />
                    {t("customers.credit.overdue")}
                </p>
            )}
            {canApprove && (
                <form onSubmit={submit} className="mt-4 max-w-sm space-y-2">
                    <Input
                        type="number"
                        inputMode="numeric"
                        min="0"
                        step="1"
                        label={t("customers.credit.limitLabel")}
                        value={data.credit_limit ?? ""}
                        // Empty is "no limit" (null), never 0: 0 means no credit at all.
                        onChange={(e) => setData("credit_limit", e.target.value === "" ? null : e.target.value)}
                        aria-describedby="credit-limit-help"
                        errors={errors.credit_limit && <span role="alert">{errors.credit_limit}</span>}
                    />
                    <p id="credit-limit-help" className="text-xs text-slate-500 dark:text-slate-400">
                        {t("customers.credit.limitHelp")}
                    </p>
                    <button
                        type="submit"
                        disabled={processing}
                        className="min-h-11 rounded-xl bg-primary-500 px-5 text-sm font-semibold text-white transition hover:bg-primary-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:opacity-60"
                    >
                        {t("customers.credit.save")}
                    </button>
                </form>
            )}
        </section>
    );
}
