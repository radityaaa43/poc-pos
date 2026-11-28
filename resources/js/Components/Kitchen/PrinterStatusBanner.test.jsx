import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import i18n from "@/i18n";
import PrinterStatusBanner, { DriverLockedHelp } from "./PrinterStatusBanner";

beforeEach(async () => {
    await i18n.changeLanguage("id");
});

const TEXT = {
    driver_locked: "Printer tidak bisa dipakai dari browser — ganti driver ke WinUSB",
    not_connected: "Printer dapur tidak terhubung",
    failed: "Gagal mencetak — coba lagi",
    stale: "Printer dapur offline atau tab tidur",
    wake_lock_off: "Layar bisa tidur — jaga perangkat tetap menyala",
};

describe("PrinterStatusBanner", () => {
    it.each([null, undefined, "unsupported"])("renders nothing for %s (unsupported is shown in the settings panel only)", (status) => {
        const { container } = render(<PrinterStatusBanner status={status} />);

        expect(container).toBeEmptyDOMElement();
    });

    it.each(Object.entries(TEXT))("shows the REQ wording for %s in one alert region", (status, text) => {
        render(<PrinterStatusBanner status={status} onRetry={() => {}} />);

        const alerts = screen.getAllByRole("alert");
        expect(alerts).toHaveLength(1);
        expect(alerts[0]).toHaveTextContent(text);
    });

    it("shows only the one banner it is given", () => {
        render(<PrinterStatusBanner status="stale" />);

        expect(screen.getByRole("alert")).not.toHaveTextContent(TEXT.failed);
        expect(screen.queryByRole("button")).not.toBeInTheDocument();
    });

    it("speaks English on an English device", async () => {
        await i18n.changeLanguage("en");
        const wording = {
            driver_locked: "Printer cannot be used from the browser — switch the driver to WinUSB",
            not_connected: "Kitchen printer is not connected",
            failed: "Print failed — try again",
            stale: "Kitchen printer offline or tab asleep",
            wake_lock_off: "The screen may sleep — keep the device awake",
        };
        for (const [status, text] of Object.entries(wording)) {
            const { unmount } = render(<PrinterStatusBanner status={status} />);
            expect(screen.getByRole("alert")).toHaveTextContent(text);
            unmount();
        }
    });

    it("offers Coba lagi on a failed print, at least 44 px tall, and calls onRetry", async () => {
        const user = userEvent.setup();
        const onRetry = vi.fn();
        render(<PrinterStatusBanner status="failed" onRetry={onRetry} />);

        const button = within(screen.getByRole("alert")).getByRole("button", { name: "Coba lagi" });
        expect(button).toHaveClass("min-h-[44px]");
        await user.click(button);

        expect(onRetry).toHaveBeenCalledTimes(1);
    });

    it("offers no Coba lagi for the other statuses", () => {
        for (const status of ["not_connected", "driver_locked", "stale", "wake_lock_off"]) {
            const { unmount } = render(<PrinterStatusBanner status={status} onRetry={() => {}} />);
            expect(screen.queryByRole("button", { name: "Coba lagi" })).not.toBeInTheDocument();
            unmount();
        }
    });

    it("hides the WinUSB steps until Lihat langkah is pressed, then lists the five steps inline", async () => {
        const user = userEvent.setup();
        render(<PrinterStatusBanner status="driver_locked" />);

        const toggle = within(screen.getByRole("alert")).getByRole("button", { name: "Lihat langkah" });
        expect(toggle).toHaveAttribute("aria-expanded", "false");
        expect(toggle).toHaveClass("min-h-[44px]");
        expect(screen.queryByRole("list")).not.toBeInTheDocument();

        await user.click(toggle);

        expect(toggle).toHaveAttribute("aria-expanded", "true");
        const steps = within(screen.getByRole("list")).getAllByRole("listitem");
        expect(steps).toHaveLength(5);
        expect(steps[0]).toHaveTextContent("Zadig");
        expect(steps[2]).toHaveTextContent("WinUSB");
        expect(steps[4]).toHaveTextContent("Muat ulang halaman ini.");
        expect(screen.getByRole("alert")).toContainElement(screen.getByRole("list"));

        await user.click(toggle);
        expect(screen.queryByRole("list")).not.toBeInTheDocument();
    });

    it("has no link: the steps are in the app, not on a page staff cannot open", async () => {
        const user = userEvent.setup();
        render(<PrinterStatusBanner status="driver_locked" />);
        await user.click(screen.getByRole("button", { name: "Lihat langkah" }));

        expect(screen.queryByRole("link")).not.toBeInTheDocument();
    });
});

describe("DriverLockedHelp", () => {
    it("shows the English steps and toggle label", async () => {
        await i18n.changeLanguage("en");
        const user = userEvent.setup();
        render(<DriverLockedHelp />);

        await user.click(screen.getByRole("button", { name: "Show steps" }));

        expect(screen.getAllByRole("listitem")).toHaveLength(5);
        expect(screen.getByText("Reload this page.")).toBeInTheDocument();
    });
});
