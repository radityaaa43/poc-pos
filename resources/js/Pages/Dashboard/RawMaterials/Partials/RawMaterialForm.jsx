import React from "react";
import { Link } from "@inertiajs/react";
import Input from "@/Components/Dashboard/Input";
import { IconArrowLeft, IconDeviceFloppy, IconPlus, IconTrash } from "@tabler/icons-react";

const selectClass = (error) =>
    `h-11 w-full min-w-0 rounded-xl border bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 disabled:cursor-not-allowed disabled:text-slate-500 dark:bg-slate-800 dark:text-slate-200 dark:disabled:text-slate-400 ${
        error ? "border-danger-500" : "border-slate-200 dark:border-slate-700"
    }`;

const formatCurrency = (value) =>
    new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 }).format(value);

function Select({ id, label, error, children, ...props }) {
    return (
        <div className="flex flex-col gap-2">
            <label htmlFor={id} className="text-sm font-medium text-slate-700 dark:text-slate-300">
                {label}
            </label>
            <select
                id={id}
                aria-invalid={Boolean(error)}
                aria-describedby={error ? `${id}-error` : undefined}
                className={selectClass(error)}
                {...props}
            >
                {children}
            </select>
            {error && (
                <small id={`${id}-error`} className="text-xs text-danger-500 dark:text-danger-400">
                    {error}
                </small>
            )}
        </div>
    );
}

