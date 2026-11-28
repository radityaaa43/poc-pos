import { useState } from "react";
import { router } from "@inertiajs/react";
import toast from "react-hot-toast";
import { useTranslation } from "react-i18next";

const defaultOptionIds = (groups) =>
    groups.flatMap((group) => group.options.filter((option) => option.is_default && option.available).map((option) => option.id));

export default function useAddToCart(modifierGroups = {}) {
    const { t } = useTranslation();
    const [addingProductId, setAddingProductId] = useState(null);
    const [picking, setPicking] = useState(null);

    const post = (product, unit, choice = {}) => {
        setAddingProductId(product.id);

        router.post(
            route("transactions.addToCart"),
            { product_id: product.id, qty: 1, unit_id: unit ? unit.unit_id : undefined, ...choice },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(`${product.title}${unit ? ` (${unit.code})` : ""} ditambahkan`);
                    if (choice.modifier_option_ids) setPicking(null);
                    setAddingProductId(null);
                },
                onError: (errors) => {
                    toast.error(errors?.message || errors?.modifiers || errors?.note || "Gagal menambahkan produk");
                    setAddingProductId(null);
                },
            }
        );
    };

    const addToCart = (product, unit = null) => {
        if (!product?.id) return false;

        if (product.modifiers_sold_out) {
            toast.error(`${product.title}: ${t("pos.modifiers.soldOut")}`);
            return false;
        }

        if (modifierGroups[product.id]?.length) {
            // A scan while the panel is open does not swap the menu under the cashier.
            setPicking((current) => current ?? { product, unit });
            return false;
        }

        post(product, unit);
        return true;
    };

    let panel = null;

    if (picking) {
        const groups = modifierGroups[picking.product.id] ?? [];

        panel = {
            title: picking.product.title,
            basePrice: picking.unit?.sell_price ?? picking.product.menu_price,
            groups,
            selected: defaultOptionIds(groups),
            labels: {
                required: t("pos.modifiers.required"),
                optional: t("pos.modifiers.optional"),
                range: (group) => t("pos.modifiers.range", { min: group.min_select, max: group.max_select }),
                soldOut: t("pos.modifiers.soldOut"),
                note: t("pos.modifiers.note"),
                noteCount: (count) => t("pos.modifiers.noteCount", { count }),
                portionPrice: t("pos.modifiers.portionPrice"),
                incomplete: (group) => t("pos.modifiers.incomplete", { group }),
                add: t("pos.modifiers.add"),
                cancel: t("pos.modifiers.cancel"),
            },
            processing: addingProductId !== null,
            onClose: () => setPicking(null),
            onAdd: ({ optionIds, note }) => post(picking.product, picking.unit, { modifier_option_ids: optionIds, note }),
        };
    }

    return { addToCart, addingProductId, panel };
}
