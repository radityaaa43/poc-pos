import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import toast from "react-hot-toast";
import Swal from "sweetalert2";
import Index from "./Index";

const destroy = vi.fn();
let pageProps;

vi.mock("react-hot-toast", () => ({ default: { success: vi.fn(), error: vi.fn() } }));
vi.mock("sweetalert2", () => ({ default: { fire: vi.fn() } }));
vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));
vi.mock("@/Components/Dashboard/Search", () => ({ default: () => null }));
vi.mock("@/Components/Dashboard/Pagination", () => ({ default: () => null }));
vi.mock("@/Utils/authorization", () => ({ useAuthorization: () => ({ can: () => true }) }));
vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    Link: ({ children }) => children,
    usePage: () => ({ props: pageProps }),
    useForm: () => ({ data: { selectedUser: [] }, setData: vi.fn(), delete: destroy, reset: vi.fn() }),
}));

const user = { id: 1, name: "Owner", email: "owner@example.com", roles: [{ name: "super-admin" }] };

describe("Users page", () => {
    beforeEach(() => {
        vi.clearAllMocks();
        pageProps = { users: { data: [user], links: [] }, flash: {} };
    });

    it("shows the server's flashed error", () => {
        pageProps.flash = { error: "Anda tidak dapat menghapus akun Anda sendiri." };

        render(<Index />);

        expect(toast.error).toHaveBeenCalledWith("Anda tidak dapat menghapus akun Anda sendiri.");
    });

    it("does not announce a successful delete before the server has answered", async () => {
        Swal.fire.mockResolvedValue({ isConfirmed: true });
        render(<Index />);

        await userEvent.click(screen.getAllByRole("button", { name: /hapus/i })[0]);

        await waitFor(() => expect(destroy).toHaveBeenCalled());
        expect(Swal.fire).toHaveBeenCalledTimes(1);
    });
});
