import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import axios from "axios";
import toast from "react-hot-toast";
import i18n from "@/i18n";
import { requestPrinter, disconnectPrinter, printerKey, printBytes, buildKitchenTicketBytes } from "@/Utils/escpos";
import { useKitchenPrintStation } from "@/Hooks/useKitchenPrintStation";
import PrintStationPanel from "./PrintStationPanel";

vi.mock("axios", () => ({ default: { get: vi.fn(), post: vi.fn() } }));
vi.mock("react-hot-toast", () => ({ default: { success: vi.fn(), error: vi.fn() } }));
vi.mock("@/Utils/escpos", () => ({
    requestPrinter: vi.fn(),
    disconnectPrinter: vi.fn(),
    printerKey: vi.fn(),
    printBytes: vi.fn(),
    buildKitchenTicketBytes: vi.fn(() => new Uint8Array([1])),
}));
vi.mock("@/Utils/printQueue", () => ({
    createPrintQueue: () => ({ enqueue: vi.fn(), drain: vi.fn(), jobs: [], status: "idle" }),
}));

// The page owns the hook and hands it to the panel; this wrapper does the same with the real hook.
function Harness() {
    const printStation = useKitchenPrintStation({ id: 1 });
    return <PrintStationPanel printStation={printStation} />;
}

const saved = () => JSON.parse(localStorage.getItem("kitchen.station"));
const withStation = () => localStorage.setItem("kitchen.station", JSON.stringify({ id: 5, name: "Dapur 1" }));

beforeEach(async () => {
    vi.clearAllMocks();
    localStorage.clear();
    await i18n.changeLanguage("id");
    axios.get.mockResolvedValue({ data: { tickets: [] } });
    requestPrinter.mockResolvedValue({ vendorId: 1 });
    printerKey.mockReturnValue("1:2:abc");
});

