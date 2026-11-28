import React, { useState } from "react";
import { Head, router } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import { IconAdjustments, IconPencil, IconPlus, IconTrash } from "@tabler/icons-react";
import toast from "react-hot-toast";
import Swal from "sweetalert2";
import DashboardLayout from "@/Layouts/DashboardLayout";
import GroupForm from "@/Components/Modifiers/GroupForm";

// Reachable only with modifiers-manage (the route middleware), so the buttons need no per-action check.
export default function Modifiers({ groups = [], ingredients = [] }) {
    const { t } = useTranslation();
    const [form, setForm] = useState(null); // null shows the list; { group: null } is a new group

    const handleDelete = async (group) => {
        const { isConfirmed } = await Swal.fire({
            title: t("modifiers.deleteTitle", { name: group.name }),
            text: t("modifiers.deleteConfirm", { menus: group.products_count }),
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#ef4444",
            cancelButtonColor: "#64748b",
            confirmButtonText: t("common.buttons.delete"),
            cancelButtonText: t("common.buttons.cancel"),
        });
        if (!isConfirmed) return;

        router.delete(route("settings.modifiers.destroy", group.id), {
            onSuccess: () => toast.success(t("modifiers.deleted")),
            onError: () => toast.error(t("modifiers.deleteFailed")),
        });
    };

    return (
        <>
            <Head title={t("modifiers.title")} />

            <div className="mb-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <IconAdjustments size={28} className="text-primary-500" />
                    {t("modifiers.title")}
                </h1>
                {!form && (
                    <button
                        type="button"
                        onClick={() => setForm({ group: null })}
                        className="inline-flex items-center gap-2 px-4 py-2.5 min-h-11 rounded-xl bg-primary-500 hover:bg-primary-600 text-white text-sm font-medium transition-colors"
                    >
                        <IconPlus size={18} />
                        {t("modifiers.add")}
                    </button>
                )}
            </div>

            {form ? (
                <GroupForm group={form.group} ingredients={ingredients} onClose={() => setForm(null)} />
            ) : (
                <div className="max-w-4xl bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                    {groups.length > 0 ? (
                        <ul className="divide-y divide-slate-200 dark:divide-slate-800">
                            {groups.map((group) => (
                                <li key={group.id} className="p-4 flex items-center gap-3">
                                    <div className="min-w-0">
                                        <p className="font-semibold text-slate-800 dark:text-white truncate">{group.name}</p>
                                        <p className="text-sm text-slate-500 dark:text-slate-400">
                                            {[
                                                t(group.is_required ? "modifiers.required" : "modifiers.optional"),
                                                t("modifiers.range", { min: group.min_select, max: group.max_select }),
                                                t("modifiers.optionCount", { total: group.options.length }),
                                                t("modifiers.menuCount", { total: group.products_count }),
                                            ].join(" · ")}
                                        </p>
                                    </div>
                                    <div className="ml-auto flex items-center gap-2 shrink-0">
                                        <button
                                            type="button"
                                            aria-label={t("modifiers.editLabel", { name: group.name })}
                                            onClick={() => setForm({ group })}
                                            className="size-11 inline-flex items-center justify-center rounded-xl text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                                        >
                                            <IconPencil size={18} />
                                        </button>
                                        <button
                                            type="button"
                                            aria-label={t("modifiers.deleteLabel", { name: group.name })}
                                            onClick={() => handleDelete(group)}
                                            className="size-11 inline-flex items-center justify-center rounded-xl text-danger-500 hover:bg-danger-50 dark:hover:bg-danger-900/20 transition-colors"
                                        >
                                            <IconTrash size={18} />
                                        </button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="p-8 text-center text-slate-500 dark:text-slate-400">{t("modifiers.empty")}</p>
                    )}
                </div>
            )}
        </>
    );
}

Modifiers.layout = (page) => <DashboardLayout children={page} />;