function Card({ title, hint, children }) {
    return (
        <section className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5">
            <h2 className="text-sm font-semibold text-slate-700 dark:text-slate-300">{title}</h2>
            {hint && <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">{hint}</p>}
            <div className="mt-4">{children}</div>
        </section>
    );
}

// Create and Edit share this form; only Create takes opening stock (`warehouses`), and the
// stock unit is locked on Edit because stock is already counted in it.
export default function RawMaterialForm({
    form,
    errors,
    onSubmit,
    categories,
    defaultCategoryId,
    units,
    warehouses,
    isEdit = false,
}) {
    const { data, setData, processing } = form;
    const stockUnit = units.find((u) => u.id === Number(data.stock_unit_id));
    const purchaseUnits = data.units;
    const taken = (index) => purchaseUnits.filter((_, i) => i !== index).map((r) => r.unit_id);
    const defaultRow = purchaseUnits.find((r) => r.is_purchase_default);
    const referencePrice =
        defaultRow && Number(defaultRow.conversion_factor) > 0 && defaultRow.buy_price !== ""
            ? Math.round(Number(defaultRow.buy_price) / Number(defaultRow.conversion_factor))
            : null;
    const openingUnits = units.filter(
        (u) => u.id === Number(data.stock_unit_id) || purchaseUnits.some((r) => Number(r.unit_id) === u.id)
    );

    const setRow = (index, field, value) =>
        setData(
            "units",
            purchaseUnits.map((row, i) => {
                if (i === index) return { ...row, [field]: value };
                // Only one default purchase unit.
                return field === "is_purchase_default" && value ? { ...row, is_purchase_default: false } : row;
            })
        );

    const addRow = () => {
        const free = units.find(
            (u) => u.id !== Number(data.stock_unit_id) && !purchaseUnits.some((r) => Number(r.unit_id) === u.id)
        );
        if (!free) return;
        setData("units", [
            ...purchaseUnits,
            { unit_id: free.id, conversion_factor: "", buy_price: "", is_purchase_default: purchaseUnits.length === 0 },
        ]);
    };

    return (
        <form onSubmit={onSubmit} className="space-y-6 max-w-4xl">
            <Card title="Informasi Dasar">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div className="md:col-span-2">
                        <Input
                            label="Nama Bahan"
                            type="text"
                            aria-label="Nama Bahan"
                            value={data.title}
                            onChange={(e) => setData("title", e.target.value)}
                            errors={errors.title}
                            placeholder="Contoh: Biji kopi"
                        />
                    </div>
                    <Select
                        id="category_id"
                        label="Kategori"
                        value={data.category_id}
                        onChange={(e) => setData("category_id", e.target.value)}
                        error={errors.category_id}
                    >
                        {!defaultCategoryId && <option value="">Bahan Baku (dibuat otomatis)</option>}
                        {categories.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name}
                            </option>
                        ))}
                    </Select>
                    <Select
                        id="purchased_by"
                        label="Dibeli Oleh"
                        value={data.purchased_by}
                        onChange={(e) => setData("purchased_by", e.target.value)}
                        error={errors.purchased_by}
                    >
                        <option value="">Pilih pembeli</option>
                        <option value="central">Pusat (gudang pusat)</option>
                        <option value="outlet">Outlet (tiap outlet)</option>
                    </Select>
                    <Select
                        id="stock_unit_id"
                        label="Satuan Stok"
                        value={data.stock_unit_id}
                        onChange={(e) => setData("stock_unit_id", Number(e.target.value) || "")}
                        error={errors.stock_unit_id}
                        disabled={isEdit && Boolean(data.stock_unit_id)}
                    >
                        <option value="">Pilih satuan</option>
                        {units.map((u) => (
                            <option key={u.id} value={u.id}>
                                {u.code}
                            </option>
                        ))}
                    </Select>
                    <Input
                        label={`Stok Minimum${stockUnit ? ` (${stockUnit.code})` : ""}`}
                        type="number"
                        min="0"
                        inputMode="numeric"
                        aria-label="Stok Minimum"
                        value={data.min_stock}
                        onChange={(e) => setData("min_stock", e.target.value)}
                        errors={errors.min_stock}
                        placeholder="0"
                    />
                </div>
            </Card>

            <Card
                title="Satuan Beli"
                hint="Satuan saat membeli dari supplier, misalnya kg atau karung. Isi = berapa satuan stok dalam satu satuan beli."
            >
                {errors.units && <p className="mb-3 text-xs text-danger-500 dark:text-danger-400">{errors.units}</p>}
                <div className="divide-y divide-slate-100 dark:divide-slate-800">
                    {purchaseUnits.map((row, index) => {
                        const err = (field) => errors[`units.${index}.${field}`];
                        return (
                            <div key={index} className="grid grid-cols-2 gap-3 py-4 first:pt-0 last:pb-0 xl:grid-cols-4">
                                <div className="col-span-2 xl:col-span-1">
                                    <Select
                                        id={`units-${index}-unit_id`}
                                        label="Satuan Beli"
                                        value={row.unit_id}
                                        onChange={(e) => setRow(index, "unit_id", Number(e.target.value) || "")}
                                        error={err("unit_id")}
                                    >
                                        {units
                                            .filter((u) => u.id !== Number(data.stock_unit_id) && !taken(index).includes(u.id))
                                            .map((u) => (
                                                <option key={u.id} value={u.id}>
                                                    {u.code}
                                                </option>
                                            ))}
                                    </Select>
                                </div>
                                <Input
                                    label={`Isi${stockUnit ? ` (${stockUnit.code})` : ""}`}
                                    type="number"
                                    min="0.0001"
                                    step="0.0001"
                                    inputMode="decimal"
                                    aria-label="Isi per satuan beli"
                                    value={row.conversion_factor}
                                    onChange={(e) => setRow(index, "conversion_factor", e.target.value)}
                                    errors={err("conversion_factor")}
                                    placeholder="1000"
                                />
                                <Input
                                    label="Harga per Satuan (Rp)"
                                    type="number"
                                    min="0"
                                    inputMode="numeric"
                                    aria-label="Harga per satuan beli"
                                    value={row.buy_price}
                                    onChange={(e) => setRow(index, "buy_price", e.target.value)}
                                    errors={err("buy_price")}
                                    placeholder="0"
                                />
                                <div className="col-span-2 xl:col-span-1 flex items-end gap-2">
                                    <label
                                        className={`inline-flex h-11 min-w-0 flex-1 cursor-pointer items-center justify-center gap-2 rounded-xl border px-3 text-sm font-medium transition-colors ${
                                            row.is_purchase_default
                                                ? "border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-950/30 dark:text-primary-300"
                                                : "border-slate-200 bg-slate-50 text-slate-600 hover:border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300"
                                        }`}
                                    >
                                        <input
                                            type="radio"
                                            name="default_purchase_unit"
                                            checked={Boolean(row.is_purchase_default)}
                                            onChange={() => setRow(index, "is_purchase_default", true)}
                                            className="border-slate-300 text-primary-600 focus:ring-primary-500"
                                        />
                                        <span className="truncate">Beli default</span>
                                    </label>
                                    <button
                                        type="button"
                                        onClick={() => setData("units", purchaseUnits.filter((_, i) => i !== index))}
                                        aria-label="Hapus satuan beli"
                                        className="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-danger-600 transition-colors hover:bg-danger-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-danger-500 dark:hover:bg-danger-950/30"
                                    >
                                        <IconTrash size={18} />
                                    </button>
                                </div>
                            </div>
                        );
                    })}
                </div>
                <div className="mt-4 flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        onClick={addRow}
                        disabled={!data.stock_unit_id}
                        className="inline-flex h-11 items-center gap-2 rounded-xl border border-slate-200 px-4 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                    >
                        <IconPlus size={16} />
                        Tambah Satuan Beli
                    </button>
                    {!data.stock_unit_id && (
                        <span className="text-xs text-slate-500 dark:text-slate-400">Pilih satuan stok dulu.</span>
                    )}
                    {referencePrice !== null && stockUnit && (
                        <span className="text-xs text-slate-500 dark:text-slate-400">
                            Harga acuan: {formatCurrency(referencePrice)} per {stockUnit.code}
                        </span>
                    )}
                </div>
            </Card>

            {warehouses && (
                <Card title="Stok Awal (opsional)" hint="Dicatat sebagai stok awal di satu gudang yang bisa Anda akses.">
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <Select
                            id="opening_warehouse_id"
                            label="Gudang"
                            value={data.opening_warehouse_id}
                            onChange={(e) => setData("opening_warehouse_id", e.target.value)}
                            error={errors.opening_warehouse_id}
                        >
                            <option value="">Tanpa stok awal</option>
                            {warehouses.map((w) => (
                                <option key={w.id} value={w.id}>
                                    {w.code} - {w.name}
                                </option>
                            ))}
                        </Select>
                        <Input
                            label="Jumlah"
                            type="number"
                            min="0"
                            step="any"
                            inputMode="decimal"
                            aria-label="Jumlah stok awal"
                            value={data.opening_qty}
                            onChange={(e) => setData("opening_qty", e.target.value)}
                            errors={errors.opening_qty}
                            placeholder="0"
                        />
                        <Select
                            id="opening_unit_id"
                            label="Satuan"
                            value={data.opening_unit_id || data.stock_unit_id}
                            onChange={(e) => setData("opening_unit_id", Number(e.target.value) || "")}
                            error={errors.opening_unit_id}
                        >
                            {openingUnits.map((u) => (
                                <option key={u.id} value={u.id}>
                                    {u.code}
                                </option>
                            ))}
                        </Select>
                    </div>
                </Card>
            )}

            <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3">
                <Link
                    href={route("raw-materials.index")}
                    className="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 text-sm font-medium text-slate-600 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                >
                    <IconArrowLeft size={16} />
                    Batal
                </Link>
                <button
                    type="submit"
                    disabled={processing}
                    className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-primary-500 px-5 text-sm font-medium text-white shadow-lg shadow-primary-500/30 transition-colors hover:bg-primary-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:opacity-60"
                >
                    <IconDeviceFloppy size={18} />
                    Simpan
                </button>
            </div>
        </form>
    );
}
