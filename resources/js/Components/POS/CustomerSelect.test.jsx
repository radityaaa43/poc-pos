import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor, fireEvent } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import axios from "axios";
import { router } from "@inertiajs/react";
import CustomerSelect from "./CustomerSelect";

vi.mock("axios", () => ({ default: { post: vi.fn() } }));
vi.mock("@inertiajs/react", () => ({ router: { reload: vi.fn() } }));
vi.mock("react-hot-toast", () => ({
    default: { success: vi.fn(), error: vi.fn() },
}));

const ani = {
    id: 1,
    name: "Ani",
    no_telp: "0811",
    member_code: "M-001",
    is_loyalty_member: true,
    loyalty_tier: "gold",
    loyalty_points: 120,
};
const budi = {
    id: 2,
    name: "Budi",
    no_telp: null,
    is_loyalty_member: false,
};

const SEARCH_PLACEHOLDER = "Cari nama/telepon/nomor anggota...";

const renderSelect = (props = {}) => {
    const onSelect = vi.fn();
    render(
        <CustomerSelect
            customers={[ani, budi]}
            onSelect={onSelect}
            {...props}
        />
    );
    return { onSelect };
};

describe("CustomerSelect", () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it("shows the placeholder, then the selected customer", () => {
        const { unmount } = render(
            <CustomerSelect customers={[ani, budi]} onSelect={vi.fn()} />
        );
        expect(screen.getByText("Pilih pelanggan...")).toBeInTheDocument();
        unmount();

        const { unmount: unmountAni } = render(
            <CustomerSelect
                customers={[ani, budi]}
                selected={ani}
                onSelect={vi.fn()}
            />
        );
        expect(screen.getByText("Ani")).toBeInTheDocument();
        expect(screen.getByText("0811")).toBeInTheDocument();
        expect(screen.getByText("M-001")).toBeInTheDocument();
        expect(screen.getByText("gold • 120 poin")).toBeInTheDocument();
        unmountAni();

        render(
            <CustomerSelect
                customers={[ani, budi]}
                selected={budi}
                onSelect={vi.fn()}
            />
        );
        expect(screen.getByText("Budi")).toBeInTheDocument();
        expect(screen.getByText("Non-member")).toBeInTheDocument();
    });

    it("filters by name, phone and member code", async () => {
        const user = userEvent.setup();
        renderSelect();

        await user.click(screen.getByText("Pilih pelanggan..."));
        const search = screen.getByPlaceholderText(SEARCH_PLACEHOLDER);
        expect(screen.getByText("Ani")).toBeInTheDocument();
        expect(screen.getByText("Budi")).toBeInTheDocument();

        await user.type(search, "m-001");
        expect(screen.getByText("Ani")).toBeInTheDocument();
        expect(screen.queryByText("Budi")).not.toBeInTheDocument();

        await user.clear(search);
        await user.type(search, "0811");
        expect(screen.getByText("Ani")).toBeInTheDocument();
        expect(screen.queryByText("Budi")).not.toBeInTheDocument();

        await user.clear(search);
        await user.type(search, "bud");
        expect(screen.getByText("Budi")).toBeInTheDocument();
        expect(screen.queryByText("Ani")).not.toBeInTheDocument();

        await user.clear(search);
        await user.type(search, "zzz");
        expect(
            screen.getByText("Pelanggan tidak ditemukan")
        ).toBeInTheDocument();
    });

    it("selecting a customer calls onSelect and closes", async () => {
        const user = userEvent.setup();
        const { onSelect } = renderSelect();

        await user.click(screen.getByText("Pilih pelanggan..."));
        await user.click(screen.getByText("Budi"));

        expect(onSelect).toHaveBeenCalledTimes(1);
        expect(onSelect).toHaveBeenCalledWith(budi);
        expect(
            screen.queryByPlaceholderText(SEARCH_PLACEHOLDER)
        ).not.toBeInTheDocument();
    });

    it("clicking outside closes the list", async () => {
        const user = userEvent.setup();
        renderSelect();

        await user.click(screen.getByText("Pilih pelanggan..."));
        expect(screen.getByPlaceholderText(SEARCH_PLACEHOLDER)).toBeInTheDocument();

        fireEvent.mouseDown(document.body);

        expect(
            screen.queryByPlaceholderText(SEARCH_PLACEHOLDER)
        ).not.toBeInTheDocument();
    });

    describe("upgrade member", () => {
        it("upgrades a non-member to the first tier option", async () => {
            const user = userEvent.setup();
            const routeSpy = vi.spyOn(globalThis, "route");
            const upgraded = { ...budi, is_loyalty_member: true };
            axios.post.mockResolvedValue({
                data: { success: true, customer: upgraded },
            });
            const { onSelect } = renderSelect({
                selected: budi,
                tierOptions: [{ value: "silver", label: "Silver" }],
            });

            await user.click(
                screen.getByRole("button", { name: "Upgrade member" })
            );

            await waitFor(() =>
                expect(onSelect).toHaveBeenCalledWith(upgraded)
            );
            expect(routeSpy).toHaveBeenCalledWith(
                "customers.upgrade-member",
                2
            );
            expect(axios.post).toHaveBeenCalledWith(
                "/customers.upgrade-member",
                { loyalty_tier: "silver" }
            );
            expect(router.reload).toHaveBeenCalledWith({ only: ["customers"] });
        });

        it("falls back to the regular tier when there are no tier options", async () => {
            const user = userEvent.setup();
            axios.post.mockResolvedValue({
                data: { success: true, customer: budi },
            });
            const { onSelect } = renderSelect({ selected: budi });

            await user.click(
                screen.getByRole("button", { name: "Upgrade member" })
            );

            await waitFor(() => expect(onSelect).toHaveBeenCalled());
            expect(axios.post).toHaveBeenCalledWith(expect.any(String), {
                loyalty_tier: "regular",
            });
        });

        it("has no upgrade button for a member", () => {
            renderSelect({ selected: ani });

            expect(
                screen.queryByRole("button", { name: "Upgrade member" })
            ).not.toBeInTheDocument();
        });
    });

    it("adding a customer selects it", async () => {
        const user = userEvent.setup();
        const created = {
            id: 3,
            name: "Citra",
            no_telp: "0822",
            is_loyalty_member: false,
        };
        axios.post.mockResolvedValue({
            data: { success: true, customer: created },
        });
        const onCustomerAdded = vi.fn();
        const { onSelect } = renderSelect({ onCustomerAdded });

        await user.click(
            screen.getByRole("button", { name: "Tambah pelanggan" })
        );
        await user.type(
            screen.getByPlaceholderText("Masukkan nama lengkap"),
            "Citra"
        );
        await user.type(
            screen.getByPlaceholderText("Contoh: 08123456789"),
            "0822"
        );
        await user.type(
            screen.getByPlaceholderText("Masukkan alamat lengkap"),
            "Jl. Mawar 1"
        );
        await user.click(screen.getByRole("button", { name: "Simpan" }));

        await waitFor(() => expect(onSelect).toHaveBeenCalledWith(created));
        expect(onCustomerAdded).toHaveBeenCalledWith(created);
        expect(router.reload).toHaveBeenCalledWith({ only: ["customers"] });
        expect(
            screen.queryByPlaceholderText("Masukkan nama lengkap")
        ).not.toBeInTheDocument();
    });
});
