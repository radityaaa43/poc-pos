import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { renderHook, act, fireEvent } from "@testing-library/react";
import useBarcodeScanner from "./useBarcodeScanner";

// Keydown on document.body bubbles to window, where the hook listens.
const press = (key) => act(() => void fireEvent.keyDown(document.body, { key }));
const wait = (ms) => act(() => void vi.advanceTimersByTime(ms));

// Types the keys 10 ms apart (a scanner's speed).
const type = (...keys) => {
    keys.forEach((key) => {
        press(key);
        wait(10);
    });
};

describe("useBarcodeScanner", () => {
    let onScan;

    beforeEach(() => {
        vi.useFakeTimers();
        onScan = vi.fn();
    });

    afterEach(() => {
        vi.useRealTimers();
        document.body.innerHTML = "";
    });

    it("fast keys then Enter scan", () => {
        const { result } = renderHook(() => useBarcodeScanner(onScan));

        type("1", "2", "3");
        press("Enter");

        expect(onScan).toHaveBeenCalledTimes(1);
        expect(onScan).toHaveBeenCalledWith("123");
        expect(result.current.lastBarcode).toBe("123");
        expect(result.current.isScanning).toBe(false);
    });

    it("too short is ignored", () => {
        const { result } = renderHook(() => useBarcodeScanner(onScan));

        type("1", "2");
        press("Enter");

        expect(onScan).not.toHaveBeenCalled();
        expect(result.current.lastBarcode).toBe("");
    });

    it("a slow gap restarts the buffer", () => {
        renderHook(() => useBarcodeScanner(onScan));

        press("1");
        wait(60);
        type("2", "3", "4");
        press("Enter");

        expect(onScan).toHaveBeenCalledWith("234");
    });

    it("buffer expires after 150 ms", () => {
        const { result } = renderHook(() => useBarcodeScanner(onScan));

        press("1");
        press("2");
        press("3");
        expect(result.current.isScanning).toBe(true);
        wait(151);
        expect(result.current.isScanning).toBe(false);
        press("Enter");

        expect(onScan).not.toHaveBeenCalled();
    });

    it.each([
        ["text input", () => Object.assign(document.createElement("input"), { type: "text" })],
        ["textarea", () => document.createElement("textarea")],
    ])("a focused %s is ignored", (_, create) => {
        renderHook(() => useBarcodeScanner(onScan));
        const field = create();
        document.body.appendChild(field);
        field.focus();

        type("1", "2", "3");
        press("Enter");

        expect(onScan).not.toHaveBeenCalled();
    });

    it("a focused checkbox is not ignored", () => {
        renderHook(() => useBarcodeScanner(onScan));
        const box = Object.assign(document.createElement("input"), { type: "checkbox" });
        document.body.appendChild(box);
        box.focus();

        type("1", "2", "3");
        press("Enter");

        expect(onScan).toHaveBeenCalledWith("123");
    });

    it("does nothing when disabled", () => {
        const { result } = renderHook(() => useBarcodeScanner(onScan, { enabled: false }));

        type("1", "2", "3");
        press("Enter");

        expect(onScan).not.toHaveBeenCalled();
        expect(result.current.isScanning).toBe(false);
    });

    it("only accepts barcode characters", () => {
        renderHook(() => useBarcodeScanner(onScan));

        type("A", "-", "_", ".", " ", "#");
        press("Enter");

        expect(onScan).toHaveBeenCalledWith("A-_.");
    });

    it("reset() clears the scanning state and keeps lastBarcode", () => {
        const { result } = renderHook(() => useBarcodeScanner(onScan));

        type("1", "2", "3");
        press("Enter");
        type("4", "5");
        expect(result.current.isScanning).toBe(true);

        act(() => result.current.reset());

        expect(result.current.isScanning).toBe(false);
        expect(result.current.lastBarcode).toBe("123");

        // The buffer was cleared too: "6" alone is too short to scan.
        onScan.mockClear();
        press("6");
        press("Enter");
        expect(onScan).not.toHaveBeenCalled();
    });

    it("stops listening after unmount", () => {
        const { unmount } = renderHook(() => useBarcodeScanner(onScan));

        unmount();
        type("1", "2", "3");
        press("Enter");

        expect(onScan).not.toHaveBeenCalled();
    });
});
