import React, { useState } from "react";
import { Head, router } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import { IconDatabaseOff, IconSearch, IconStopwatch } from "@tabler/icons-react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Input from "@/Components/Dashboard/Input";
import Select from "@/Components/Dashboard/Select";

const cardClass =
    "bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800";
const thClass =
    "px-4 py-4 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase whitespace-nowrap";
const tdClass = "px-4 py-4 text-right text-sm text-slate-600 dark:text-slate-400 whitespace-nowrap";

const columns = ["orders", "portions", "makeMedian", "makeAvg", "waitMakeMedian", "waitMakeAvg", "target", "withinTarget"];

const KitchenSpeed = ({ filters, outlets = [], rows = [], orders, empty }) => {
    const { t, i18n } = useTranslation();
    const [filterData, setFilterData] = useState({
        outlet_id: String(filters?.outlet_id ?? ""),
        start_date: filters?.start_date ?? "",
        end_date: filters?.end_date ?? "",
    });
    const handleChange = (field) => (e) =>
        setFilterData((prev) => ({ ...prev, [field]: e.target.value }));

    const applyFilters = (e) => {
        e.preventDefault();
        // Empty dates would fail the server's date rule; leave them out of the query.
        const query = Object.fromEntries(Object.entries(filterData).filter(([, v]) => v !== ""));
        router.get(route("reports.kitchen-speed.index"), query, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    // Minutes with one decimal in the page language: 4.5 -> "4,5 mnt" (id) / "4.5 min" (en).
    const decimal = (value) =>
        new Intl.NumberFormat(i18n.language, { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(value);
    const minutes = (value) =>
        value == null ? "-" : t("reports.kitchenSpeed.minutes", { value: decimal(value) });

    return (
        <>
            <Head title={t("reports.kitchenSpeed.title")} />

            <div className="space-y-6">
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <IconStopwatch size={28} className="text-primary-500" />
                    {t("reports.kitchenSpeed.title")}
                </h1>

                <form onSubmit={applyFilters} className={`${cardClass} p-5`}>
                    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4 items-end">
                        {outlets.length > 1 && (
                            <Select
                                id="outlet_id"
                                label={t("reports.kitchenSpeed.outlet")}
                                value={filterData.outlet_id}
                                onChange={handleChange("outlet_id")}
                            >
                                {outlets.map((outlet) => (
                                    <option key={outlet.id} value={outlet.id}>
                                        {outlet.name}
                                    </option>
                                ))}
                            </Select>
                        )}
                        <Input
                            type="date"
                            label={t("reports.kitchenSpeed.startDate")}
                            value={filterData.start_date}
                            onChange={handleChange("start_date")}
                        />
                        <Input
                            type="date"
                            label={t("reports.kitchenSpeed.endDate")}
                            value={filterData.end_date}
                            onChange={handleChange("end_date")}
                        />
                        <button
                            type="submit"
                            className="inline-flex items-center justify-center gap-2 h-11 px-6 rounded-xl bg-primary-500 hover:bg-primary-600 text-white font-medium transition-colors"
                        >
                            <IconSearch size={18} />
                            {t("reports.kitchenSpeed.apply")}
                        </button>
                    </div>
                </form>

                {empty ? (
                    <div className={`${cardClass} flex flex-col items-center justify-center py-16`}>
                        <div className="w-16 h-16 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center mb-4">
                            <IconDatabaseOff size={32} className="text-slate-400" />
                        </div>
                        <p className="text-lg font-medium text-slate-800 dark:text-slate-200">
                            {t("reports.kitchenSpeed.empty")}
                        </p>
                    </div>
                ) : (
                    <>
                        <div className={`${cardClass} overflow-hidden`}>
                            <div className="overflow-x-auto">
                                <table className="w-full">
                                    <thead>
                                        <tr className="border-b border-slate-100 dark:border-slate-800">
                                            <th scope="col" className={`${thClass} text-left`}>
                                                {t("reports.kitchenSpeed.item")}
                                            </th>
                                            {columns.map((column) => (
                                                <th key={column} scope="col" className={`${thClass} text-right`}>
                                                    {t(`reports.kitchenSpeed.${column}`)}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                        {rows.map((row) => (
                                            <tr
                                                key={row.product_id}
                                                className="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors"
                                            >
                                                <th
                                                    scope="row"
                                                    className="px-4 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white"
                                                >
                                                    {row.title}
                                                </th>
                                                <td className={tdClass}>{row.count_rows}</td>
                                                <td className={tdClass}>{row.count_portions}</td>
                                                <td className={tdClass}>{minutes(row.make_median)}</td>
                                                <td className={tdClass}>{minutes(row.make_avg)}</td>
                                                <td className={tdClass}>{minutes(row.wait_make_median)}</td>
                                                <td className={tdClass}>{minutes(row.wait_make_avg)}</td>
                                                <td className={tdClass}>{minutes(row.target)}</td>
                                                <td className={tdClass}>
                                                    {row.within_target_pct == null
                                                        ? "-"
                                                        : `${decimal(row.within_target_pct)}%`}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <section aria-labelledby="kitchen-handover" className={`${cardClass} p-5`}>
                            <h2
                                id="kitchen-handover"
                                className="text-sm font-medium text-slate-500 dark:text-slate-400"
                            >
                                {t("reports.kitchenSpeed.handover")}
                            </h2>
                            <p className="text-2xl font-bold text-slate-900 dark:text-white mt-1">
                                {minutes(orders?.avg_minutes)}
                            </p>
                            <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">
                                {t("reports.kitchenSpeed.orders")}:{" "}
                                <span>{orders?.orders ?? 0}</span>
                            </p>
                        </section>
                    </>
                )}
            </div>
        </>
    );
};

KitchenSpeed.layout = (page) => <DashboardLayout children={page} />;

export default KitchenSpeed;
