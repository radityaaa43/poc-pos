import { useState } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { router } from "@inertiajs/react";
import toast from "react-hot-toast";
import Swal from "sweetalert2";
import i18n from "@/i18n";
import KitchenStations from "./KitchenStations";

const post = vi.fn();
const put = vi.fn();
let formState; // what the fake useForm reports as `processing` and `errors`

vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    router: { delete: vi.fn() },
    // Just enough of Inertia's useForm: state, setData(key, value), reset, clearErrors and the verbs.
    useForm: (initial) => {
        const [data, setAll] = useState(initial);
        return {
            data,
            setData: (key, value) => setAll((prev) => ({ ...prev, [key]: value })),
            reset: () => setAll(initial),
            clearErrors: vi.fn(),
            post,
            put,
            ...formState,
        };
    },
}));
vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));
vi.mock("react-hot-toast", () => ({ default: { success: vi.fn(), error: vi.fn() } }));
vi.mock("sweetalert2", () => ({ default: { fire: vi.fn() } }));

const stations = [
    { id: 1, name: "Dapur", is_default: true, products_count: 0 },
    { id: 2, name: "Bar", is_default: false, products_count: 3 },
];

beforeEach(async () => {
    vi.clearAllMocks();
    formState = { processing: false, errors: {} };
    window.route = (name, id) => (id ? `/${name}/${id}` : `/${name}`);
    await i18n.changeLanguage("id");
});

describe("Kitchen stations page", () => {
    it("lists the stations and marks Dapur as the built-in one", () => {
        render(<KitchenStations stations={stations} />);

        expect(screen.getByRole("heading", { name: "Stasiun dapur" })).toBeInTheDocument();
        expect(screen.getByText("Dapur")).toBeInTheDocument();
        expect(screen.getByText("Bar")).toBeInTheDocument();
        expect(screen.getAllByText("Bawaan")).toHaveLength(1);
    });

    it("offers delete for a normal station but not for Dapur", () => {
        render(<KitchenStations stations={stations} />);

        expect(screen.getByRole("button", { name: "Hapus stasiun Bar" })).toBeInTheDocument();
        expect(screen.queryByRole("button", { name: "Hapus stasiun Dapur" })).not.toBeInTheDocument();
        expect(screen.getByRole("button", { name: "Ubah nama Dapur" })).toBeInTheDocument();
    });

    it("shows a defensive empty state", () => {
        render(<KitchenStations stations={[]} />);

        expect(screen.getByText("Belum ada stasiun")).toBeInTheDocument();
    });

    it("adds a station: focused labelled input, posts the name, toasts and closes", async () => {
        render(<KitchenStations stations={stations} />);

        await userEvent.click(screen.getByRole("button", { name: "Tambah stasiun" }));
        const input = screen.getByLabelText("Nama stasiun");
        expect(screen.getByRole("dialog")).toBeInTheDocument();
        await waitFor(() => expect(input).toHaveFocus());

        await userEvent.type(input, "Pastry");
        await userEvent.click(screen.getByRole("button", { name: "Simpan" }));

        expect(post).toHaveBeenCalledTimes(1);
        const [url, options] = post.mock.calls[0];
        expect(url).toBe("/settings.kitchen-stations.store");
        options.onSuccess();
        expect(toast.success).toHaveBeenCalledWith("Stasiun ditambahkan");
        await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
    });

    it("renames a station: prefilled, puts to the update route with its id", async () => {
        render(<KitchenStations stations={stations} />);

        await userEvent.click(screen.getByRole("button", { name: "Ubah nama Bar" }));
        const input = screen.getByLabelText("Nama stasiun");
        expect(input).toHaveValue("Bar");

        await userEvent.clear(input);
        await userEvent.type(input, "Bar Atas");
        await userEvent.click(screen.getByRole("button", { name: "Simpan" }));

        expect(put).toHaveBeenCalledTimes(1);
        expect(put.mock.calls[0][0]).toBe("/settings.kitchen-stations.update/2");
        put.mock.calls[0][1].onSuccess();
        expect(toast.success).toHaveBeenCalledWith("Nama stasiun diubah");
    });

    it("shows the server's validation error next to the name field", async () => {
        formState.errors = { name: "Nama stasiun sudah dipakai." };
        render(<KitchenStations stations={stations} />);

        await userEvent.click(screen.getByRole("button", { name: "Tambah stasiun" }));

        expect(screen.getByText("Nama stasiun sudah dipakai.")).toBeInTheDocument();
        expect(screen.getByLabelText("Nama stasiun")).toHaveAttribute("aria-invalid", "true");
    });

    it("disables Simpan while the request is processing", async () => {
        formState.processing = true;
        render(<KitchenStations stations={stations} />);

        await userEvent.click(screen.getByRole("button", { name: "Tambah stasiun" }));

        expect(screen.getByRole("button", { name: "Simpan" })).toBeDisabled();
    });

    it("deletes only after the confirmation, which states the rule", async () => {
        Swal.fire.mockResolvedValue({ isConfirmed: true });
        render(<KitchenStations stations={stations} />);

        await userEvent.click(screen.getByRole("button", { name: "Hapus stasiun Bar" }));

        expect(Swal.fire).toHaveBeenCalledTimes(1);
        expect(Swal.fire.mock.calls[0][0].text).toMatch(/pindah ke Dapur/);
        await waitFor(() => expect(router.delete).toHaveBeenCalledTimes(1));
        const [url, options] = router.delete.mock.calls[0];
        expect(url).toBe("/settings.kitchen-stations.destroy/2");
        options.onSuccess();
        expect(toast.success).toHaveBeenCalledWith("Stasiun dihapus");
        options.onError({ station: "Dapur tidak bisa dihapus." });
        expect(toast.error).toHaveBeenCalledWith("Dapur tidak bisa dihapus.");
    });

    it("does not delete when the confirmation is cancelled", async () => {
        Swal.fire.mockResolvedValue({ isConfirmed: false });
        render(<KitchenStations stations={stations} />);

        await userEvent.click(screen.getByRole("button", { name: "Hapus stasiun Bar" }));

        await waitFor(() => expect(Swal.fire).toHaveBeenCalled());
        expect(router.delete).not.toHaveBeenCalled();
    });

    it("renders in English too", async () => {
        await i18n.changeLanguage("en");
        render(<KitchenStations stations={stations} />);

        expect(screen.getByRole("heading", { name: "Kitchen stations" })).toBeInTheDocument();
        expect(screen.getByRole("button", { name: "Add station" })).toBeInTheDocument();
        expect(screen.getByText("Built-in")).toBeInTheDocument();
        expect(screen.getByRole("button", { name: "Delete station Bar" })).toBeInTheDocument();
    });
});
