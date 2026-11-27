import React, { useEffect, useRef, useState } from "react";
import { Head, router } from "@inertiajs/react";
import { IconCheck, IconClock, IconX, IconRefresh } from "@tabler/icons-react";
import GuestPayPanel, { mergePayment } from "@/Components/Dine/GuestPayPanel";

const fmt = (v) => Number(v || 0).toLocaleString("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 });

const PROGRESS_STEPS = ["Diterima", "Menunggu Pembayaran", "Sedang dibuat", "Siap", "Disajikan", "Selesai"];

// Statuses that can still change, so the page keeps polling.
const ACTIVE = ["submitted", "accepted", "preparing", "ready", "served"];

const STATUS_CONFIG = {
    submitted: { label: "Menunggu Konfirmasi", color: "bg-amber-100 text-amber-700", icon: IconClock, desc: "Pesanan Anda sedang menunggu konfirmasi dari staff." },
    // payment comes first: the kitchen starts only on a paid bill
    waiting_payment: { label: "Menunggu Pembayaran", color: "bg-amber-100 text-amber-700", icon: IconClock, desc: "Pesanan diterima. Silakan menuju kasir untuk membayar, setelah itu pesanan mulai dibuat." },
    accepted: { label: "Diterima", color: "bg-primary-100 text-primary-700", icon: IconCheck, desc: "Pembayaran diterima. Pesanan akan segera dibuat." },
    preparing: { label: "Sedang dibuat", color: "bg-primary-100 text-primary-700", icon: IconClock, desc: "Pesanan Anda sedang dibuat." },
    ready: { label: "Siap", color: "bg-primary-100 text-primary-700", icon: IconCheck, desc: "Pesanan Anda sudah siap." },
    served: { label: "Disajikan", color: "bg-primary-100 text-primary-700", icon: IconCheck, desc: "Pesanan sudah disajikan." },
    completed: { label: "Selesai", color: "bg-emerald-100 text-emerald-700", icon: IconCheck, desc: "Pesanan sudah selesai." },
    rejected: { label: "Ditolak", color: "bg-rose-100 text-rose-700", icon: IconX, desc: "Pesanan ditolak oleh staff." },
    cancelled: { label: "Dibatalkan", color: "bg-slate-100 text-slate-500", icon: IconX, desc: "Pesanan dibatalkan." },
};

export default function DineOrderStatus({ order, table, storeName, payment: pagePayment }) {
    const [currentOrder, setCurrentOrder] = useState(order);
    const [refreshing, setRefreshing] = useState(false);
    const [payment, setPayment] = useState(() => mergePayment(null, pagePayment));
    // The attempt whose QR this page already received or asked for (the page load carries it).
    const qrAsked = useRef(pagePayment?.payment_url ? pagePayment.attempt : null);

    // A charge this page did not start (another tab, say) arrives through the poll, which has no QR: ask for it once.
    const qrMissing = payment?.state === "pending" && payment.charge === payment.attempt && !payment.qr;
    const attempt = payment?.attempt;
    useEffect(() => {
        if (qrMissing && qrAsked.current !== attempt) {
            qrAsked.current = attempt;
            router.reload({ only: ["payment"], onSuccess: (page) => setPayment((shown) => mergePayment(shown, page.props.payment)) });
        }
    }, [qrMissing, attempt]);

    useEffect(() => {
        if (ACTIVE.includes(currentOrder.status)) {
            const interval = setInterval(async () => {
                setRefreshing(true);
                try {
                    const res = await fetch(route("dine-order.status-check", currentOrder.access_token), {
                        headers: { Accept: "application/json" },
                    });
                    const data = await res.json();
                    if (data.order) {
                        // status-check returns minimal scalar fields — merge so items/product rows from the initial render are kept
                        setCurrentOrder((prev) => ({ ...prev, ...data.order }));
                        setPayment((shown) => mergePayment(shown, data.payment));
                        if (!ACTIVE.includes(data.order.status)) {
                            clearInterval(interval);
                        }
                    }
                } catch (_) {}
                setRefreshing(false);
            }, 5000);
            return () => clearInterval(interval);
        }
    }, [currentOrder.status, currentOrder.access_token]);

    // progress shown once the order is accepted: Diterima, Menunggu Pembayaran, Sedang dibuat, Siap, Disajikan, Selesai
    const statusKey = currentOrder.status === "accepted" && !currentOrder.is_paid ? "waiting_payment" : currentOrder.status;
    const stepIndex = { waiting_payment: 1, accepted: 1, preparing: 2, ready: 3, served: 4, completed: 5 }[statusKey];
    const status = STATUS_CONFIG[statusKey] ?? { label: currentOrder.status, color: "bg-slate-100 text-slate-600", icon: IconClock, desc: "" };
    const StatusIcon = status.icon;
    // The pay panel says what to do instead, unless the guest has to pay at the counter.
    const desc = statusKey === "waiting_payment" && payment && payment.state !== "counter" ? "" : status.desc;

    return (
        <>
            <Head title={`Status Pesanan — ${storeName}`} />
            <div className="min-h-screen bg-slate-50 flex flex-col items-center justify-center px-4 py-8">
                <div className="w-full max-w-md bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                    <div className="bg-gradient-to-r from-primary-500 to-primary-700 px-6 py-5 text-white text-center">
                        <p className="text-sm opacity-80">PESANAN DINE-IN</p>
                        <p className="text-lg font-bold mt-1">Meja {table.name}</p>
                        <p className="text-xs opacity-80 mt-1">{storeName}</p>
                    </div>

                    <div className="p-6">
                        <div className={`flex items-center gap-3 p-4 rounded-xl ${status.color} mb-6`}>
                            <StatusIcon size={24} />
                            <div>
                                <p className="font-semibold" data-testid="dine-status">{status.label}</p>
                                <p className="text-sm opacity-80">{desc}</p>
                            </div>
                        </div>

                        {stepIndex !== undefined && (
                            <ol className="mb-6 grid grid-cols-6 gap-1 text-center text-[11px] leading-tight" aria-label="Progres pesanan">
                                {PROGRESS_STEPS.map((step, index) => (
                                    <li
                                        key={step}
                                        aria-current={index === stepIndex ? "step" : undefined}
                                        className={index <= stepIndex ? "font-semibold text-primary-700" : "text-slate-400"}
                                    >
                                        <span className={`mx-auto mb-1 block h-1.5 rounded-full ${index <= stepIndex ? "bg-primary-500" : "bg-slate-200"}`} />
                                        {step}
                                    </li>
                                ))}
                            </ol>
                        )}

                        <div className="space-y-2 mb-4">
                            {(currentOrder.items ?? []).map((item) => (
                                <div key={item.id} className="flex justify-between items-center py-2 border-b border-slate-100 last:border-0">
                                    <div className="flex items-center gap-2">
                                        <span className="w-6 h-6 rounded-full bg-slate-100 flex items-center justify-center text-xs font-medium text-slate-600">
                                            {item.qty}
                                        </span>
                                        <span className="text-sm text-slate-700">{item.product?.title ?? "Produk"}</span>
                                    </div>
                                    <span className="text-sm text-slate-600">
                                        {fmt(item.price)}
                                    </span>
                                </div>
                            ))}
                        </div>

                        <div className="flex justify-between items-center pt-3 border-t border-slate-200">
                            <span className="font-semibold text-slate-700">Total</span>
                            <span className="font-bold text-lg text-primary-600">{fmt(currentOrder.subtotal)}</span>
                        </div>

                        <GuestPayPanel
                            token={currentOrder.access_token}
                            payment={payment}
                            onChange={(next) => {
                                // The pay answer carries this charge's QR, if the gateway gave one.
                                if (next?.payment_url) qrAsked.current = next.attempt;
                                setPayment((shown) => mergePayment(shown, next));
                            }}
                        />

                        {currentOrder.notes && (
                            <div className="mt-4 p-3 bg-slate-50 rounded-lg">
                                <p className="text-xs text-slate-500 mb-1">Catatan:</p>
                                <p className="text-sm text-slate-700">{currentOrder.notes}</p>
                            </div>
                        )}

                        <div className="mt-4 flex flex-col gap-2">
                            <div className="flex items-center justify-between text-xs text-slate-400">
                                <span>No. Pesanan: {currentOrder.id}</span>
                                <button
                                    onClick={() => window.location.reload()}
                                    className="flex items-center gap-1 hover:text-slate-600 transition-colors"
                                >
                                    <IconRefresh size={12} className={refreshing ? "animate-spin" : ""} />
                                    Refresh
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}
