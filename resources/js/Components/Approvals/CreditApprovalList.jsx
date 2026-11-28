import React from "react";
import { useForm } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import { IconCheck, IconX } from "@tabler/icons-react";
import toast from "react-hot-toast";
import Textarea from "@/Components/Dashboard/TextArea";

const formatCurrency = (v = 0) => Number(v || 0).toLocaleString("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 });
const formatTime = (iso) => new Date(iso).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" });
const BUTTON = "inline-flex min-h-11 items-center gap-2 rounded-xl px-4 text-sm font-medium transition-colors disabled:opacity-50";

function RequestCard({ request }) {
    const { t } = useTranslation();
    const form = useForm({ reason: "" });
    // The reason's own error, or why the sale could not be booked (stock, shift, a changed total).
    const error = Object.values(form.errors)[0];
    const errorId = `credit-error-${request.id}`;
    const decide = (action) =>
        form.post(route(`credit-approvals.${action}`, request.id), {
            preserveScroll: true,
            onSuccess: (page) => toast.success(page.props.flash?.success),
            // Decided by someone else, the card leaves the list: the toast still says why.
            onError: (errors) => toast.error(Object.values(errors)[0]),
        });

    return (
        <li className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                {[
                    ["customer", request.customer],
                    ["cashier", request.cashier],
                    ["amount", formatCurrency(request.amount)],
                ].map(([key, value]) => (
                    <div key={key}>
                        <dt className="text-slate-500 dark:text-slate-400">{t(`creditApprovals.${key}`)}</dt>
                        <dd className="font-semibold text-slate-800 dark:text-white">{value}</dd>
                    </div>
                ))}
            </dl>
            <p className="mt-3 text-sm font-medium text-amber-700 dark:text-amber-300">{request.refusal}</p>
            <p className="mb-3 text-xs text-slate-500 dark:text-slate-400">{formatTime(request.created_at)}</p>
            <Textarea
                id={`credit-reason-${request.id}`}
                label={t("creditApprovals.reasonLabel")}
                rows={2}
                maxLength={500}
                required
                value={form.data.reason}
                onChange={(event) => form.setData("reason", event.target.value)}
                aria-invalid={Boolean(error)}
                aria-describedby={error ? errorId : undefined}
                errors={
                    error && (
                        <span id={errorId} role="alert">
                            {error}
                        </span>
                    )
                }
            />
            <div className="mt-4 flex gap-3">
                <button type="button" disabled={form.processing} onClick={() => decide("approve")} className={`${BUTTON} bg-success-500 text-white hover:bg-success-600`}>
                    <IconCheck size={18} aria-hidden="true" /> {t("creditApprovals.approve")}
                </button>
                <button type="button" disabled={form.processing} onClick={() => decide("deny")} className={`${BUTTON} border border-danger-200 text-danger-600 hover:bg-danger-50 dark:border-danger-800 dark:text-danger-400`}>
                    <IconX size={18} aria-hidden="true" /> {t("creditApprovals.deny")}
                </button>
            </div>
        </li>
    );
}

/** The credit exceptions waiting for this approver (REQ-007 J4.2), under the discount queue. */
export default function CreditApprovalList({ requests, heading = "h2" }) {
    const { t } = useTranslation();
    const Heading = heading;

    return (
        <section className="space-y-4">
            <Heading className="text-xl font-bold text-slate-900 dark:text-white">{t("creditApprovals.title")}</Heading>
            {requests.length > 0 ? (
                <ul className="space-y-4">
                    {requests.map((request) => (
                        <RequestCard key={request.id} request={request} />
                    ))}
                </ul>
            ) : (
                <p className="rounded-2xl border border-slate-200 bg-white p-8 text-center text-slate-500 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400">
                    {t("creditApprovals.empty")}
                </p>
            )}
        </section>
    );
}
