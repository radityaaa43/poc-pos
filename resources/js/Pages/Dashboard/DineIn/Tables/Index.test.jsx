import { useState } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { router } from "@inertiajs/react";
import toast from "react-hot-toast";
import Tables from "./Index";

const patch = vi.fn();

vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    Link: ({ children }) => children,
    router: { delete: vi.fn(), get: vi.fn() },
    useForm: (initial = {}) => {
        const [data, setData] = useState(initial);
        return { data, setData, patch, post: vi.fn(), reset: vi.fn(), processing: false, errors: {} };
    },
}));
vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));
vi.mock("@/Utils/authorization", () => ({ useAuthorization: () => ({ can: () => true }) }));
vi.mock("react-hot-toast", () => ({ default: { success: vi.fn(), error: vi.fn() } }));

const tables = [
    { id: 1, name: "Meja 1", dine_area_id: null, area: null, capacity: 4, shape: "square", pos_x: 0, pos_y: 0, sort_order: 0, is_active: true },
];
const refusal = "Meja ini punya reservasi Budi 05/01/2027 19:00 — tandai atau batalkan dulu";

async function openList() {
    const user = userEvent.setup();
    render(<Tables tables={tables} areas={[]} filters={{}} />);
    await user.click(screen.getByRole("button", { name: "Daftar" }));
    return user;
}

beforeEach(() => {
    vi.clearAllMocks();
    window.route = (name, id) => (id ? `/${name}/${id}` : `/${name}`);
    window.confirm = () => true;
});

describe("Dine tables page: a refused removal (REQ-009 AC-23)", () => {
    it.each([
        [{ table: refusal }, refusal],
        [{}, "Gagal menghapus meja."],
    ])("shows the server's reason when a delete is refused (%j)", async (errors, shown) => {
        router.delete.mockImplementation((url, options) => options.onError(errors));
        const user = await openList();

        await user.click(screen.getByRole("button", { name: "Hapus Meja 1" }));

        expect(toast.error).toHaveBeenCalledWith(shown);
    });

    it("shows the server's reason when a deactivation is refused", async () => {
        patch.mockImplementation((url, options) => options.onError({ table: refusal }));
        const user = await openList();

        await user.click(screen.getByRole("button", { name: "Edit Meja 1" }));
        await user.click(screen.getByRole("button", { name: "Perbarui" }));

        expect(toast.error).toHaveBeenCalledWith(refusal);
    });
});
