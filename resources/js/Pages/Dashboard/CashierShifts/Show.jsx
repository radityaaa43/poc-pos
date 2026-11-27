import React, { useCallback, useEffect, useMemo, useState } from "react";
import { Head, Link, router, usePage } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import DashboardLayout from "@/Layouts/DashboardLayout";
import ShiftCloseBlockedList from "./ShiftCloseBlockedList";
import { getUnsyncedCount } from "@/Utils/offlineDb";
import {
    IconArrowLeft,
    IconCashBanknote,
    IconPrinter,
    IconReceipt,
    IconRotateClockwise2,
    IconWallet,
} from "@tabler/icons-react";
import { useAuthorization } from "@/Utils/authorization";

const formatCurrency = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value);

const formatDateTime = (value) => {
    if (!value) return "-";

    return new Intl.DateTimeFormat("id-ID", {
        dateStyle: "full",
        timeStyle: "short",
    }).format(new Date(value));
};

function MetricCard({ title, value, icon: Icon }) {
    return (
        <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <div className="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
                <Icon size={18} />
                <span>{title}</span>
            </div>
            <p className="mt-3 text-xl font-semibold text-slate-900 dark:text-white">
                {value}
            </p>
        </div>
    );
}

export default function Show({ cashierShift, canForceClose = false, closeBlockers = [] }) {
    const { auth, errors } = usePage().props;
    const { can } = useAuthorization();
    const { t } = useTranslation();
    const [actualCash, setActualCash] = useState(
        cashierShift.actual_cash !== null ? String(cashierShift.actual_cash) : ""
    );
    const [closeNotes, setCloseNotes] = useState(cashierShift.close_notes || "");

    const canCloseShift = useMemo(() => {
        if (cashierShift.status !== "open") return false;

        return (
            can("cashier-shifts-close") &&
            (cashierShift.user?.id === auth?.user?.id ||
                auth?.super ||
                canForceClose)
        );
    }, [
        auth?.super,
        auth?.user?.id,
        can,
        canForceClose,
        cashierShift.status,
        cashierShift.user?.id,
    ]);

    // A held shift closes only by force; without the permission the plain submit lets the server say why not.
    const forcing = closeBlockers.length > 0 && Boolean(canForceClose || auth?.super);

    // A forced close books the unpaid bills as a shortfall, so the preview takes them off too (REQ-003 AC-13).
    const booked = forcing
        ? closeBlockers.filter((row) => row.type === "bill").reduce((sum, row) => sum + row.amount, 0)
        : 0;
    const actualCashNumber = Number(actualCash || 0);
    const difference = actualCash === ""
        ? null
        : actualCashNumber - Number(cashierShift.expected_cash || 0) - booked;

    // BR-12: sales the shift's cashier queued on this device must reach the shift first.
    const cashierId = cashierShift.user?.id ?? "guest";
    const [hasUnsynced, setHasUnsynced] = useState(false);
    const checkUnsynced = useCallback(async () => {
        // No IndexedDB means nothing can have been queued here.
        const pending = (await getUnsyncedCount(cashierId).catch(() => 0)) > 0;
        setHasUnsynced(pending);
        return pending;
    }, [cashierId]);

    useEffect(() => {
        if (!canCloseShift) return undefined;

        checkUnsynced();
        window.addEventListener("focus", checkUnsynced);
        return () => window.removeEventListener("focus", checkUnsynced);
    }, [canCloseShift, checkUnsynced]);

    const handleCloseShift = async (event) => {
        event.preventDefault();

        // Read again: another tab may have queued a sale since this page loaded.
        if (await checkUnsynced()) return;

        router.post(route("cashier-shifts.close", cashierShift.id), {
            actual_cash: actualCashNumber,
            close_notes: closeNotes,
            ...(forcing && { force: true }),
        });
    };

    return (
        <>
            <Head title={`Shift #${cashierShift.id}`} />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <Link
                            href={route("cashier-shifts.index")}
                            className="mb-3 inline-flex items-center gap-2 text-sm font-medium text-slate-500 transition hover:text-primary-600 dark:text-slate-400 dark:hover:text-primary-400"
                        >
                            <IconArrowLeft size={16} />
                            <span>Kembali ke histori shift</span>
                        </Link>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">
                            Shift Kasir {cashierShift.user?.name || "-"}
                        </h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400">
                            Dibuka {formatDateTime(cashierShift.opened_at)}
                        </p>
                    </div>
                    {cashierShift.status !== "open" && (
                        <button
                            type="button"
                            onClick={() =>
                                window.open(
                                    route("cashier-shifts.report", {
                                        cashierShift: cashierShift.id,
                                        type: "z",
                                    }),
                                    "_blank"
                                )
                            }
                            className="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                        >
                            <IconPrinter size={18} />
                            <span>Cetak Laporan Z</span>
                        </button>
                    )}
                    <span
                        className={`inline-flex rounded-full px-3 py-1.5 text-sm font-semibold ${
                            cashierShift.status === "open"
                                ? "bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400"
                                : cashierShift.status === "force_closed"
                                  ? "bg-rose-100 text-rose-700 dark:bg-rose-950/40 dark:text-rose-400"
                                  : "bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300"
                        }`}
                    >
                        {cashierShift.status === "open"
                            ? "Shift Aktif"
                            : cashierShift.status === "force_closed"
                              ? "Force Closed"
                              : "Shift Closed"}
                    </span>
                </div>

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <MetricCard title="Modal Awal" value={formatCurrency(cashierShift.opening_cash)} icon={IconWallet} />
                    <MetricCard title="Expected Cash" value={formatCurrency(cashierShift.expected_cash)} icon={IconCashBanknote} />
                    <MetricCard title="Penjualan Tunai" value={formatCurrency(cashierShift.cash_sales_total)} icon={IconReceipt} />
                    <MetricCard title="Refund Tunai" value={formatCurrency(cashierShift.cash_refund_total)} icon={IconRotateClockwise2} />
                </div>

                <div className="grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h2 className="text-lg font-semibold text-slate-900 dark:text-white">
                            Ringkasan Shift
                        </h2>
                        <div className="mt-5 grid gap-4 md:grid-cols-2">
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Kasir</p>
                                <p className="mt-2 text-sm text-slate-900 dark:text-white">{cashierShift.user?.name || "-"}</p>
                            </div>
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Gudang / Cabang</p>
                                <p className="mt-2 text-sm text-slate-900 dark:text-white">{cashierShift.warehouse?.name || "-"}</p>
                            </div>
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Dibuka Oleh</p>
                                <p className="mt-2 text-sm text-slate-900 dark:text-white">{cashierShift.opened_by?.name || "-"}</p>
                            </div>
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Waktu Tutup</p>
                                <p className="mt-2 text-sm text-slate-900 dark:text-white">{formatDateTime(cashierShift.closed_at)}</p>
                            </div>
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Ditutup Oleh</p>
                                <p className="mt-2 text-sm text-slate-900 dark:text-white">{cashierShift.closed_by?.name || "-"}</p>
                            </div>
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Total Transaksi</p>
                                <p className="mt-2 text-sm text-slate-900 dark:text-white">{cashierShift.transactions_count}</p>
                            </div>
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Total Retur</p>
                                <p className="mt-2 text-sm text-slate-900 dark:text-white">{cashierShift.sales_returns_count}</p>
                            </div>
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Penjualan Non Tunai</p>
                                <p className="mt-2 text-sm text-slate-900 dark:text-white">{formatCurrency(cashierShift.non_cash_sales_total)}</p>
                            </div>
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Refund Non Tunai</p>
                                <p className="mt-2 text-sm text-slate-900 dark:text-white">{formatCurrency(cashierShift.non_cash_refund_total)}</p>
                            </div>
                        </div>

                        <div className="mt-5 grid gap-4 md:grid-cols-2">
                            <div className="rounded-2xl bg-slate-50 p-4 dark:bg-slate-800/60">
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Catatan Shift</p>
                                <p className="mt-2 text-sm text-slate-700 dark:text-slate-300">{cashierShift.notes || "Tidak ada catatan pembukaan."}</p>
                            </div>
                            <div className="rounded-2xl bg-slate-50 p-4 dark:bg-slate-800/60">
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Catatan Closing</p>
                                <p className="mt-2 text-sm text-slate-700 dark:text-slate-300">{cashierShift.close_notes || "Tidak ada catatan penutupan."}</p>
                            </div>
                        </div>
                    </div>

                    <div className="space-y-6">
                        <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <h2 className="text-lg font-semibold text-slate-900 dark:text-white">
                                Cash Closing
                            </h2>
                            <div className="mt-4 space-y-3">
                                <div className="flex items-center justify-between rounded-xl bg-slate-50 px-4 py-3 dark:bg-slate-800/60">
                                    <span className="text-sm text-slate-500 dark:text-slate-400">Expected Cash</span>
                                    <span className="font-semibold text-slate-900 dark:text-white">{formatCurrency(cashierShift.expected_cash)}</span>
                                </div>
                                <div className="flex items-center justify-between rounded-xl bg-slate-50 px-4 py-3 dark:bg-slate-800/60">
                                    <span className="text-sm text-slate-500 dark:text-slate-400">Actual Cash</span>
                                    <span className="font-semibold text-slate-900 dark:text-white">
                                        {cashierShift.actual_cash === null ? "-" : formatCurrency(cashierShift.actual_cash)}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between rounded-xl bg-slate-50 px-4 py-3 dark:bg-slate-800/60">
                                    <span className="text-sm text-slate-500 dark:text-slate-400">Selisih</span>
                                    <span className="font-semibold text-slate-900 dark:text-white">
                                        {cashierShift.cash_difference === null ? "-" : formatCurrency(cashierShift.cash_difference)}
                                    </span>
                                </div>
                                {cashierShift.unpaid_bills_total > 0 && (
                                    <div className="flex items-center justify-between gap-3 rounded-xl bg-slate-50 px-4 py-3 dark:bg-slate-800/60">
                                        <span className="text-sm text-slate-500 dark:text-slate-400">{t("cashierShifts.forced.unpaidBooked")}</span>
                                        <span className="font-semibold text-slate-900 dark:text-white">{formatCurrency(cashierShift.unpaid_bills_total)}</span>
                                    </div>
                                )}
                            </div>
                        </div>

                        <ShiftCloseBlockedList blockers={closeBlockers} />

                        {/* Outside the form: a shift closed meanwhile removes the form but must still say why. */}
                        {errors?.shift && (
                            <p role="alert" className="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:bg-rose-950/30 dark:text-rose-300">
                                {errors.shift}
                            </p>
                        )}

                        {canCloseShift && (
                            <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                                <h2 className="text-lg font-semibold text-slate-900 dark:text-white">
                                    Tutup Shift
                                </h2>
                                <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                    Input kas fisik akhir untuk finalisasi cash closing.
                                </p>
                                <form onSubmit={handleCloseShift} className="mt-4 space-y-4">
                                    <div>
                                        <label htmlFor="actual-cash" className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">Kas Fisik Aktual</label>
                                        <input
                                            id="actual-cash"
                                            type="number"
                                            min="0"
                                            value={actualCash}
                                            onChange={(event) => setActualCash(event.target.value)}
                                            className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                        />
                                        {errors?.actual_cash && (
                                            <p role="alert" className="mt-2 text-xs text-rose-500">{errors.actual_cash}</p>
                                        )}
                                    </div>
                                    {forcing && (
                                        <p id="force-close-warning" className="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-amber-950/30 dark:text-amber-300">
                                            {t("cashierShifts.forced.warning")}
                                        </p>
                                    )}
                                    <div>
                                        <label htmlFor="close-notes" className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                            {forcing ? t("cashierShifts.forced.note") : "Catatan Closing"}
                                        </label>
                                        <textarea
                                            id="close-notes"
                                            rows={4}
                                            value={closeNotes}
                                            onChange={(event) => setCloseNotes(event.target.value)}
                                            className="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                            placeholder={forcing ? undefined : "Opsional"}
                                            required={forcing}
                                            minLength={forcing ? 3 : undefined}
                                            aria-invalid={Boolean(errors?.close_notes)}
                                            aria-describedby={
                                                [forcing && "force-close-warning", errors?.close_notes && "close-notes-error"]
                                                    .filter(Boolean)
                                                    .join(" ") || undefined
                                            }
                                        />
                                        {errors?.close_notes && (
                                            <p id="close-notes-error" role="alert" className="mt-2 text-xs text-rose-600 dark:text-rose-400">
                                                {errors.close_notes}
                                            </p>
                                        )}
                                    </div>
                                    {difference !== null && (
                                        <div
                                            className={`rounded-xl px-4 py-3 text-sm ${
                                                difference === 0
                                                    ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300"
                                                    : "bg-amber-50 text-amber-700 dark:bg-amber-950/30 dark:text-amber-300"
                                            }`}
                                        >
                                            Selisih closing: {formatCurrency(difference)}
                                        </div>
                                    )}
                                    <button
                                        type="submit"
                                        disabled={hasUnsynced}
                                        className={`inline-flex min-h-[44px] items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-medium text-white transition-colors disabled:cursor-not-allowed disabled:opacity-60 ${
                                            forcing ? "bg-rose-600 hover:bg-rose-700" : "bg-primary-500 hover:bg-primary-600"
                                        }`}
                                    >
                                        <IconCashBanknote size={18} />
                                        <span>
                                            {hasUnsynced
                                                ? t("cashierShifts.closeBlocked.syncFirst")
                                                : forcing
                                                  ? t("cashierShifts.forced.submit")
                                                  : "Finalisasi Closing"}
                                        </span>
                                    </button>
                                </form>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}

Show.layout = (page) => <DashboardLayout children={page} />;
