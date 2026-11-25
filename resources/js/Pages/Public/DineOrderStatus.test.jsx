import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import DineOrderStatus from "./DineOrderStatus";

vi.mock("@inertiajs/react", () => ({ Head: () => null }));

const base = { id: 1, access_token: "tok", subtotal: 20000, items: [] };
const table = { id: 1, token: "t", name: "M1" };

const label = (order) => {
    render(<DineOrderStatus order={{ ...base, ...order }} table={table} storeName="Toko" />);
    return screen.getByTestId("dine-status").textContent;
};

describe("DineOrderStatus", () => {
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
