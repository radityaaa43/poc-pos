import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, fireEvent } from "@testing-library/react";
import toast from "react-hot-toast";
import { router } from "@inertiajs/react";
import Index from "./Index";

vi.mock("react-hot-toast", () => ({ default: { success: vi.fn(), error: vi.fn() } }));
vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));
vi.mock("@/Components/Dashboard/Search", () => ({ default: () => null }));
vi.mock("@/Components/Dashboard/Button", () => ({ default: () => null }));
vi.mock("@/Components/Dashboard/Pagination", () => ({ default: () => null }));
vi.mock("@/Components/Barcode/BarcodePrintModal", () => ({ default: () => null }));
vi.mock("@/Utils/authorization", () => ({ useAuthorization: () => ({ can: () => true }) }));
vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    Link: ({ children }) => children,
    usePage: () => ({ props: {} }),
    router: { post: vi.fn() },
}));

describe("Products page import", () => {
    beforeEach(() => {
        vi.clearAllMocks();
        window.route = (name) => `/${name}`;
    });

    it("shows the server's result message after a successful import", () => {
        const { container } = render(<Index products={{ data: [], links: [], total: 0 }} />);

        fireEvent.change(container.querySelector("#import-products-input"), {
            target: { files: [new File(["x"], "products.csv")] },
        });
        router.post.mock.calls[0][2].onSuccess({ props: { flash: { success: "Import selesai. 3 produk diimport." } } });

        expect(toast.success).toHaveBeenCalledWith("Import selesai. 3 produk diimport.");
    });
});
