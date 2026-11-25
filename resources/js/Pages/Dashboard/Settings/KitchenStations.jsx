import React, { useState } from "react";
import { Head, router, useForm } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import { IconPencil, IconPlus, IconToolsKitchen2, IconTrash } from "@tabler/icons-react";
import toast from "react-hot-toast";
import Swal from "sweetalert2";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Input from "@/Components/Dashboard/Input";
import Modal from "@/Components/Dashboard/Modal";

// Reachable only with products-edit (the route middleware), so the buttons need no per-action check.
export default function KitchenStations({ stations = [] }) {
    const { t } = useTranslation();
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({ name: "" });

    const openForm = (station) => {
        setEditing(station);
        reset();
        clearErrors();
        if (station) setData("name", station.name);
        setModalOpen(true);
    };

    const submit = (e) => {
        e.preventDefault();
        const onSuccess = () => {
            toast.success(t(editing ? "kitchenStations.renamed" : "kitchenStations.created"));
            setModalOpen(false);
        };
        const options = { onSuccess, onError: () => toast.error(t("kitchenStations.failed")) };

        if (editing) put(route("settings.kitchen-stations.update", editing.id), options);
        else post(route("settings.kitchen-stations.store"), options);
    };

    const handleDelete = async (station) => {
        const defaultName = stations.find((s) => s.is_default)?.name;
        const { isConfirmed } = await Swal.fire({
            title: t("kitchenStations.deleteTitle", { name: station.name }),
            text: t("kitchenStations.deleteConfirm", { default: defaultName }),
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#ef4444",
            cancelButtonColor: "#64748b",
            confirmButtonText: t("common.buttons.delete"),
            cancelButtonText: t("common.buttons.cancel"),
        });
        if (!isConfirmed) return;

        router.delete(route("settings.kitchen-stations.destroy", station.id), {
            onSuccess: () => toast.success(t("kitchenStations.deleted")),
            onError: (errs) => toast.error(Object.values(errs)[0] ?? t("kitchenStations.failed")),
        });
    };

    return (
        <>
            <Head title={t("kitchenStations.title")} />

            <div className="mb-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <IconToolsKitchen2 size={28} className="text-primary-500" />
                    {t("kitchenStations.title")}
                </h1>
                <button
                    type="button"
                    onClick={() => openForm(null)}
                    className="inline-flex items-center gap-2 px-4 py-2.5 min-h-11 rounded-xl bg-primary-500 hover:bg-primary-600 text-white text-sm font-medium transition-colors"
                >
                    <IconPlus size={18} />
                    {t("kitchenStations.add")}
                </button>
            </div>

            <div className="max-w-4xl bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                {stations.length > 0 ? (
                    <ul className="divide-y divide-slate-200 dark:divide-slate-800">
                        {stations.map((station) => (
                            <li key={station.id} className="p-4 flex items-center gap-3">
                                <span className="font-semibold text-slate-800 dark:text-white truncate">
                                    {station.name}
                                </span>
                                {station.is_default && (
                                    <span className="px-2 py-0.5 rounded-lg text-xs font-medium bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                        {t("kitchenStations.default")}
                                    </span>
                                )}
                                <div className="ml-auto flex items-center gap-2 shrink-0">
                                    <button
                                        type="button"
                                        aria-label={t("kitchenStations.renameLabel", { name: station.name })}
                                        onClick={() => openForm(station)}
                                        className="size-11 inline-flex items-center justify-center rounded-xl text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                                    >
                                        <IconPencil size={18} />
                                    </button>
                                    {!station.is_default && (
                                        <button
                                            type="button"
                                            aria-label={t("kitchenStations.deleteLabel", { name: station.name })}
                                            onClick={() => handleDelete(station)}
                                            className="size-11 inline-flex items-center justify-center rounded-xl text-danger-500 hover:bg-danger-50 dark:hover:bg-danger-900/20 transition-colors"
                                        >
                                            <IconTrash size={18} />
                                        </button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="p-8 text-center text-slate-500 dark:text-slate-400">{t("kitchenStations.empty")}</p>
                )}
            </div>

            <Modal
                show={modalOpen}
                onClose={() => setModalOpen(false)}
                title={editing ? t("kitchenStations.rename") : t("kitchenStations.add")}
                maxWidth="md"
            >
                <form onSubmit={submit} className="space-y-4">
                    <Input
                        label={t("kitchenStations.name")}
                        value={data.name}
                        onChange={(e) => setData("name", e.target.value)}
                        errors={errors.name}
                        maxLength={100}
                        autoFocus
                    />
                    <div className="flex items-center gap-3">
                        <button
                            type="submit"
                            disabled={processing}
                            className="inline-flex items-center px-4 py-2.5 min-h-11 rounded-xl bg-primary-500 hover:bg-primary-600 disabled:opacity-60 text-white text-sm font-semibold transition-colors"
                        >
                            {t("common.buttons.save")}
                        </button>
                        <button
                            type="button"
                            onClick={() => setModalOpen(false)}
                            className="inline-flex items-center px-4 py-2.5 min-h-11 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 text-sm hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                        >
                            {t("common.buttons.cancel")}
                        </button>
                    </div>
                </form>
            </Modal>
        </>
    );
}

KitchenStations.layout = (page) => <DashboardLayout children={page} />;
