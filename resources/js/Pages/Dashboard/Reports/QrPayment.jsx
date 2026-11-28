import React, { useState } from "react";
import { Head, router } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import { IconQrcode } from "@tabler/icons-react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Select from "@/Components/Dashboard/Select";

const cardClass =
    "bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800";

const QrPayment = ({ filters, outlets = [], summary, errors = {} }) => {
    const { t, i18n } = useTranslation();
    const [filterData, setFilterData] = useState({
        outlet_id: String(filters?.outlet_id ?? ""),
        month: filters?.month ?? "",
    });
    const change = (field) => (event) => setFilterData((prev) => ({ ...prev, [field]: event.target.value }));

    const apply = (event) => {
        event.preventDefault();
        const query = Object.fromEntries(Object.entries(filterData).filter(([, value]) => value !== ""));
        router.get(route("reports.qr-payment.index"), query, { preserveScroll: true, preserveState: true });
    };

    const decimal = (value) =>
        new Intl.NumberFormat(i18n.language, { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(value);
    const percent = summary.online_percent == null ? "-" : `${decimal(summary.online_percent)}%`;
    const median = summary.median_minutes == null ? "-" : decimal(summary.median_minutes);

    return (
        <>
            <Head title={t("reports.qrPayment.title")} />

            <div className="space-y-6">
                <h1 className="flex items-center gap-2 text-2xl font-bold text-slate-900 dark:text-white">
                    <IconQrcode size={28} className="text-primary-500" />
                    {t("reports.qrPayment.title")}
                </h1>

                <form onSubmit={apply} className={`${cardClass} p-5`}>
                    <div className="grid items-end gap-4 md:grid-cols-2 lg:grid-cols-4">
                        {outlets.length > 1 && (
                            <Select id="outlet_id" label={t("reports.qrPayment.outlet")} value={filterData.outlet_id} onChange={change("outlet_id")}>
                                {outlets.map((outlet) => (
                                    <option key={outlet.id} value={outlet.id}>{outlet.name}</option>
                                ))}
                            </Select>
                        )}
                        <div>
                            <label htmlFor="month" className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                {t("reports.qrPayment.month")}
                            </label>
                            <input
                                id="month"
                                type="month"
                                value={filterData.month}
                                onChange={change("month")}
                                className="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                            />
                            {errors.month && <p role="alert" className="mt-1 text-xs text-danger-600 dark:text-danger-400">{errors.month}</p>}
                        </div>
                        <button type="submit" className="rounded-xl bg-primary-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-600">
                            {t("reports.qrPayment.apply")}
                        </button>
                    </div>
                </form>

                <section className={`${cardClass} p-5`}>
                    <h2 className="font-semibold text-slate-800 dark:text-white">{t("reports.qrPayment.cardTitle")}</h2>
                    {summary.paid === 0 ? (
                        <p className="mt-3 text-sm text-slate-500 dark:text-slate-400">{t("reports.qrPayment.empty")}</p>
                    ) : (
                        <dl className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-5">
                            <div>
                                <dt className="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{t("reports.qrPayment.paid")}</dt>
                                <dd className="text-lg font-bold text-slate-900 dark:text-white">{summary.paid}</dd>
                            </div>
                            <div>
                                <dt className="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{t("reports.qrPayment.paidOnline")}</dt>
                                <dd className="text-lg font-bold text-slate-900 dark:text-white">{summary.paid_online}</dd>
                            </div>
                            <div>
                                <dt className="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{t("reports.qrPayment.onlineShare")}</dt>
                                <dd className="text-lg font-bold text-slate-900 dark:text-white">{percent}</dd>
                            </div>
                            <div>
                                <dt className="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{t("reports.qrPayment.median")}</dt>
                                <dd className="text-lg font-bold text-slate-900 dark:text-white">
                                    {median}
                                    {summary.timed > 0 && (
                                        <span className="ml-1 text-xs font-normal text-slate-500 dark:text-slate-400">
                                            ({t("reports.qrPayment.fromBills", { count: summary.timed })})
                                        </span>
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{t("reports.qrPayment.doublePaid")}</dt>
                                <dd className="text-lg font-bold text-slate-900 dark:text-white">{summary.double_paid}</dd>
                            </div>
                        </dl>
                    )}
                </section>
            </div>
        </>
    );
};

QrPayment.layout = (page) => <DashboardLayout children={page} />;

export default QrPayment;
