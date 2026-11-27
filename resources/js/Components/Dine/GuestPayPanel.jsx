import React, { useEffect, useRef, useState } from "react";
import axios from "axios";
import { useTranslation } from "react-i18next";
import { IconCheck, IconLoader2 } from "@tabler/icons-react";

const fmt = (v) => Number(v || 0).toLocaleString("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 });

// A pending attempt with no page and no QR for this long was never charged (the server stopped between claim and charge).
const STUCK_AFTER_MS = 60_000;

// dine.pay.* keys: the heading of each state, and the answer to each refusal of the pay request.
const TITLES = { pending: "waiting", paid: "paid", counter: "counter", failed: "failed", expired: "expired", gateway_error: "gatewayError" };
const REFUSALS = { not_payable: "notPayable", already_paid: "alreadyPaid", no_gateway: "counter", gateway_error: "gatewayError" };
const RETRYABLE = ["failed", "expired", "gateway_error"];

/**
 * The payment the guest sees, merged from the server's answers (page load, 5 s poll, pay request), which can arrive out of
 * order. The QR and the gateway link belong to one charge (`charge`, its attempt). The poll carries no QR, and a double tap
 * can be answered for an attempt whose charge is not stored yet: neither takes them away. A newer attempt replaces them
 * as soon as it brings its own QR or link.
 */
export function mergePayment(shown, next) {
    if (!next) return null;
    // A stale answer: attempts only grow, and paid is final.
    if (shown && (next.attempt < shown.attempt || (shown.state === "paid" && next.state !== "paid"))) return shown;

    const kept = next.state === "pending" && shown?.state === "pending" ? shown : null;
    if (next.state !== "pending" || (!next.qr && !next.payment_url)) {
        return { ...next, charge: kept?.charge ?? null, qr: kept?.qr ?? null, payment_url: kept?.payment_url ?? null };
    }
    const same = kept?.charge === next.attempt ? kept : null;

    return { ...next, charge: next.attempt, qr: next.qr ?? same?.qr ?? null, payment_url: next.payment_url ?? same?.payment_url ?? null };
}

/**
 * Paying online on the guest's order status page. `payment` is a mergePayment() result (null: nothing to show);
 * `onChange` receives the server's answer to a pay request.
 */
export default function GuestPayPanel({ token, payment, onChange }) {
    const { t } = useTranslation();
    const [busy, setBusy] = useState(false);
    const [method, setMethod] = useState("qris");
    const [refusal, setRefusal] = useState(null);
    const [seenState, setSeenState] = useState(payment?.state);
    const [stuckAttempt, setStuckAttempt] = useState(null);
    const heading = useRef(null);
    const focusCharge = useRef(false);
    const { state, attempt, charge } = payment ?? {};

    // A refusal answers the state it was given in: once the bill moves on, the new state's own text takes over.
    if (state !== seenState) {
        setSeenState(state);
        setRefusal(null);
    }

    const uncharged = state === "pending" && charge !== attempt;
    useEffect(() => {
        if (!uncharged) return undefined;
        const timer = setTimeout(() => setStuckAttempt(attempt), STUCK_AFTER_MS);

        return () => clearTimeout(timer);
    }, [uncharged, attempt]);

    // The guest's tap produced a charge: move keyboard and screen reader users to it.
    useEffect(() => {
        if (focusCharge.current && state === "pending" && charge === attempt) {
            focusCharge.current = false;
            heading.current?.focus();
        }
    }, [state, attempt, charge]);

    if (!payment) return null;

    // Only the method is sent: the server charges the bill's remaining total.
    const pay = async (chosen) => {
        setBusy(true);
        setRefusal(null);
        setMethod(chosen);
        focusCharge.current = true;
        try {
            const { data } = await axios.post(route("dine-order.pay", token), { method: chosen });
            onChange(data.payment);
        } catch ({ response }) {
            focusCharge.current = false;
            if (!response) setRefusal("offline");
            else if (response.status === 429) setRefusal("tooMany");
            else setRefusal(REFUSALS[response.data?.errors?.payment?.[0]] ?? "gatewayError");
        } finally {
            setBusy(false);
        }
    };

    const title = TITLES[state];
    const button = (label, chosen, style = "bg-primary-600 text-white hover:bg-primary-700") => (
        <button
            type="button"
            onClick={() => pay(chosen)}
            disabled={busy}
            className={`flex min-h-11 w-full items-center justify-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-60 ${style}`}
        >
            {busy && method === chosen && <IconLoader2 size={18} className="animate-spin" aria-hidden="true" />}
            {t(`dine.pay.${label}`)}
        </button>
    );

    return (
        <section data-testid="guest-pay" className="mt-4 flex flex-col items-center gap-3 rounded-xl border border-slate-200 p-4 text-center">
            <div aria-live="polite">
                {title && (
                    <h2
                        ref={heading}
                        tabIndex={-1}
                        className={`flex items-center justify-center gap-2 font-semibold ${state === "paid" ? "text-emerald-700" : "text-slate-800"}`}
                    >
                        {state === "paid" && <IconCheck size={20} aria-hidden="true" />}
                        {t(`dine.pay.${title}`)}
                    </h2>
                )}
                {refusal && refusal !== title && <p className="text-sm text-rose-700">{t(`dine.pay.${refusal}`)}</p>}
            </div>

            {state !== "paid" && state !== "counter" && <p className="text-2xl font-bold text-primary-700">{fmt(payment.amount)}</p>}

            {state === "pending" && payment.qr && (
                <>
                    <img src={payment.qr} alt="QRIS" width={240} height={240} />
                    <p className="text-sm text-slate-600">{t("dine.pay.scanQris")}</p>
                </>
            )}
            {state === "pending" && payment.payment_url && (
                <a
                    href={payment.payment_url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="flex min-h-11 w-full items-center justify-center rounded-xl bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700"
                >
                    {t("dine.pay.openWallet")}
                </a>
            )}

            {state === "can_pay" && button("button", "qris")}
            {(RETRYABLE.includes(state) || (uncharged && stuckAttempt === attempt)) && button("retry", method)}
            {(state === "can_pay" || state === "pending") &&
                button("otherMethod", "gateway", "border border-primary-500 bg-white text-primary-700 hover:bg-slate-50")}
            {(state === "failed" || state === "expired") && <p className="text-sm text-slate-500">{t("dine.pay.payAtCounterInstead")}</p>}
        </section>
    );
}
