import React from "react";
import { IconShoppingCart } from "@tabler/icons-react";

/** The POS cart with nothing in it. */
export default function EmptyCart() {
    return (
        <div className="py-6 text-center">
            <IconShoppingCart
                size={32}
                className="mx-auto text-slate-300 dark:text-slate-600 mb-2"
            />
            <p className="text-sm text-slate-400">
                Keranjang kosong
            </p>
        </div>
    );
}
