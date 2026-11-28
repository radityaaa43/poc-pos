import React, { useState } from "react";
import { Head, router } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import { IconCircleCheck, IconPrinter, IconSearch } from "@tabler/icons-react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Input from "@/Components/Dashboard/Input";
import Select from "@/Components/Dashboard/Select";

const cardClass =
    "bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800";
const thClass =
    "px-4 py-4 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase whitespace-nowrap";
const tdClass = "px-4 py-4 text-sm text-slate-600 dark:text-slate-400 whitespace-nowrap";

const KitchenUnprinted = ({ filters, outlets = [], tickets = [], days = [], total = 0, truncated = false, errors = {} }) => {
    const { t } = useTranslation();
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
        router.get(route("reports.kitchen-unprinted.index"), query, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    return (
        <>
            <Head title={t("reports.kitchenUnprinted.title")} />

            <div className="space-y-6">
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <IconPrinter size={28} className="text-primary-500" />
                    {t("reports.kitchenUnprinted.title")}
                </h1>
                <p className="text-sm text-slate-500 dark:text-slate-400">{t("reports.kitchenUnprinted.note")}</p>

                <form onSubmit={applyFilters} className={`${cardClass} p-5`}>
                    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4 items-end">
                        {outlets.length > 1 && (
                            <Select
                                id="outlet_id"
                                label={t("reports.kitchenUnprinted.outlet")}
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
                            label={t("reports.kitchenUnprinted.startDate")}
                            value={filterData.start_date}
                            onChange={handleChange("start_date")}
                            errors={errors.start_date}
                        />
                        <Input
                            type="date"
                            label={t("reports.kitchenUnprinted.endDate")}
                            value={filterData.end_date}
                            min={filterData.start_date || undefined}
                            onChange={handleChange("end_date")}
                            errors={errors.end_date}
                        />
                        <button
                            type="submit"
                            className="inline-flex items-center justify-center gap-2 h-11 px-6 rounded-xl bg-primary-500 hover:bg-primary-600 text-white font-medium transition-colors"
                        >
                            <IconSearch size={18} />
                            {t("reports.kitchenUnprinted.apply")}
                        </button>
                    </div>
                </form>

                <section aria-labelledby="kitchen-unprinted-total" className={`${cardClass} p-5`}>
                    <h2 id="kitchen-unprinted-total" className="text-sm font-medium text-slate-500 dark:text-slate-400">
                        {t("reports.kitchenUnprinted.total")}
                    </h2>
                    {total === 0 ? (
                        <p className="flex items-center gap-2 text-lg font-medium text-green-600 dark:text-green-400 mt-1">
                            <IconCircleCheck size={22} />
                            {t("reports.kitchenUnprinted.allPrinted")}
                        </p>
                    ) : (
                        <p className="text-2xl font-bold text-slate-900 dark:text-white mt-1">{total}</p>
                    )}
                </section>

                {total > 0 && (
                    <>
                        {truncated && (
                            <p className="text-sm text-amber-600 dark:text-amber-400">
                                {t("reports.kitchenUnprinted.truncated", { shown: tickets.length, total })}
                            </p>
                        )}

                        <div className={`${cardClass} overflow-hidden`}>
                            <div className="overflow-x-auto">
                                <table className="w-full">
                                    <caption className="sr-only">{t("reports.kitchenUnprinted.perDay")}</caption>
                                    <thead>
                                        <tr className="border-b border-slate-100 dark:border-slate-800">
                                            <th scope="col" className={`${thClass} text-left`}>
                                                {t("reports.kitchenUnprinted.date")}
                                            </th>
                                            <th scope="col" className={`${thClass} text-right`}>
                                                {t("reports.kitchenUnprinted.count")}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                        {days.map((day) => (
                                            <tr key={day.date}>
                                                <th scope="row" className="px-4 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">
                                                    {day.date}
                                                </th>
                                                <td className={`${tdClass} text-right`}>{day.count}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div className={`${cardClass} overflow-hidden`}>
                            <div className="overflow-x-auto">
                                <table className="w-full">
                                    <caption className="sr-only">{t("reports.kitchenUnprinted.tickets")}</caption>
                                    <thead>
                                        <tr className="border-b border-slate-100 dark:border-slate-800">
                                            <th scope="col" className={`${thClass} text-left`}>
                                                {t("reports.kitchenUnprinted.time")}
                                            </th>
                                            <th scope="col" className={`${thClass} text-left`}>
                                                {t("reports.kitchenUnprinted.number")}
                                            </th>
                                            <th scope="col" className={`${thClass} text-left`}>
                                                {t("reports.kitchenUnprinted.station")}
                                            </th>
                                            <th scope="col" className={`${thClass} text-left`}>
                                                {t("reports.kitchenUnprinted.device")}
                                            </th>
                                            <th scope="col" className={`${thClass} text-right`}>
                                                {t("reports.kitchenUnprinted.items")}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                        {tickets.map((ticket) => (
                                            <tr key={ticket.key}>
                                                <th scope="row" className="px-4 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white whitespace-nowrap">
                                                    {`${ticket.date} ${ticket.time.slice(11, 16)}`}
                                                </th>
                                                <td className={`${tdClass} text-left`}>{ticket.number}</td>
                                                <td className={`${tdClass} text-left`}>{ticket.station}</td>
                                                <td className={`${tdClass} text-left`}>{ticket.devices.join(", ")}</td>
                                                <td className={`${tdClass} text-right`}>{ticket.items}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </>
                )}
            </div>
        </>
    );
};

KitchenUnprinted.layout = (page) => <DashboardLayout children={page} />;

export default KitchenUnprinted;
