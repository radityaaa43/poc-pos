import React from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, Link, useForm } from "@inertiajs/react";
import toast from "react-hot-toast";
import { IconArrowLeft } from "@tabler/icons-react";
import RawMaterialForm from "./Partials/RawMaterialForm";

export default function Edit({ material, categories, defaultCategoryId, units }) {
    const form = useForm({
        title: material.title,
        category_id: material.category_id ?? defaultCategoryId ?? "",
        stock_unit_id: material.stock_unit_id ?? "",
        purchased_by: material.purchased_by ?? "",
        min_stock: material.min_stock ?? "",
        units: material.units,
    });

    const submit = (e) => {
        e.preventDefault();
        form.put(route("raw-materials.update", material.id), {
            onSuccess: () => toast.success("Bahan baku berhasil diperbarui"),
            onError: () => toast.error("Gagal menyimpan bahan baku"),
        });
    };

    return (
        <>
            <Head title="Edit Bahan Baku" />
            <div className="mb-6">
                <Link
                    href={route("raw-materials.index")}
                    className="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-primary-600 mb-3"
                >
                    <IconArrowLeft size={16} />
                    Kembali ke Bahan Baku
                </Link>
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Edit Bahan Baku</h1>
            </div>
            <RawMaterialForm
                form={form}
                errors={form.errors}
                onSubmit={submit}
                categories={categories}
                defaultCategoryId={defaultCategoryId}
                units={units}
                isEdit
            />
        </>
    );
}

Edit.layout = (page) => <DashboardLayout children={page} />;