describe("PrintStationPanel", () => {
    it("starts with only the name form usable", () => {
        render(<Harness />);

        expect(screen.getByLabelText("Nama perangkat cetak")).toBeEnabled();
        expect(screen.getByRole("button", { name: "Hubungkan printer" })).toBeDisabled();
        expect(screen.getByRole("switch", { name: "Cetak otomatis" })).toBeDisabled();
        expect(screen.getByRole("status")).toHaveTextContent("Printer belum terhubung");
    });

    it("saves the station name, shows it and keeps it on the device", async () => {
        const user = userEvent.setup();
        axios.post.mockResolvedValue({ data: { id: 5, name: "Dapur 1" } });
        render(<Harness />);

        await user.type(screen.getByLabelText("Nama perangkat cetak"), "  Dapur 1 ");
        await user.click(screen.getByRole("button", { name: "Simpan" }));

        expect(axios.post).toHaveBeenCalledWith("/kitchen.stations.store", { name: "Dapur 1" });
        await waitFor(() => expect(saved()).toEqual({ id: 5, name: "Dapur 1" }));
        expect(screen.getByRole("button", { name: "Hubungkan printer" })).toBeEnabled();
        expect(screen.getByRole("switch", { name: "Cetak otomatis" })).toBeEnabled();
    });

    it("shows the taken-name message under the field on a 422 and saves nothing", async () => {
        const user = userEvent.setup();
        axios.post.mockRejectedValue({ response: { status: 422, data: { errors: { name: ["The name has already been taken."] } } } });
        render(<Harness />);

        await user.type(screen.getByLabelText("Nama perangkat cetak"), "Dapur 1");
        await user.click(screen.getByRole("button", { name: "Simpan" }));

        const field = screen.getByLabelText("Nama perangkat cetak");
        expect(await screen.findByText("Nama perangkat cetak sudah dipakai di outlet ini")).toBeInTheDocument();
        expect(field).toHaveAccessibleDescription("Nama perangkat cetak sudah dipakai di outlet ini");
        expect(field).toBeInvalid();
        expect(screen.getByRole("alert")).toHaveTextContent("Nama perangkat cetak sudah dipakai di outlet ini");
        expect(localStorage.getItem("kitchen.station")).toBeNull();
    });

    it("says another tab prints when this tab does not hold the print lock", async () => {
        withStation();
        localStorage.setItem("kitchen.autoPrint", "true");
        Object.defineProperty(navigator, "locks", { value: { request: vi.fn(() => new Promise(() => {})) }, configurable: true });

        render(<Harness />);

        expect(await screen.findByText("Tiket dicetak dari tab lain di perangkat ini")).toBeInTheDocument();
        delete navigator.locks;
    });

    it("does not offer to save an empty name", async () => {
        const user = userEvent.setup();
        render(<Harness />);

        await user.type(screen.getByLabelText("Nama perangkat cetak"), "   ");
        expect(screen.getByRole("button", { name: "Simpan" })).toBeDisabled();
    });

    it("connects the printer and stores its key", async () => {
        const user = userEvent.setup();
        withStation();
        render(<Harness />);

        await user.click(screen.getByRole("button", { name: "Hubungkan printer" }));

        expect(requestPrinter).toHaveBeenCalled();
        expect(localStorage.getItem("kitchen.printerKey")).toBe("1:2:abc");
        expect(await screen.findByRole("button", { name: "Putuskan" })).toBeInTheDocument();
    });

    it("shows the printer error and changes nothing when the device dialog is closed", async () => {
        const user = userEvent.setup();
        withStation();
        requestPrinter.mockRejectedValue(new Error("No device selected."));
        render(<Harness />);

        await user.click(screen.getByRole("button", { name: "Hubungkan printer" }));

        await waitFor(() => expect(toast.error).toHaveBeenCalledWith("No device selected."));
        expect(localStorage.getItem("kitchen.printerKey")).toBeNull();
        expect(screen.getByRole("button", { name: "Hubungkan printer" })).toBeEnabled();
    });

    it("disconnects the printer and forgets its key", async () => {
        const user = userEvent.setup();
        withStation();
        localStorage.setItem("kitchen.printerKey", "1:2:abc");
        render(<Harness />);

        await user.click(screen.getByRole("button", { name: "Putuskan" }));

        expect(disconnectPrinter).toHaveBeenCalled();
        expect(localStorage.getItem("kitchen.printerKey")).toBeNull();
        expect(await screen.findByRole("button", { name: "Hubungkan printer" })).toBeInTheDocument();
    });

    it("keeps the paper size on the device", async () => {
        const user = userEvent.setup();
        render(<Harness />);

        expect(screen.getByLabelText("Ukuran kertas (58 mm / 80 mm)")).toHaveValue("80mm");
        await user.selectOptions(screen.getByLabelText("Ukuran kertas (58 mm / 80 mm)"), "58mm");

        expect(localStorage.getItem("kitchen.paper")).toBe("58mm");
    });

    it("test print needs a connected printer and prints a TEST ticket in the chosen width", async () => {
        const user = userEvent.setup();
        withStation();
        localStorage.setItem("kitchen.paper", "58mm");
        const { unmount } = render(<Harness />);
        expect(screen.getByRole("button", { name: "Cetak percobaan" })).toBeDisabled();
        unmount();

        localStorage.setItem("kitchen.printerKey", "1:2:abc");
        render(<Harness />);
        await user.click(screen.getByRole("button", { name: "Cetak percobaan" }));

        expect(buildKitchenTicketBytes).toHaveBeenCalledWith(expect.objectContaining({ number: "TEST" }), "58mm", expect.any(Object));
        expect(printBytes).toHaveBeenCalledWith(expect.any(Uint8Array), { deviceKey: "1:2:abc" });
    });

    it("shows the printer error when the test print fails", async () => {
        const user = userEvent.setup();
        withStation();
        localStorage.setItem("kitchen.printerKey", "1:2:abc");
        printBytes.mockRejectedValue(new Error("Printer belum terhubung."));
        render(<Harness />);

        await user.click(screen.getByRole("button", { name: "Cetak percobaan" }));

        await waitFor(() => expect(toast.error).toHaveBeenCalledWith("Printer belum terhubung."));
    });

    it("switches auto-print on and off, and reports the printer as ready only when both hold", async () => {
        const user = userEvent.setup();
        withStation();
        localStorage.setItem("kitchen.printerKey", "1:2:abc");
        render(<Harness />);
        const auto = screen.getByRole("switch", { name: "Cetak otomatis" });

        expect(auto).toHaveAttribute("aria-checked", "false");
        expect(screen.getByRole("status")).toHaveTextContent("Printer belum terhubung");

        await user.click(auto);
        expect(auto).toHaveAttribute("aria-checked", "true");
        expect(localStorage.getItem("kitchen.autoPrint")).toBe("true");
        expect(screen.getByRole("status")).toHaveTextContent("Printer siap");

        await user.click(auto);
        expect(localStorage.getItem("kitchen.autoPrint")).toBe("false");
        expect(screen.getByRole("status")).toHaveTextContent("Printer belum terhubung");
    });

    it("restores every setting from localStorage", () => {
        withStation();
        localStorage.setItem("kitchen.printerKey", "1:2:abc");
        localStorage.setItem("kitchen.paper", "58mm");
        localStorage.setItem("kitchen.autoPrint", "true");
        render(<Harness />);

        expect(screen.getByLabelText("Nama perangkat cetak")).toHaveValue("Dapur 1");
        expect(screen.getByRole("button", { name: "Putuskan" })).toBeInTheDocument();
        expect(screen.getByLabelText("Ukuran kertas (58 mm / 80 mm)")).toHaveValue("58mm");
        expect(screen.getByRole("switch", { name: "Cetak otomatis" })).toHaveAttribute("aria-checked", "true");
        expect(screen.getByRole("status")).toHaveTextContent("Printer siap");
    });

    it("uses the English wording", async () => {
        await i18n.changeLanguage("en");
        render(<Harness />);

        expect(screen.getByLabelText("Print device name")).toHaveAttribute("placeholder", "Kitchen 1");
        expect(screen.getByRole("button", { name: "Save" })).toBeInTheDocument();
        expect(screen.getByRole("button", { name: "Connect printer" })).toBeInTheDocument();
        expect(screen.getByLabelText("Paper size (58 mm / 80 mm)")).toBeInTheDocument();
        expect(screen.getByRole("button", { name: "Test print" })).toBeInTheDocument();
        expect(screen.getByRole("switch", { name: "Auto-print" })).toBeInTheDocument();
        expect(screen.getByRole("status")).toHaveTextContent("Printer not connected");
    });

    it("keeps every button at least 44 px high", () => {
        withStation();
        render(<Harness />);

        [...screen.getAllByRole("button"), screen.getByRole("switch")].forEach((el) => expect(el.className).toMatch(/min-h-\[44px\]/));
    });
});
