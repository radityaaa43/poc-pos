import { describe, it, expect, vi, beforeAll } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { router } from "@inertiajs/react";
import toast from "react-hot-toast";
import i18n from "@/i18n";
import Tables from "./Index";

vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    router: { delete: vi.fn(), get: vi.fn() },
    useForm: (initial = {}) => ({ data: initial, setData: vi.fn(), patch: vi.fn(), post: vi.fn(), reset: vi.fn(), processing: false, errors: {} }),
}));
vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));
vi.mock("@/Utils/authorization", () => ({ useAuthorization: () => ({ can: () => true }) }));
vi.mock("react-hot-toast", () => ({ default: { success: vi.fn(), error: vi.fn() } }));

const table = { dine_area_id: null, area: null, capacity: 4, shape: "square", pos_x: 0, pos_y: 0, sort_order: 0, is_active: true };
const tables = [
    { ...table, id: 1, name: "Meja 1", status: "occupied", open_bills: 2 },
    { ...table, id: 2, name: "Meja 2", pos_x: 2, status: "free", open_bills: 0 },
];

beforeAll(() => i18n.changeLanguage("id"));

describe("Tables page status", () => {
    it("lists each table's status and unpaid bills, and why a busy one cannot be deleted", async () => {
        const user = userEvent.setup();
        vi.spyOn(window, "confirm").mockReturnValue(true);
        render(<Tables tables={tables} areas={[]} filters={{}} />);
        await user.click(screen.getByRole("button", { name: "Daftar" }));

        expect(screen.getByRole("row", { name: /Meja 1/ })).toHaveTextContent("Terisi");
        expect(screen.getByRole("row", { name: /Meja 1/ })).toHaveTextContent("2 tagihan Belum Lunas");
        expect(screen.getByRole("row", { name: /Meja 2/ })).toHaveTextContent("Kosong");

        await user.click(screen.getByRole("button", { name: "Hapus Meja 1" }));
        const refusal = "Meja ini masih punya pesanan #41. Selesaikan atau batalkan dulu.";
        router.delete.mock.calls[0][1].onError({ table: refusal });

        expect(toast.error).toHaveBeenCalledWith(refusal);
    });
});
