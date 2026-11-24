import React from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, Link, useForm } from "@inertiajs/react";
import toast from "react-hot-toast";
import { IconArrowLeft } from "@tabler/icons-react";
import RawMaterialForm from "./Partials/RawMaterialForm";

export default function Create({ categories, defaultCategoryId, units, warehouses }) {
    const form = useForm({
        title: "",
        category_id: defaultCategoryId ?? "",
        stock_unit_id: "",
        purchased_by: "",
        min_stock: "",
        units: [],
        opening_warehouse_id: "",
        opening_qty: "",
        opening_unit_id: "",
    });

    // The opening unit select shows the stock unit until the user picks another one.
    form.transform((data) => ({ ...data, opening_unit_id: data.opening_unit_id || data.stock_unit_id }));

    const submit = (e) => {
        e.preventDefault();
        form.post(route("raw-materials.store"), {
            onSuccess: () => toast.success("Bahan baku berhasil ditambahkan"),
            onError: () => toast.error("Gagal menyimpan bahan baku"),
        });
    };

    return (
        <>
            <Head title="Tambah Bahan Baku" />
            <div className="mb-6">
                <Link
                    href={route("raw-materials.index")}
                    className="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-primary-600 mb-3"
                >
                    <IconArrowLeft size={16} />
                    Kembali ke Bahan Baku
                </Link>
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Tambah Bahan Baku</h1>
            </div>
            <RawMaterialForm
                form={form}
                errors={form.errors}
                onSubmit={submit}
                categories={categories}
                defaultCategoryId={defaultCategoryId}
                units={units}
                warehouses={warehouses}
            />
        </>
    );
}

Create.layout = (page) => <DashboardLayout children={page} />;
