import React from "react";

const selectClass = (error) =>
    `h-11 w-full min-w-0 rounded-xl border bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 disabled:cursor-not-allowed disabled:text-slate-500 dark:bg-slate-800 dark:text-slate-200 dark:disabled:text-slate-400 ${
        error ? "border-danger-500" : "border-slate-200 dark:border-slate-700"
    }`;

export default function Select({ id, label, error, children, ...props }) {
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

// Shared by the Raw Materials and Products forms so the options read the same.
export function PurchasedBySelect({ error, ...props }) {
    return (
        <Select id="purchased_by" label="Dibeli Oleh" error={error} {...props}>
            <option value="">Pilih pembeli</option>
            <option value="central">Pusat (gudang pusat)</option>
            <option value="outlet">Outlet (tiap outlet)</option>
        </Select>
    );
}
