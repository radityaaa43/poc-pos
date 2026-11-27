import { describe, it, expect, vi, beforeAll } from "vitest";
import { render, screen } from "@testing-library/react";
import i18n from "@/i18n";
import DineOrderStatus from "./DineOrderStatus";

vi.mock("@inertiajs/react", () => ({ Head: () => null }));

const base = { id: 1, access_token: "tok", subtotal: 20000, items: [] };
const table = { id: 1, token: "t", name: "M1" };

const label = (order) => {
    render(<DineOrderStatus order={{ ...base, ...order }} table={table} storeName="Toko" />);
    return screen.getByTestId("dine-status").textContent;
};

beforeAll(() => i18n.changeLanguage("id"));

describe("DineOrderStatus", () => {
    it.each(["accepted", "preparing", "ready", "served"])("offers Tambah pesanan while the order is %s", (status) => {
        const route = vi.spyOn(globalThis, "route");
        render(<DineOrderStatus order={{ ...base, status }} table={table} storeName="Toko" />);

        expect(screen.getByRole("link", { name: "Tambah pesanan" })).toBeInTheDocument();
        expect(route).toHaveBeenCalledWith("dine.menu", { token: "t", add_on_to: "tok" });
        route.mockRestore();
    });

    it.each(["submitted", "completed", "rejected", "cancelled"])("offers no add-on for a %s order", (status) => {
        render(<DineOrderStatus order={{ ...base, status }} table={table} storeName="Toko" />);

        expect(screen.queryByRole("link", { name: "Tambah pesanan" })).not.toBeInTheDocument();
    });

    it.each([
        ["submitted", "Menunggu Konfirmasi"],
        ["accepted", "Menunggu Pembayaran"],
        ["preparing", "Sedang dibuat"],
        ["ready", "Siap"],
        ["served", "Disajikan"],
        ["completed", "Selesai"],
    ])("shows the %s status", (status, expected) => {
        expect(label({ status })).toBe(expected);
    });

    it("shows Diterima once an accepted order is paid", () => {
        expect(label({ status: "accepted", is_paid: true })).toBe("Diterima");
    });

    it("marks the current step in the progress list once accepted", () => {
        render(<DineOrderStatus order={{ ...base, status: "ready" }} table={table} storeName="Toko" />);
        expect(screen.getByRole("listitem", { current: "step" })).toHaveTextContent("Siap");
        expect(screen.getAllByRole("listitem").map((item) => item.textContent)).toEqual([
            "Diterima",
            "Menunggu Pembayaran",
            "Sedang dibuat",
            "Siap",
            "Disajikan",
            "Selesai",
        ]);
    });
});
