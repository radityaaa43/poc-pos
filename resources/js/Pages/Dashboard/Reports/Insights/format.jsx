export const defaultFilters = {
    start_date: "",
    end_date: "",
    cashier_id: "",
    customer_id: "",
    category_id: "",
};

export const formatCurrency = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value);

export const formatPercentage = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    }).format(value);

export const formatDateTime = (value) =>
    value
        ? new Intl.DateTimeFormat("id-ID", {
              dateStyle: "medium",
              timeStyle: "short",
          }).format(new Date(value))
        : "-";

export const coverageStatusConfig = {
    critical: {
        label: "Kritis",
        className:
            "bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300",
    },
    low: {
        label: "Rendah",
        className:
            "bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300",
    },
    healthy: {
        label: "Sehat",
        className:
            "bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300",
    },
    no_movement: {
        label: "Tidak Bergerak",
        className:
            "bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300",
    },
};

export const promoStatusConfig = {
    active: {
        label: "Aktif",
        className:
            "bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300",
    },
    scheduled: {
        label: "Terjadwal",
        className:
            "bg-sky-100 text-sky-700 dark:bg-sky-950/50 dark:text-sky-300",
    },
    expired: {
        label: "Berakhir",
        className:
            "bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300",
    },
    inactive: {
        label: "Nonaktif",
        className:
            "bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300",
    },
};

export const promoKindLabel = {
    standard_discount: "Discount",
    qty_break: "Grosir",
    bundle_price: "Bundle",
    buy_x_get_y: "BXGY",
};

export const crmCampaignTypeLabel = {
    promo_broadcast: "Promo Broadcast",
    invoice_share: "Invoice Share",
    due_date_reminder: "Due Reminder",
    repeat_order_reminder: "Repeat Order",
};
