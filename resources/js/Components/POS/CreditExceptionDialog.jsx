import React, { useState } from "react";
import { router } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import { IconAlertTriangle } from "@tabler/icons-react";
import Modal from "@/Components/Dashboard/Modal";

const BUTTON =
    "min-h-11 rounded-xl px-4 text-sm font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 disabled:opacity-50";
const PLAIN = `${BUTTON} border border-slate-300 text-slate-700 dark:border-slate-700 dark:text-slate-200`;

/**
 * Posts the POS sale. When the customer's credit rules refuse it, `dialog` offers the three ways on (REQ-007 J4):
 * pay now, ask an approver for an exception, or cancel.
 */
export default function useCreditException(onPayNow) {
    const { t } = useTranslation();
    const [refused, setRefused] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const close = () => setRefused(null);

    const submit = (payload, options) =>
        router.post(route("transactions.store"), payload, {
            ...options,
            onError: (errors) => {
                options.onError(errors);
                if (!errors?.credit) return;
                setError(null);
                setRefused({ message: errors.credit, payload, options });
            },
        });

    const ask = () => {
        setBusy(true);
        router.post(
            route("transactions.store"),
            { ...refused.payload, credit_exception: true },
            {
                onSuccess: (page) => {
                    // A closed shift also lands here, with only a flashed error: nothing was held then.
                    if (page.props.flash?.error) return setError(page.props.flash.error);
                    close();
                    refused.options.onSuccess(page);
                },
                onError: (errors) => setError(Object.values(errors)[0]),
                onFinish: () => setBusy(false),
            },
        );
    };

    const dialog = (
        <Modal
            show={refused !== null}
            onClose={close}
            closeable={!busy}
            maxWidth="md"
            title={
                <>
                    <IconAlertTriangle size={18} className="shrink-0 text-amber-600" aria-hidden="true" />
                    {refused?.message}
                </>
            }
        >
            {error && (
                <p role="alert" className="mb-3 text-sm text-danger-600 dark:text-danger-400">
                    {error}
                </p>
            )}
            <div className="flex flex-col gap-2" aria-busy={busy}>
                <button
                    type="button"
                    disabled={busy}
                    onClick={() => {
                        close();
                        onPayNow();
                    }}
                    className={`${BUTTON} bg-primary-600 text-white`}
                >
                    {t("pos.credit.payNow")}
                </button>
                <button type="button" disabled={busy} onClick={ask} className={PLAIN}>
                    {t("pos.credit.requestException")}
                </button>
                <button type="button" disabled={busy} onClick={close} className={PLAIN}>
                    {t("pos.credit.cancel")}
                </button>
            </div>
        </Modal>
    );

    return { submit, dialog };
}
