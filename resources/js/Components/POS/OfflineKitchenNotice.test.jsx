import { beforeAll, beforeEach, describe, expect, it } from "vitest";
import { act, render, screen } from "@testing-library/react";
import { Toaster } from "react-hot-toast";
import i18n from "@/i18n";
import { clearPendingTransactions, getPendingTransactions, queueTransaction } from "@/Utils/offlineDb";
import { needsKitchen, OfflineKitchenMarker, queueOfflineSale } from "./OfflineKitchenNotice";

const NOTICE = "Dapur belum menerima pesanan ini. Sampaikan langsung ke dapur.";
const kitchenCart = [{ product: { needs_preparation: false } }, { product: { needs_preparation: true } }];
const shelfCart = [{ product: { needs_preparation: false } }, {}];

const mount = () => render(<><Toaster /><OfflineKitchenMarker scopeKey="5:3" /></>);

beforeAll(() => {
    i18n.changeLanguage("id");
    // react-hot-toast reads prefers-reduced-motion, which jsdom lacks.
    window.matchMedia ??= () => ({ matches: false });
});
beforeEach(() => clearPendingTransactions());

describe("needsKitchen", () => {
    it("is true only when a cart line's product needs preparation", () => {
        expect(needsKitchen(kitchenCart)).toBe(true);
        expect(needsKitchen(shelfCart)).toBe(false);
        expect(needsKitchen([])).toBe(false);
    });
});

describe("queueOfflineSale", () => {
    it("keeps each line's product title on the queue record, not in the payload", async () => {
        const carts = [{ product_id: 12, product: { title: "Kopi Susu" } }, { product_id: 13, product: {} }];
        await act(() => queueOfflineSale({ client_uuid: "t" }, "5:3", carts));

        const [row] = await getPendingTransactions("5:3");
        expect(row.product_titles).toEqual({ 12: "Kopi Susu" });
        expect(row.data).toEqual({ client_uuid: "t" });
    });

    it("queues a shelf-only sale without notice or marker", async () => {
        mount();
        await act(() => queueOfflineSale({ client_uuid: "a" }, "5:3", shelfCart));
        expect(await getPendingTransactions("5:3")).toHaveLength(1);
        expect(screen.queryByText(NOTICE)).not.toBeInTheDocument();
        expect(screen.queryByText(/belum diterima dapur/)).not.toBeInTheDocument();
    });

    it("says the kitchen has not received the order and marks it as pending", async () => {
        mount();
        await act(() => queueOfflineSale({ client_uuid: "b" }, "5:3", kitchenCart));
        expect(await screen.findByRole("alert")).toHaveTextContent(NOTICE);
        expect(await screen.findByText("Pesanan offline yang belum diterima dapur: 1")).toBeInTheDocument();
        const [row] = await getPendingTransactions("5:3");
        expect(row.needs_kitchen).toBe(true);
        expect(row.data).toEqual({ client_uuid: "b" });
    });

    it("counts only this scope's unsynced kitchen sales", async () => {
        await queueTransaction({ client_uuid: "c" }, "5:3", { needs_kitchen: true });
        await queueTransaction({ client_uuid: "d" }, "5:3", { needs_kitchen: true, status: "synced" });
        await queueTransaction({ client_uuid: "e" }, "8:3", { needs_kitchen: true });
        mount();
        expect(await screen.findByText("Pesanan offline yang belum diterima dapur: 1")).toBeInTheDocument();
    });
});
