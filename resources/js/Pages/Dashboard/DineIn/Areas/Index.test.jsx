import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { router } from "@inertiajs/react";
import Index from "./Index";

vi.mock("@inertiajs/react", async (importActual) => ({
    ...(await importActual()),
    Head: () => null,
}));
vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));
vi.mock("@/Utils/authorization", () => ({ useAuthorization: () => ({ can: () => true }) }));
vi.mock("react-hot-toast", () => ({ default: { success: vi.fn(), error: vi.fn() } }));

const areas = [{ id: 1, name: "Indoor", sort_order: 0, is_active: true, tables: [] }];

describe("Dine areas page", () => {
    beforeEach(() => {
        window.route = (name) => `/${name}`;
        vi.spyOn(router, "visit").mockImplementation(() => {});
    });

    // #210: the pencil was an Inertia <Link> without href, so the click also GET the page
    // and the reload closed the dialog and wiped what the user typed.
    it("opens the edit dialog without making an Inertia visit", async () => {
        render(<Index areas={areas} />);

        await userEvent.click(screen.getByRole("button", { name: "Edit Indoor" }));

        expect(router.visit).not.toHaveBeenCalled();
        expect(screen.getByRole("dialog")).toBeInTheDocument();
        expect(screen.getByDisplayValue("Indoor")).toBeInTheDocument();
    });
});
