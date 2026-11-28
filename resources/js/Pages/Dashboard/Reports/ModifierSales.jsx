import React, { useState } from "react";
import { Head, router } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import { IconAdjustments, IconDatabaseOff, IconSearch } from "@tabler/icons-react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Input from "@/Components/Dashboard/Input";
import Pagination from "@/Components/Dashboard/Pagination";
import Table from "@/Components/Dashboard/Table";

const cardClass = "bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800";
const rupiah = new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 });

const ModifierSales = ({ rows, filters, errors = {} }) => {
    const { t } = useTranslation();
    const [dates, setDates] = useState({
        start_date: filters?.start_date ?? "",
        end_date: filters?.end_date ?? "",
    });
    const handleChange = (field) => (e) => setDates((prev) => ({ ...prev, [field]: e.target.value }));

    const applyFilters = (e) => {
        e.preventDefault();
        // An empty date means no limit; leave it out of the query.
        const query = Object.fromEntries(Object.entries(dates).filter(([, value]) => value !== ""));
        router.get(route("reports.modifiers.index"), query, { preserveScroll: true, preserveState: true });
    };

    return (
        <>
            <Head title={t("reports.modifiers.title")} />

            <div className="space-y-6">
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <IconAdjustments size={28} className="text-primary-500" />
                    {t("reports.modifiers.title")}
                </h1>

                <form onSubmit={applyFilters} className={`${cardClass} p-5`}>
                    <div className="grid gap-4 md:grid-cols-3 items-end">
                        <Input
                            type="date"
                            label={t("reports.modifiers.startDate")}
                            value={dates.start_date}
                            onChange={handleChange("start_date")}
                            errors={errors.start_date}
                        />
                        <Input
                            type="date"
                            label={t("reports.modifiers.endDate")}
                            value={dates.end_date}
                            min={dates.start_date || undefined}
                            onChange={handleChange("end_date")}
                            errors={errors.end_date}
                        />
                        <button
                            type="submit"
                            className="inline-flex items-center justify-center gap-2 h-11 px-6 rounded-xl bg-primary-500 hover:bg-primary-600 text-white font-medium transition-colors"
                        >
                            <IconSearch size={18} />
                            {t("reports.modifiers.apply")}
                        </button>
                    </div>
                </form>

                {rows.data.length === 0 ? (
                    <div className={`${cardClass} flex flex-col items-center justify-center py-16`}>
                        <div className="w-16 h-16 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center mb-4">
                            <IconDatabaseOff size={32} className="text-slate-400" />
                        </div>
                        <p className="text-lg font-medium text-slate-800 dark:text-slate-200">
                            {t("reports.modifiers.empty")}
                        </p>
                    </div>
                ) : (
                    <>
                        <Table>
                            <Table.Thead>
                                <tr>
                                    <Table.Th>{t("reports.modifiers.group")}</Table.Th>
                                    <Table.Th>{t("reports.modifiers.option")}</Table.Th>
                                    <Table.Th className="!text-right">{t("reports.modifiers.qty")}</Table.Th>
                                    <Table.Th className="!text-right">{t("reports.modifiers.revenue")}</Table.Th>
                                </tr>
                            </Table.Thead>
                            <Table.Tbody>
                                {rows.data.map((row) => (
                                    <tr key={`${row.modifier_option_id}-${row.group_name}-${row.option_name}`}>
                                        <Table.Td>{row.group_name}</Table.Td>
                                        <Table.Td>{row.option_name}</Table.Td>
                                        <Table.Td className="text-right">{row.qty.toLocaleString("id-ID")}</Table.Td>
                                        <Table.Td className="text-right">{rupiah.format(row.revenue)}</Table.Td>
                                    </tr>
                                ))}
                            </Table.Tbody>
                        </Table>
                        <Pagination links={rows.links} defaultPerPage={String(rows.per_page)} />
                    </>
                )}
            </div>
        </>
    );
};

ModifierSales.layout = (page) => <DashboardLayout children={page} />;

export default ModifierSales;
