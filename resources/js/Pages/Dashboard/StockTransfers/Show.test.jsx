import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import toast from "react-hot-toast";
import { router } from "@inertiajs/react";
import Show from "./Show";

vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    Link: ({ children }) => <a>{children}</a>,
    router: { post: vi.fn() },
    usePage: () => ({ props: { flash: { success: "Transfer stok berhasil dikirim." } } }),
}));
vi.mock("react-hot-toast", () => ({
    default: { success: vi.fn(), error: vi.fn() },
}));
vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));
vi.mock("@/Utils/authorization", () => ({ useAuthorization: () => ({ can: () => true }) }));

const transfer = {
    id: 7,
    document_number: "TRF-7",
    status: "draft",
    items: [],
    source_warehouse: { name: "Gudang A" },
    destination_warehouse: { name: "Gudang B" },
};

beforeEach(() => {
    vi.clearAllMocks();
    vi.spyOn(window, "confirm").mockReturnValue(true);
});

describe("StockTransfers/Show", () => {
    it("tells the user why a refused send was refused", async () => {
        render(<Show transfer={transfer} />);

        await userEvent.click(screen.getByRole("button", { name: /Kirim Barang/ }));

        const { onError } = router.post.mock.calls[0][2];
        onError({ transfer: "Stok Kopi tidak mencukupi di gudang asal (tersedia: 5)." });

        expect(toast.error).toHaveBeenCalledWith("Stok Kopi tidak mencukupi di gudang asal (tersedia: 5).");
    });

    it("shows the success flash after an action", () => {
        render(<Show transfer={transfer} />);

        expect(toast.success).toHaveBeenCalledWith("Transfer stok berhasil dikirim.");
    });
});
