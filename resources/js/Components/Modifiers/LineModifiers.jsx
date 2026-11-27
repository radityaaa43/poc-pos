import React from "react";
import { useTranslation } from "react-i18next";
import i18n from "@/i18n";

/**
 * A line's options ("+ Large 5.000") and note, as the cart and receipts show them. Takes a sale line
 * (`modifiers`, the snapshot) or a cart line's preview (`options`).
 */
export function modifierLines({ modifiers, options, note } = {}) {
    const lines = (modifiers ?? options ?? []).map((option) => {
        const price = Number(option.price_delta);

        return `+ ${option.option_name ?? option.name}${price ? ` ${price.toLocaleString("id-ID")}` : ""}`;
    });

    return note ? [...lines, i18n.t("pos.modifiers.noteLine", { note })] : lines;
}

export default function LineModifiers({ item, className = "text-xs text-slate-500 dark:text-slate-400" }) {
    useTranslation(); // re-render when the language changes

    return modifierLines(item).map((line, index) => (
        <p key={index} className={`break-words ${className}`}>
            {line}
        </p>
    ));
}
