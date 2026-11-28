import React, { useState } from "react";
import { Link, router } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import Input from "@/Components/Dashboard/Input";
import Select from "@/Components/Dashboard/Select";

const formatDateTime = (value, language) => {
    if (!value) return "-";

    return new Intl.DateTimeFormat(language === "en" ? "en-GB" : "id-ID", {
        dateStyle: "medium",
        timeStyle: "short",
    }).format(new Date(value));
};

/** The monthly force-close count per outlet (REQ-003 AC-13, #564). */
export default function ForceCloseSummary({
    forceCloseSummary = null,
    summaryFilters = {},
    summaryOutlets = [],
    listFilters = {},
    errors = {},
}) {
    const { t, i18n } = useTranslation();
    const [outletId, setOutletId] = useState(String(summaryFilters?.outlet_id ?? ""));
    const [month, setMonth] = useState(summaryFilters?.month ?? "");
    const summary = forceCloseSummary ?? { closed: 0, force_closed: 0, held: 0, held_shifts: [] };

    const applySummary = (event) => {
        event.preventDefault();
        // Keep the list's filters: the summary fields are added on top of them.
        const query = Object.fromEntries(
            Object.entries({ ...listFilters, summary_outlet_id: outletId, summary_month: month })
                .filter(([, value]) => value !== "" && value !== null && value !== undefined)
        );
        router.get(route("cashier-shifts.index"), query, { preserveState: true, preserveScroll: true });
    };

    const figures = [
        ["closed", summary.closed],
        ["forceClosed", summary.force_closed],
        ["overHold", summary.held],
    ];

    return (
        <section
            aria-labelledby="force-close-summary-title"
            className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"
        >
            <h2 id="force-close-summary-title" className="text-lg font-semibold text-slate-900 dark:text-white">
                {t("cashierShifts.monthSummary.title")}
            </h2>

            <form onSubmit={applySummary} className="mt-4 grid gap-4 md:grid-cols-3 md:items-end">
                {summaryOutlets.length > 1 && (
                    <Select
                        id="summary_outlet_id"
                        label={t("cashierShifts.monthSummary.outlet")}
                        value={outletId}
                        onChange={(event) => setOutletId(event.target.value)}
                        error={errors.summary_outlet_id}
                    >
                        {summaryOutlets.map((outlet) => (
                            <option key={outlet.id} value={outlet.id}>
                                {outlet.name}
                            </option>
                        ))}
                    </Select>
                )}
                <Input
                    id="summary_month"
                    type="month"
                    label={t("cashierShifts.monthSummary.month")}
                    value={month}
                    onChange={(event) => setMonth(event.target.value)}
                    errors={errors.summary_month}
                />
                <button
                    type="submit"
                    className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-primary-500 px-6 font-medium text-white transition-colors hover:bg-primary-600"
                >
                    {t("cashierShifts.monthSummary.apply")}
                </button>
            </form>

            <dl className="mt-4 grid gap-3 sm:grid-cols-3">
                {figures.map(([key, value]) => (
                    <div key={key} className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800">
                        <dt className="text-sm text-slate-500 dark:text-slate-400">
                            {t(`cashierShifts.monthSummary.${key}`)}
                        </dt>
                        <dd className="text-2xl font-semibold text-slate-900 dark:text-white">{value}</dd>
                    </div>
                ))}
            </dl>

            {summary.closed === 0 ? (
                <p className="mt-4 text-sm text-slate-600 dark:text-slate-300">{t("cashierShifts.monthSummary.empty")}</p>
            ) : summary.held === 0 ? (
                <p className="mt-4 text-sm text-slate-600 dark:text-slate-300">{t("cashierShifts.monthSummary.noHeld")}</p>
            ) : (
                <ul className="mt-4 divide-y divide-slate-100 dark:divide-slate-800">
                    {summary.held_shifts.map((shift) => (
                        <li key={shift.id} className="flex flex-wrap items-start justify-between gap-3 py-3">
                            <div className="min-w-0">
                                <p className="text-sm font-semibold text-slate-900 dark:text-white">{shift.cashier}</p>
                                <p className="text-sm text-slate-600 dark:text-slate-300">
                                    {formatDateTime(shift.closed_at, i18n.language)}
                                </p>
                                <p className="text-sm text-slate-600 dark:text-slate-300">{shift.close_notes || "-"}</p>
                            </div>
                            <Link
                                href={route("cashier-shifts.show", shift.id)}
                                className="text-sm font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400"
                            >
                                {t("cashierShifts.monthSummary.viewShift", { id: shift.id })}
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
