import React from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, router } from "@inertiajs/react";
import Button from "@/Components/Dashboard/Button";
import Search from "@/Components/Dashboard/Search";
import Table from "@/Components/Dashboard/Table";
import Pagination from "@/Components/Dashboard/Pagination";
import { useAuthorization } from "@/Utils/authorization";
import { IconCirclePlus, IconDatabaseOff, IconPencilCog, IconTrash } from "@tabler/icons-react";
import Swal from "sweetalert2";
import toast from "react-hot-toast";

const formatCurrency = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
        maximumFractionDigits: 2, // average cost per stock unit is fractional (e.g. Rp 265,08/g)
    }).format(value);

const formatNumber = (value) => new Intl.NumberFormat("id-ID").format(value);

const PURCHASED_BY = { central: "Pusat", outlet: "Outlet" };

// Not the shared delete Button: it announces success before the server answers, and
// this screen can refuse the delete (recipe component, stock history).
const confirmDelete = (material) =>
    Swal.fire({
        title: `Hapus ${material.title}?`,
        text: "Data yang dihapus tidak dapat dikembalikan!",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#6366f1",
        cancelButtonColor: "#64748b",
        confirmButtonText: "Ya, Hapus!",
        cancelButtonText: "Batal",
    }).then((result) => {
        if (!result.isConfirmed) return;
        router.delete(route("raw-materials.destroy", material.id), {
            preserveScroll: true,
            onSuccess: () => toast.success("Bahan baku berhasil dihapus"),
            onError: (errors) => toast.error(errors.delete ?? "Gagal menghapus bahan baku"),
        });
    });

export default function Index({ materials, warehouses }) {
    const { can } = useAuthorization();
    const canCreate = can("products-create");
    const canEdit = can("products-edit");
    const canDelete = can("products-delete");
    const hasActions = canEdit || canDelete;

    return (
        <>
            <Head title="Bahan Baku" />

            <div className="mb-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 dark:text-white">
                        Bahan Baku
                    </h1>
                    <p className="text-sm text-slate-500 dark:text-slate-400">
                        {materials.total} bahan baku terdaftar
                    </p>
                </div>
                {canCreate && (
                    <Button
                        type={"link"}
                        icon={<IconCirclePlus size={18} strokeWidth={1.5} />}
                        className={
                            "bg-primary-500 hover:bg-primary-600 text-white shadow-lg shadow-primary-500/30 w-full sm:w-auto justify-center"
                        }
                        label={"Tambah Bahan Baku"}
                        href={route("raw-materials.create")}
                    />
                )}
            </div>

            <div className="mb-4 w-full sm:w-80">
                <Search url={route("raw-materials.index")} placeholder="Cari nama atau SKU..." />
            </div>

            {materials.data.length > 0 ? (
                <Table.Card title="Data Bahan Baku">
                    <Table>
                        <Table.Thead>
                            <tr>
                                <Table.Th>Bahan</Table.Th>
                                <Table.Th>Kategori</Table.Th>
                                <Table.Th>Satuan Stok</Table.Th>
                                <Table.Th>Dibeli Oleh</Table.Th>
                                <Table.Th>Satuan Beli Default</Table.Th>
                                <Table.Th>Stok Min</Table.Th>
                                {warehouses.map((warehouse) => (
                                    <Table.Th key={warehouse.id}>
                                        <span title={warehouse.name}>{warehouse.code}</span>
                                        <span className="block text-xs font-normal">Stok · HPP</span>
                                    </Table.Th>
                                ))}
                                {hasActions && (
                                    <Table.Th className="relative">
                                        <span className="sr-only">Aksi</span>
                                    </Table.Th>
                                )}
                            </tr>
                        </Table.Thead>
                        <Table.Tbody>
                            {materials.data.map((material) => (
                                <tr
                                    key={material.id}
                                    className="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors"
                                >
                                    <Table.Td>
                                        <p className="text-sm font-medium text-slate-800 dark:text-slate-200">
                                            {material.title}
                                        </p>
                                        <p className="text-xs text-slate-500 dark:text-slate-400">
                                            SKU: {material.sku}
                                        </p>
                                    </Table.Td>
                                    <Table.Td>{material.category ?? "-"}</Table.Td>
                                    <Table.Td>{material.unit ?? "-"}</Table.Td>
                                    <Table.Td>
                                        {PURCHASED_BY[material.purchased_by] ?? "-"}
                                    </Table.Td>
                                    <Table.Td>
                                        {material.default_purchase_unit
                                            ? `${material.default_purchase_unit.code} (isi ${formatNumber(
                                                  material.default_purchase_unit.conversion_factor
                                              )})`
                                            : (material.unit ?? "-")}
                                    </Table.Td>
                                    <Table.Td>{formatNumber(material.min_stock)}</Table.Td>
                                    {material.stocks.map((stock) => (
                                        <Table.Td key={stock.warehouse_id}>
                                            <span
                                                className={
                                                    stock.stock <= material.min_stock
                                                        ? "font-semibold text-danger-600 dark:text-danger-400"
                                                        : "font-semibold"
                                                }
                                            >
                                                {formatNumber(stock.stock)}
                                            </span>{" "}
                                            <span className="text-xs text-slate-500 dark:text-slate-400">
                                                · {formatCurrency(stock.cost)}
                                            </span>
                                        </Table.Td>
                                    ))}
                                    {hasActions && (
                                        <Table.Td>
                                            <div className="flex gap-2">
                                                {canEdit && (
                                                    <Button
                                                        type={"edit"}
                                                        icon={<IconPencilCog size={16} strokeWidth={1.5} />}
                                                        className={
                                                            "border bg-warning-100 border-warning-200 text-warning-600 hover:bg-warning-200 dark:bg-warning-900/50 dark:border-warning-800 dark:text-warning-400"
                                                        }
                                                        href={route("raw-materials.edit", material.id)}
                                                    />
                                                )}
                                                {canDelete && (
                                                    <button
                                                        type="button"
                                                        onClick={() => confirmDelete(material)}
                                                        aria-label={`Hapus ${material.title}`}
                                                        className="inline-flex items-center justify-center px-3 py-2 rounded-xl border bg-danger-100 border-danger-200 text-danger-600 hover:bg-danger-200 dark:bg-danger-900/50 dark:border-danger-800 dark:text-danger-400 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-danger-500"
                                                    >
                                                        <IconTrash size={16} strokeWidth={1.5} />
                                                    </button>
                                                )}
                                            </div>
                                        </Table.Td>
                                    )}
                                </tr>
                            ))}
                        </Table.Tbody>
                    </Table>
                </Table.Card>
            ) : (
                <div className="flex flex-col items-center justify-center py-16 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800">
                    <div className="w-16 h-16 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center mb-4">
                        <IconDatabaseOff size={32} className="text-slate-400" strokeWidth={1.5} />
                    </div>
                    <h3 className="text-lg font-medium text-slate-800 dark:text-slate-200 mb-1">
                        Belum Ada Bahan Baku
                    </h3>
                    <p className="text-sm text-slate-500 dark:text-slate-400">
                        Tambahkan bahan baku pertama Anda untuk memulai.
                    </p>
                </div>
            )}

            {materials.last_page !== 1 && <Pagination links={materials.links} />}
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
