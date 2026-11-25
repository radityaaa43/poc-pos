import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import AreasIndex from "./Areas/Index";
import TablesIndex from "./Tables/Index";

// The pages hand useForm's `errors` to Input; a wrong prop name dropped every
// field message (#120), so the fake useForm reports one error per field.
vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    router: { post: vi.fn(), delete: vi.fn(), get: vi.fn(), patch: vi.fn() },
    usePage: () => ({ props: {} }),
    useForm: (initial) => ({
        data: initial,
        setData: vi.fn(),
        post: vi.fn(),
        patch: vi.fn(),
        processing: false,
        reset: vi.fn(),
        errors: {
            name: "The name field is required.",
            capacity: "The capacity must be at least 0.",
            pos_x: "The pos x must not be greater than 24.",
            pos_y: "The pos y must not be greater than 14.",
            sort_order: "The sort order must be an integer.",
        },
    }),
}));
vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));
vi.mock("@/Utils/authorization", () => ({ useAuthorization: () => ({ can: () => true }) }));
vi.mock("react-hot-toast", () => ({ default: { success: vi.fn(), error: vi.fn() } }));

describe("Dine-in forms show field validation errors", () => {
    it("area form shows the name and sort order errors", async () => {
        render(<AreasIndex areas={[]} />);
        await userEvent.click(screen.getAllByRole("button", { name: /Tambah Area/ })[0]);

        expect(screen.getByText("The name field is required.")).toBeTruthy();
        expect(screen.getByText("The sort order must be an integer.")).toBeTruthy();
    });

    it("table form shows the name, capacity and position errors", async () => {
        render(<TablesIndex tables={[]} areas={[]} filters={{}} />);
        await userEvent.click(screen.getAllByRole("button", { name: /Tambah Meja/ })[0]);

        expect(screen.getByText("The name field is required.")).toBeTruthy();
        expect(screen.getByText("The capacity must be at least 0.")).toBeTruthy();
        expect(screen.getByText("The pos x must not be greater than 24.")).toBeTruthy();
        expect(screen.getByText("The pos y must not be greater than 14.")).toBeTruthy();
    });
});
