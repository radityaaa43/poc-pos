import React, { useEffect, useState } from "react";
import { Head, Link, router, usePage } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import toast from "react-hot-toast";
import { IconPlus } from "@tabler/icons-react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Pagination from "@/Components/Dashboard/Pagination";
import Select from "@/Components/Dashboard/Select";
import { SkeletonTable } from "@/Components/Dashboard/Skeleton";
import { useAuthorization } from "@/Utils/authorization";

const TONES = {
    pending: "bg-warning-100 text-warning-700 dark:bg-warning-900/40 dark:text-warning-300",
    recorded: "bg-success-100 text-success-700 dark:bg-success-900/40 dark:text-success-300",
    rejected: "bg-danger-100 text-danger-700 dark:bg-danger-900/40 dark:text-danger-300",
    cancelled: "bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300",
};

const rupiah = (value) => Number(value).toLocaleString("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 });
const amount = (row) => `${row.qty} ${row.product?.unit_symbol ?? ""}`.trim();

export default function Index({ wastes, filters, warehouses }) {
    const { t, i18n } = useTranslation();
    const { flash } = usePage().props;
    const { can } = useAuthorization();
    const [loading, setLoading] = useState(false);
    const date = new Intl.DateTimeFormat(i18n.language === "en" ? "en-GB" : "id-ID", { dateStyle: "medium", timeStyle: "short" });

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash]);

    const filter = (key, value) =>
        router.get(route("stock-waste.index"), { ...filters, [key]: value }, {
            preserveState: true,
            replace: true,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });

    const createLink = can("stock-waste-create") && (
        <Link href={route("stock-waste.create")} className="inline-flex items-center gap-2 rounded-xl bg-primary-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-600">
            <IconPlus size={18} /> {t("waste.create")}
        </Link>
    );

    return (
        <>
            <Head title={t("waste.title")} />
            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 dark:text-white">{t("waste.title")}</h1>
                    <p className="text-sm text-slate-500 dark:text-slate-400">{t("waste.subtitle")}</p>
                </div>
                {wastes.data.length > 0 && createLink}
            </div>

            <div className="mb-4 grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 md:grid-cols-2">
                <Select id="status" label={t("waste.status")} value={filters.status ?? ""} onChange={(e) => filter("status", e.target.value)}>
                    <option value="">{t("waste.allStatuses")}</option>
                    {Object.keys(TONES).map((status) => (
                        <option key={status} value={status}>{t(`waste.statuses.${status}`)}</option>
                    ))}
                </Select>
                <Select id="warehouse_id" label={t("waste.warehouse")} value={filters.warehouse_id ?? ""} onChange={(e) => filter("warehouse_id", e.target.value)}>
                    <option value="">{t("waste.allWarehouses")}</option>
                    {warehouses.map((warehouse) => (
                        <option key={warehouse.id} value={warehouse.id}>{warehouse.code} — {warehouse.name}</option>
                    ))}
                </Select>
            </div>

            {loading ? (
                <SkeletonTable rows={5} cols={5} />
            ) : wastes.data.length === 0 ? (
                <div className="flex flex-col items-center gap-4 rounded-2xl border border-slate-200 bg-white p-10 text-slate-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300">
                    <p>{t("waste.empty")}</p>
                    {createLink}
                </div>
            ) : (
                <ul className="space-y-3">
                    {wastes.data.map((waste) => (
                        <li key={waste.id} className="rounded-2xl border border-slate-200 bg-white text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300">
                            <details>
                                <summary className="flex cursor-pointer flex-wrap items-center gap-x-4 gap-y-1 p-4">
                                    <span className="font-semibold">{date.format(new Date(waste.created_at))}</span>
                                    <span>{waste.warehouse?.name}</span>
                                    <span>{t(`waste.reasons.${waste.reason}`)}</span>
                                    <span className="font-semibold">{rupiah(waste.total_value)}</span>
                                    <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${TONES[waste.status]}`}>{t(`waste.statuses.${waste.status}`)}</span>
                                    <span>{t("waste.recordedBy", { name: waste.creator?.name ?? "-" })}</span>
                                </summary>
                                <div className="space-y-3 border-t border-slate-200 p-4 dark:border-slate-800">
                                    <div>
                                        <h2 className="font-semibold">{t("waste.lines")}</h2>
                                        <ul>
                                            {waste.lines.map((line) => (
                                                <li key={line.id}>{line.product?.title}: {amount(line)}</li>
                                            ))}
                                        </ul>
                                    </div>
                                    {waste.moves.length === 0 ? (
                                        <p>{t("waste.notTaken")}</p>
                                    ) : (
                                        <div className="overflow-x-auto">
                                            <table className="w-full text-left">
                                                <caption className="text-left font-semibold">{t("waste.moves")}</caption>
                                                <thead>
                                                    <tr>
                                                        {["item", "qty", "unitCost", "value"].map((column) => (
                                                            <th key={column} scope="col" className="py-1 pr-4 font-medium">{t(`waste.${column}`)}</th>
                                                        ))}
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {waste.moves.map((move) => (
                                                        <tr key={move.id}>
                                                            <td className="py-1 pr-4">{move.product?.title}</td>
                                                            <td className="py-1 pr-4">{amount(move)}</td>
                                                            <td className="py-1 pr-4">{rupiah(move.unit_cost)}</td>
                                                            <td className="py-1 pr-4">{rupiah(move.value)}</td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    )}
                                    {waste.note && <p>{t("waste.note")}: {waste.note}</p>}
                                    {waste.recorded_at && <p>{t("waste.takenAt", { date: date.format(new Date(waste.recorded_at)) })}</p>}
                                </div>
                            </details>
                        </li>
                    ))}
                </ul>
            )}
            <Pagination links={wastes.links} />
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
