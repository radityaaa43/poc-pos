import React, { useState } from "react";
import { Head, router } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import { IconDatabaseOff, IconSearch, IconTrash } from "@tabler/icons-react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Input from "@/Components/Dashboard/Input";
import Select from "@/Components/Dashboard/Select";
import WasteRecorderDays from "@/Components/Dashboard/WasteRecorderDays";

const cardClass =
    "bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800";
const thClass =
    "px-4 py-4 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase whitespace-nowrap";
const tdClass = "px-4 py-4 text-right text-sm text-slate-600 dark:text-slate-400 whitespace-nowrap";

const formatCurrency = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value);

const Waste = ({ filters, outlets = [], items = [], reasons = [], total_value = 0, recorder_days = [], approval_limit = null, waste_percent = null, errors = {} }) => {
    const { t } = useTranslation();
    const [loading, setLoading] = useState(false);
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
        setLoading(true);
        router.get(route("reports.waste.index"), query, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setLoading(false),
        });
    };

    const reasonLabel = (reason) =>
        t(`waste.reasons.${reason}`, { defaultValue: reason });

    return (
        <>
            <Head title={t("wasteReport.title")} />

            <div className="space-y-6">
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <IconTrash size={28} className="text-primary-500" />
                    {t("wasteReport.title")}
                </h1>

                <form onSubmit={applyFilters} className={`${cardClass} p-5`}>
                    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4 items-end">
                        {outlets.length > 1 && (
                            <Select
                                id="outlet_id"
                                label={t("wasteReport.outlet")}
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
                            label={t("wasteReport.startDate")}
                            value={filterData.start_date}
                            onChange={handleChange("start_date")}
                            errors={errors.start_date}
                        />
                        <Input
                            type="date"
                            label={t("wasteReport.endDate")}
                            value={filterData.end_date}
                            min={filterData.start_date || undefined}
                            onChange={handleChange("end_date")}
                            errors={errors.end_date}
                        />
                        <button
                            type="submit"
                            disabled={loading}
                            className="inline-flex items-center justify-center gap-2 h-11 px-6 rounded-xl bg-primary-500 hover:bg-primary-600 text-white font-medium transition-colors disabled:opacity-60"
                        >
                            <IconSearch size={18} />
                            {t("wasteReport.apply")}
                        </button>
                    </div>
                </form>

                <div className="grid gap-6 md:grid-cols-2">
                    <section aria-labelledby="waste-total" className={`${cardClass} p-5`}>
                        <h2 id="waste-total" className="text-sm font-medium text-slate-500 dark:text-slate-400">
                            {t("wasteReport.total")}
                        </h2>
                        <p className="text-2xl font-bold text-slate-900 dark:text-white mt-1">
                            {formatCurrency(total_value)}
                        </p>
                    </section>
                    <section aria-labelledby="waste-percent" className={`${cardClass} p-5`}>
                        <h2 id="waste-percent" className="text-sm font-medium text-slate-500 dark:text-slate-400">
                            {t("wasteReport.wastePercent")}
                        </h2>
                        <p className="text-2xl font-bold text-slate-900 dark:text-white mt-1">
                            {waste_percent === null
                                ? "—"
                                : `${Number(waste_percent).toLocaleString("id-ID", { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`}
                        </p>
                        {waste_percent === null && (
                            <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">{t("wasteReport.noSalesCost")}</p>
                        )}
                    </section>
                </div>

                <section aria-labelledby="waste-items" className={`${cardClass} overflow-hidden`}>
                    <h2
                        id="waste-items"
                        className="px-4 py-4 text-sm font-semibold text-slate-700 dark:text-slate-300"
                    >
                        {t("wasteReport.items")}
                    </h2>
                    <div className="overflow-x-auto">
                        <table className="w-full">
                            <thead>
                                <tr className="border-b border-slate-100 dark:border-slate-800">
                                    <th scope="col" className={`${thClass} text-left`}>
                                        {t("wasteReport.item")}
                                    </th>
                                    <th scope="col" className={`${thClass} text-right`}>
                                        {t("wasteReport.qty")}
                                    </th>
                                    <th scope="col" className={`${thClass} text-right`}>
                                        {t("wasteReport.value")}
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {items.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={3}
                                            className="px-4 py-16 text-center text-sm text-slate-500 dark:text-slate-400"
                                        >
                                            <IconDatabaseOff
                                                size={32}
                                                className="mx-auto mb-2 text-slate-400"
                                            />
                                            {t("wasteReport.empty")}
                                        </td>
                                    </tr>
                                ) : (
                                    items.map((item) => (
                                        <tr
                                            key={item.product_id}
                                            className="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors"
                                        >
                                            <th
                                                scope="row"
                                                className="px-4 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white"
                                            >
                                                {item.title}
                                                {item.is_composite && (
                                                    <span className="ml-2 px-2 py-0.5 text-xs font-medium bg-primary-100 dark:bg-primary-900/50 text-primary-700 dark:text-primary-400 rounded-full">
                                                        {t("wasteReport.menuTag")}
                                                    </span>
                                                )}
                                            </th>
                                            <td className={tdClass}>{item.qty}</td>
                                            <td className={tdClass}>{formatCurrency(item.value)}</td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>

                {reasons.length > 0 && (
                    <section aria-labelledby="waste-reasons" className={`${cardClass} overflow-hidden`}>
                        <h2
                            id="waste-reasons"
                            className="px-4 py-4 text-sm font-semibold text-slate-700 dark:text-slate-300"
                        >
                            {t("wasteReport.reasons")}
                        </h2>
                        <div className="overflow-x-auto">
                            <table className="w-full">
                                <thead>
                                    <tr className="border-b border-slate-100 dark:border-slate-800">
                                        <th scope="col" className={`${thClass} text-left`}>
                                            {t("wasteReport.reason")}
                                        </th>
                                        <th scope="col" className={`${thClass} text-right`}>
                                            {t("wasteReport.records")}
                                        </th>
                                        <th scope="col" className={`${thClass} text-right`}>
                                            {t("wasteReport.value")}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {reasons.map((reason) => (
                                        <tr
                                            key={reason.reason}
                                            className="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors"
                                        >
                                            <th
                                                scope="row"
                                                className="px-4 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white"
                                            >
                                                {reasonLabel(reason.reason)}
                                            </th>
                                            <td className={tdClass}>{reason.records}</td>
                                            <td className={tdClass}>{formatCurrency(reason.value)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}

                <WasteRecorderDays
                    rows={recorder_days}
                    approvalLimit={approval_limit}
                    formatCurrency={formatCurrency}
                />
            </div>
        </>
    );
};

Waste.layout = (page) => <DashboardLayout children={page} />;

export default Waste;
