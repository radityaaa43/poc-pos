import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import axios from "axios";
import toast from "react-hot-toast";
import AddCustomerModal from "./AddCustomerModal";

vi.mock("axios");
vi.mock("react-hot-toast", () => ({
    default: { success: vi.fn(), error: vi.fn() },
}));

const tierOptions = [
    { value: "regular", label: "Regular" },
    { value: "gold", label: "Gold" },
];

const renderModal = (props = {}) => {
    const onClose = vi.fn();
    const onSuccess = vi.fn();
    render(
        <AddCustomerModal
            isOpen
            onClose={onClose}
            onSuccess={onSuccess}
            tierOptions={tierOptions}
            {...props}
        />
    );
    return { onClose, onSuccess };
};

const nameInput = () => screen.getByPlaceholderText("Masukkan nama lengkap");
const phoneInput = () => screen.getByPlaceholderText("Contoh: 08123456789");
const addressInput = () => screen.getByPlaceholderText("Masukkan alamat lengkap");

const fillForm = async (user) => {
    await user.type(nameInput(), "Citra");
    await user.type(phoneInput(), "0812");
    await user.type(addressInput(), "Jl. Mawar");
};

describe("AddCustomerModal", () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it("renders nothing when closed", () => {
        const { container } = render(
            <AddCustomerModal isOpen={false} onClose={vi.fn()} />
        );

        expect(container).toBeEmptyDOMElement();
    });

    it("blank fields show three messages and send nothing", async () => {
        const user = userEvent.setup();
        renderModal();

        await user.type(nameInput(), "   ");
        await user.type(phoneInput(), "   ");
        await user.type(addressInput(), "   ");
        await user.click(screen.getByRole("button", { name: "Simpan" }));

        expect(screen.getByText("Nama wajib diisi")).toBeInTheDocument();
        expect(screen.getByText("No. telepon wajib diisi")).toBeInTheDocument();
        expect(screen.getByText("Alamat wajib diisi")).toBeInTheDocument();
        expect(axios.post).not.toHaveBeenCalled();
    });

    it("typing clears that field's error", async () => {
        const user = userEvent.setup();
        renderModal();

        await user.click(screen.getByRole("button", { name: "Simpan" }));
        expect(screen.getByText("Nama wajib diisi")).toBeInTheDocument();

        await user.type(nameInput(), "C");

        expect(screen.queryByText("Nama wajib diisi")).not.toBeInTheDocument();
        expect(screen.getByText("No. telepon wajib diisi")).toBeInTheDocument();
        expect(screen.getByText("Alamat wajib diisi")).toBeInTheDocument();
    });

    it("member tier select appears only for members", async () => {
        const user = userEvent.setup();
        renderModal();

        expect(screen.queryByText("Tier Awal")).not.toBeInTheDocument();
        expect(screen.queryByRole("combobox")).not.toBeInTheDocument();

        await user.click(screen.getByLabelText("Member"));

        expect(screen.getByText("Tier Awal")).toBeInTheDocument();
        const select = screen.getByRole("combobox");
        expect(
            Array.from(select.options).map((o) => [o.value, o.textContent])
        ).toEqual([
            ["regular", "Regular"],
            ["gold", "Gold"],
        ]);
    });

    it("success posts the form, toasts, then calls onSuccess and onClose", async () => {
        const user = userEvent.setup();
        const customer = { id: 7, name: "Citra" };
        axios.post.mockResolvedValue({ data: { success: true, customer } });
        const { onClose, onSuccess } = renderModal();

        await fillForm(user);
        await user.click(screen.getByRole("button", { name: "Simpan" }));

        expect(axios.post).toHaveBeenCalledWith("/customers.storeAjax", {
            name: "Citra",
            no_telp: "0812",
            address: "Jl. Mawar",
            is_loyalty_member: false,
            loyalty_tier: "regular",
        });
        expect(toast.success).toHaveBeenCalledWith(
            "Pelanggan berhasil ditambahkan"
        );
        expect(onSuccess).toHaveBeenCalledWith(customer);
        expect(onClose).toHaveBeenCalledTimes(1);
        expect(onSuccess.mock.invocationCallOrder[0]).toBeLessThan(
            onClose.mock.invocationCallOrder[0]
        );
    });

    it("server says no: shows the message and the field error", async () => {
        const user = userEvent.setup();
        axios.post.mockResolvedValue({
            data: {
                success: false,
                message: "Telepon sudah dipakai",
                errors: { no_telp: "Telepon sudah dipakai" },
            },
        });
        const { onClose, onSuccess } = renderModal();

        await fillForm(user);
        await user.click(screen.getByRole("button", { name: "Simpan" }));

        expect(toast.error).toHaveBeenCalledWith("Telepon sudah dipakai");
        expect(screen.getByText("Telepon sudah dipakai")).toBeInTheDocument();
        expect(screen.getByRole("button", { name: "Simpan" })).toBeEnabled();
        expect(onSuccess).not.toHaveBeenCalled();
        expect(onClose).not.toHaveBeenCalled();
    });

    it("request rejected: shows the server message and re-enables Simpan", async () => {
        const user = userEvent.setup();
        const consoleError = vi
            .spyOn(console, "error")
            .mockImplementation(() => {});
        const failure = { response: { data: { message: "Validasi gagal" } } };
        axios.post.mockRejectedValue(failure);
        const { onClose, onSuccess } = renderModal();

        await fillForm(user);
        await user.click(screen.getByRole("button", { name: "Simpan" }));

        expect(toast.error).toHaveBeenCalledWith("Validasi gagal");
        expect(consoleError).toHaveBeenCalledWith(
            "Add customer error:",
            failure
        );
        expect(screen.getByRole("button", { name: "Simpan" })).toBeEnabled();
        expect(onSuccess).not.toHaveBeenCalled();
        expect(onClose).not.toHaveBeenCalled();
    });

    it("Batal resets the form and closes", async () => {
        const user = userEvent.setup();
        const { onClose } = renderModal();

        await user.type(nameInput(), "Citra");
        await user.click(screen.getByRole("button", { name: "Simpan" }));
        expect(screen.getByText("No. telepon wajib diisi")).toBeInTheDocument();

        await user.click(screen.getByRole("button", { name: "Batal" }));

        expect(onClose).toHaveBeenCalledTimes(1);
        expect(nameInput()).toHaveValue("");
        expect(
            screen.queryByText("No. telepon wajib diisi")
        ).not.toBeInTheDocument();
    });
});
