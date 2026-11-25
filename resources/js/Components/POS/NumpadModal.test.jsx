import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import NumpadModal from "./NumpadModal";

function setup(props = {}) {
    const onClose = vi.fn();
    const onConfirm = vi.fn();
    const user = userEvent.setup();
    const utils = render(
        <NumpadModal
            isOpen
            onClose={onClose}
            onConfirm={onConfirm}
            {...props}
        />
    );
    // The display is the only <p> in the modal. "0" also labels a digit
    // button, so it cannot be found by text.
    const display = () => utils.container.querySelector("p");
    const click = async (...labels) => {
        for (const label of labels) {
            await user.click(screen.getByRole("button", { name: label }));
        }
    };
    const backspace = () => screen.getByRole("button", { name: "Hapus angka" });
    return { ...utils, user, onClose, onConfirm, display, click, backspace };
}

describe("NumpadModal", () => {
    it("renders nothing when closed", () => {
        const { container } = setup({ isOpen: false });

        expect(container).toBeEmptyDOMElement();
    });

    it("typing digits builds the value", async () => {
        const currency = setup({ isCurrency: true });
        await currency.click("1", "5", "0", "0", "0");
        expect(screen.getByText("Rp 15.000")).toBeInTheDocument();
        currency.unmount();

        const plain = setup();
        await plain.click("1", "5", "0", "0", "0");
        expect(plain.display()).toHaveTextContent(/^15\.000$/);
    });

    it("a leading zero is replaced", async () => {
        const { click, display } = setup();

        await click("0", "7");

        expect(display()).toHaveTextContent(/^7$/);
    });

    it("backspace and clear", async () => {
        const { click, backspace, display, user } = setup({
            initialValue: 15,
        });
        expect(display()).toHaveTextContent(/^15$/);

        await user.click(backspace());
        expect(display()).toHaveTextContent(/^1$/);

        await user.click(backspace());
        expect(display()).toHaveTextContent(/^0$/);

        await click("9", "9");
        expect(display()).toHaveTextContent(/^99$/);

        await click("C");
        expect(display()).toHaveTextContent(/^0$/);
    });

    it("the Tutup button closes without confirming", async () => {
        const { user, onClose, onConfirm } = setup();

        await user.click(screen.getByRole("button", { name: "Tutup" }));

        expect(onClose).toHaveBeenCalledTimes(1);
        expect(onConfirm).not.toHaveBeenCalled();
    });

    it("a digit beyond maxValue is ignored", async () => {
        const { click, display } = setup({ maxValue: 100 });

        await click("1", "0", "0", "5");

        expect(display()).toHaveTextContent(/^100$/);
    });

    describe("quick amounts", () => {
        it("add to the value", async () => {
            const { click } = setup({ isCurrency: true });

            await click("+10rb", "+50rb");

            expect(screen.getByText("Rp 60.000")).toBeInTheDocument();
        });

        it("are ignored when they would pass maxValue", async () => {
            const { click, display } = setup({
                isCurrency: true,
                maxValue: 50000,
            });

            await click("+100rb");

            expect(display()).toHaveTextContent(/^Rp 0$/);
        });

        it("are not shown without isCurrency", () => {
            setup();

            expect(screen.queryByText("+10rb")).not.toBeInTheDocument();
            expect(screen.queryByText("+100rb")).not.toBeInTheDocument();
        });
    });

    it("Konfirmasi sends a number then closes", async () => {
        const { click, onConfirm, onClose } = setup({ isCurrency: true });

        await click("1", "5", "0", "0", "0", "Konfirmasi");

        expect(onConfirm).toHaveBeenCalledTimes(1);
        expect(onConfirm).toHaveBeenCalledWith(15000);
        expect(onClose).toHaveBeenCalledTimes(1);
        expect(onConfirm.mock.invocationCallOrder[0]).toBeLessThan(
            onClose.mock.invocationCallOrder[0]
        );
    });

    it("Konfirmasi is disabled below minValue", async () => {
        const { click, onConfirm, onClose } = setup({ minValue: 1 });
        const confirm = screen.getByRole("button", { name: "Konfirmasi" });

        expect(confirm).toBeDisabled();

        await click("Konfirmasi");
        expect(onConfirm).not.toHaveBeenCalled();
        expect(onClose).not.toHaveBeenCalled();

        await click("3");
        expect(confirm).toBeEnabled();
    });

    describe("keyboard", () => {
        it("digits build the value", () => {
            const { display } = setup();

            fireEvent.keyDown(window, { key: "4" });
            fireEvent.keyDown(window, { key: "2" });

            expect(display()).toHaveTextContent(/^42$/);
        });

        it("Backspace removes the last digit", () => {
            const { display } = setup();

            fireEvent.keyDown(window, { key: "4" });
            fireEvent.keyDown(window, { key: "2" });
            fireEvent.keyDown(window, { key: "Backspace" });

            expect(display()).toHaveTextContent(/^4$/);
        });

        it("Enter confirms the value", () => {
            const { onConfirm, onClose } = setup();

            fireEvent.keyDown(window, { key: "4" });
            fireEvent.keyDown(window, { key: "2" });
            fireEvent.keyDown(window, { key: "Enter" });

            expect(onConfirm).toHaveBeenCalledTimes(1);
            expect(onConfirm).toHaveBeenCalledWith(42);
            expect(onClose).toHaveBeenCalledTimes(1);
        });

        it("Escape closes without confirming", () => {
            const { onConfirm, onClose } = setup();

            fireEvent.keyDown(window, { key: "Escape" });

            expect(onClose).toHaveBeenCalledTimes(1);
            expect(onConfirm).not.toHaveBeenCalled();
        });

        it("c clears the value", () => {
            const { display } = setup();

            fireEvent.keyDown(window, { key: "4" });
            fireEvent.keyDown(window, { key: "2" });
            fireEvent.keyDown(window, { key: "c" });

            expect(display()).toHaveTextContent(/^0$/);
        });

        it("is not listened to while closed", () => {
            const { onClose } = setup({ isOpen: false });

            fireEvent.keyDown(window, { key: "Escape" });

            expect(onClose).not.toHaveBeenCalled();
        });
    });

    it("resets when reopened", async () => {
        const onClose = vi.fn();
        const onConfirm = vi.fn();
        const props = { onClose, onConfirm, isCurrency: true };
        const { rerender, container } = render(
            <NumpadModal isOpen {...props} />
        );
        await userEvent.setup().click(screen.getByRole("button", { name: "9" }));
        expect(screen.getByText("Rp 9")).toBeInTheDocument();

        rerender(<NumpadModal isOpen={false} {...props} />);
        expect(container).toBeEmptyDOMElement();

        rerender(<NumpadModal isOpen initialValue={500} {...props} />);
        expect(screen.getByText("Rp 500")).toBeInTheDocument();
    });
});
