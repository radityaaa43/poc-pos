import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { act, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { router } from "@inertiajs/react";
import i18n from "@/i18n";
import useCreditException from "./CreditExceptionDialog";

vi.mock("@inertiajs/react", () => ({ router: { post: vi.fn() } }));
vi.mock("@/Components/Dashboard/Modal", () => ({
    default: ({ children, title, show }) =>
        show ? (
            <div role="dialog">
                <h2>{title}</h2>
                {children}
            </div>
        ) : null,
}));

const refusal = "Melebihi batas piutang — sisa Rp 200.000";
const payload = { customer_id: 5, pay_later: true };
const onPayNow = vi.fn();
const options = { onSuccess: vi.fn(), onError: vi.fn() };

function Harness() {
    const { submit, dialog } = useCreditException(onPayNow);

    return (
        <>
            <button onClick={() => submit(payload, options)}>Simpan</button>
            {dialog}
        </>
    );
}

const lastPost = () => router.post.mock.calls.at(-1);
const answer = (callback, argument) => act(() => lastPost()[2][callback](argument));
const refused = async () => {
    const user = userEvent.setup();
    render(<Harness />);
    await user.click(screen.getByRole("button", { name: "Simpan" }));
    answer("onError", { credit: refusal });

    return user;
};

beforeAll(() => i18n.changeLanguage("id"));
beforeEach(() => vi.clearAllMocks());

describe("credit exception dialog", () => {
    it("posts the sale and opens only for a credit refusal", async () => {
        const user = userEvent.setup();
        render(<Harness />);
        await user.click(screen.getByRole("button", { name: "Simpan" }));

        expect(lastPost().slice(0, 2)).toEqual(["/transactions.store", payload]);
        answer("onError", { note: "Catatan wajib" });
        expect(options.onError).toHaveBeenCalledWith({ note: "Catatan wajib" });
        expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    });

    it("says why in the server's words and offers three ways on", async () => {
        await refused();

        expect(screen.getByRole("heading", { name: refusal })).toBeInTheDocument();
        expect(options.onError).toHaveBeenCalledWith({ credit: refusal });
        expect(screen.getAllByRole("button").map((button) => button.textContent)).toEqual([
            "Simpan",
            "Bayar sekarang",
            "Minta pengecualian",
            "Batal",
        ]);
    });

    it("pay now closes and switches pay later off; cancel only closes", async () => {
        const user = await refused();
        await user.click(screen.getByRole("button", { name: "Bayar sekarang" }));
        expect(onPayNow).toHaveBeenCalledTimes(1);
        expect(screen.queryByRole("dialog")).not.toBeInTheDocument();

        await user.click(screen.getByRole("button", { name: "Simpan" }));
        answer("onError", { credit: refusal });
        await user.click(screen.getByRole("button", { name: "Batal" }));
        expect(onPayNow).toHaveBeenCalledTimes(1);
        expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    });

    it("asks for an exception with the same sale, once, and finishes the form when it is held", async () => {
        const user = await refused();
        await user.click(screen.getByRole("button", { name: "Minta pengecualian" }));

        expect(lastPost()[1]).toEqual({ ...payload, credit_exception: true });
        for (const button of screen.getAllByRole("button").slice(1)) expect(button).toBeDisabled();

        const page = { props: { flash: { success: "Menunggu persetujuan kredit" } } };
        answer("onSuccess", page);
        answer("onFinish");
        expect(options.onSuccess).toHaveBeenCalledWith(page);
        expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    });

    it.each([
        ["onSuccess", { props: { flash: { error: "Shift kasir belum dibuka." } } }, "Shift kasir belum dibuka."],
        ["onError", { credit: "Keranjang sudah tidak ada." }, "Keranjang sudah tidak ada."],
    ])("stays open and says why when the request is not held (%s)", async (callback, argument, message) => {
        const user = await refused();
        await user.click(screen.getByRole("button", { name: "Minta pengecualian" }));
        answer(callback, argument);
        answer("onFinish");

        expect(screen.getByRole("alert")).toHaveTextContent(message);
        expect(options.onSuccess).not.toHaveBeenCalled();
        expect(screen.getByRole("button", { name: "Minta pengecualian" })).toBeEnabled();
    });
});
