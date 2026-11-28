import React from "react";
import { useTranslation } from "react-i18next";

export const ORDER_TYPE_LABELS = { in_store: "Di Tempat", takeaway: "Bawa Pulang", delivery: "Diantar" };

/** "Nama pesanan: X" in the current language, or null when the sale has no order name. */
export function useOrderNameLine(name) {
    const { t } = useTranslation();

    return name ? `${t("receipt.orderName")}: ${name}` : null;
}

export default function OrderNameLine({ name, className }) {
    const line = useOrderNameLine(name);

    return line && <p className={className}>{line}</p>;
}
