import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, act } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import toast from "react-hot-toast";
import { router } from "@inertiajs/react";
import HeldTransactions, { HoldButton } from "./HeldTransactions";

vi.mock("@inertiajs/react", () => ({
    router: { post: vi.fn(), delete: vi.fn() },
}));
vi.mock("react-hot-toast", () => ({
    default: { success: vi.fn(), error: vi.fn() },
}));

const heldCarts = [
    { hold_id: "h1", label: "Meja 3", items_count: 2, total: 30000 },
    { hold_id: "h2", label: "Take away", items_count: 1, total: 15000 },
];

beforeEach(() => {
    vi.clearAllMocks();
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe("HeldTransactions", () => {
    async function expand(props = {}) {
        const user = userEvent.setup();
        const utils = render(
            <HeldTransactions heldCarts={heldCarts} {...props} />
        );
        await user.click(screen.getByRole("button"));
        return { user, ...utils };
    }

    // The delete button is icon-only and has no title or label.
    const deleteButton = (index) =>
        document.querySelectorAll(".tabler-icon-trash")[index].closest("button");

    it("renders nothing without held carts", () => {
        const { container, rerender } = render(<HeldTransactions />);
        expect(container).toBeEmptyDOMElement();

        rerender(<HeldTransactions heldCarts={[]} />);
        expect(container).toBeEmptyDOMElement();
    });

    it("collapsed badge shows count and total", () => {
        render(<HeldTransactions heldCarts={heldCarts} />);

        expect(screen.getByText("2")).toBeInTheDocument();
        expect(screen.getByText("Transaksi Ditahan")).toBeInTheDocument();
        expect(screen.getByText("• Rp 45.000")).toBeInTheDocument();
        expect(screen.queryByText("Meja 3")).not.toBeInTheDocument();
    });

    it("expanded rows", async () => {
        await expand();

        expect(screen.getByText("Meja 3")).toBeInTheDocument();
        expect(screen.getByText("2 item • Rp 30.000")).toBeInTheDocument();
        expect(screen.getByText("Take away")).toBeInTheDocument();
        expect(screen.getByText("1 item • Rp 15.000")).toBeInTheDocument();
    });

    describe("resume", () => {
        it("posts the resume route and reports success", async () => {
            const routeSpy = vi.spyOn(globalThis, "route");
            const { user } = await expand();

            await user.click(screen.getAllByTitle("Lanjutkan")[0]);

            expect(routeSpy).toHaveBeenCalledWith("transactions.resume", "h1");
            expect(router.post).toHaveBeenCalledWith(
                "/transactions.resume",
                {},
                {
                    preserveScroll: true,
                    onSuccess: expect.any(Function),
                    onError: expect.any(Function),
                }
            );

            act(() => router.post.mock.calls[0][2].onSuccess());

            expect(toast.success).toHaveBeenCalledWith("Transaksi dilanjutkan");
            // Success collapses the panel back to the badge.
            expect(screen.queryByText("Meja 3")).not.toBeInTheDocument();
            expect(screen.getByText("Transaksi Ditahan")).toBeInTheDocument();
        });

        it("shows the server message on error", async () => {
            const { user } = await expand();

            await user.click(screen.getAllByTitle("Lanjutkan")[0]);
            act(() =>
                router.post.mock.calls[0][2].onError({ message: "Shift tutup" })
            );

            expect(toast.error).toHaveBeenCalledWith("Shift tutup");
            // The row stays so the cashier can retry.
            expect(screen.getByText("Meja 3")).toBeInTheDocument();
            expect(screen.getAllByTitle("Lanjutkan")[0]).toBeEnabled();
        });

        it("falls back to a generic error message", async () => {
            const { user } = await expand();

            await user.click(screen.getAllByTitle("Lanjutkan")[0]);
            act(() => router.post.mock.calls[0][2].onError({}));

            expect(toast.error).toHaveBeenCalledWith(
                "Gagal melanjutkan transaksi"
            );
        });

        it("is disabled while a cart is active", async () => {
            const { user } = await expand({ hasActiveCart: true });

            const buttons = screen.getAllByTitle("Kosongkan keranjang dulu");
            expect(buttons).toHaveLength(2);
            for (const button of buttons) expect(button).toBeDisabled();
            expect(screen.queryByTitle("Lanjutkan")).not.toBeInTheDocument();

            await user.click(buttons[0]);

            expect(router.post).not.toHaveBeenCalled();
        });
    });

    describe("delete", () => {
        it("does nothing when the confirm is declined", async () => {
            const confirmSpy = vi
                .spyOn(window, "confirm")
                .mockReturnValue(false);
            const { user } = await expand();

            await user.click(deleteButton(0));

            expect(confirmSpy).toHaveBeenCalledWith(
                "Hapus transaksi yang ditahan ini?"
            );
            expect(router.delete).not.toHaveBeenCalled();
        });

        it("deletes after confirming and reports success", async () => {
            vi.spyOn(window, "confirm").mockReturnValue(true);
            const routeSpy = vi.spyOn(globalThis, "route");
            const { user } = await expand();

            await user.click(deleteButton(0));

            expect(routeSpy).toHaveBeenCalledWith(
                "transactions.clearHold",
                "h1"
            );
            expect(router.delete).toHaveBeenCalledWith(
                "/transactions.clearHold",
                {
                    preserveScroll: true,
                    onSuccess: expect.any(Function),
                    onError: expect.any(Function),
                }
            );
            // The row's button is locked until the request settles.
            expect(deleteButton(0)).toBeDisabled();

            act(() => router.delete.mock.calls[0][1].onSuccess());

            expect(toast.success).toHaveBeenCalledWith("Transaksi dihapus");
            expect(deleteButton(0)).toBeEnabled();
        });

        it("reports an error when deleting fails", async () => {
            vi.spyOn(window, "confirm").mockReturnValue(true);
            const { user } = await expand();

            await user.click(deleteButton(0));
            act(() => router.delete.mock.calls[0][1].onError({}));

            expect(toast.error).toHaveBeenCalledWith(
                "Gagal menghapus transaksi"
            );
            expect(deleteButton(0)).toBeEnabled();
        });
    });
});

describe("HoldButton", () => {
    it("is hidden without items", () => {
        const { container } = render(<HoldButton onHold={vi.fn()} />);

        expect(container).toBeEmptyDOMElement();
    });

    it("opens a label input from the Tahan button", async () => {
        const user = userEvent.setup();
        render(<HoldButton hasItems onHold={vi.fn()} />);

        await user.click(screen.getByRole("button", { name: "Tahan" }));

        expect(
            screen.getByPlaceholderText("Label (opsional)")
        ).toBeInTheDocument();
    });

    it("holds with the typed label on Enter", async () => {
        const onHold = vi.fn();
        const user = userEvent.setup();
        render(<HoldButton hasItems onHold={onHold} />);

        await user.click(screen.getByRole("button", { name: "Tahan" }));
        await user.type(
            screen.getByPlaceholderText("Label (opsional)"),
            "Meja 5{Enter}"
        );

        expect(onHold).toHaveBeenCalledTimes(1);
        expect(onHold).toHaveBeenCalledWith("Meja 5");
        // The input closes after holding.
        expect(
            screen.getByRole("button", { name: "Tahan" })
        ).toBeInTheDocument();
    });

    it("holds with null when the label is empty", async () => {
        const onHold = vi.fn();
        const user = userEvent.setup();
        render(<HoldButton hasItems onHold={onHold} />);

        await user.click(screen.getByRole("button", { name: "Tahan" }));
        await user.click(screen.getByRole("button", { name: "OK" }));

        expect(onHold).toHaveBeenCalledWith(null);
    });

    it("closes on Escape without holding", async () => {
        const onHold = vi.fn();
        const user = userEvent.setup();
        render(<HoldButton hasItems onHold={onHold} />);

        await user.click(screen.getByRole("button", { name: "Tahan" }));
        await user.type(
            screen.getByPlaceholderText("Label (opsional)"),
            "Meja 5{Escape}"
        );

        expect(onHold).not.toHaveBeenCalled();
        expect(
            screen.queryByPlaceholderText("Label (opsional)")
        ).not.toBeInTheDocument();
    });

    it("disables OK while holding", async () => {
        const user = userEvent.setup();
        render(<HoldButton hasItems isHolding onHold={vi.fn()} />);

        await user.click(screen.getByRole("button", { name: "Tahan" }));

        expect(screen.getByRole("button", { name: "..." })).toBeDisabled();
    });
});
