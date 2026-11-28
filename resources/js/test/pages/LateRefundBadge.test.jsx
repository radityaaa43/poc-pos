// Lives outside Pages/ because the Inertia page glob would bundle a test placed there.
import { afterEach, beforeAll, describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import i18n from "@/i18n";
import LateRefundBadge, { LateRefundNotice } from "@/Pages/Dashboard/Transactions/LateRefundBadge";

beforeAll(() => i18n.changeLanguage("id"));
afterEach(() => i18n.changeLanguage("id"));

describe("LateRefundBadge", () => {
    it("shows the manual-refund marker when a late payment was recorded", () => {
        render(<LateRefundBadge transaction={{ late_superseded_payment_at: "2026-11-25T10:00:00Z" }} />);

        expect(screen.getByText("Bayar ganda — refund manual")).toBeInTheDocument();
    });

    it("renders nothing when no late payment was recorded", () => {
        const { container } = render(<LateRefundBadge transaction={{ late_superseded_payment_at: null }} />);

        expect(container).toBeEmptyDOMElement();
    });

    it("shows the receipt notice only when set", () => {
        const { rerender } = render(<LateRefundNotice transaction={{ late_superseded_payment_at: null }} />);
        expect(screen.queryByRole("status")).not.toBeInTheDocument();

        rerender(<LateRefundNotice transaction={{ late_superseded_payment_at: "2026-11-25T10:00:00Z" }} />);
        expect(screen.getByRole("status")).toHaveTextContent(
            "Pembayaran gateway diterima tapi tidak dibukukan — perlu refund manual"
        );
        expect(screen.getByRole("status")).not.toHaveTextContent("Kelebihan bayar");
    });

    it("adds the excess to refund when a payment exceeded what a returned bill owed", () => {
        render(
            <LateRefundNotice
                transaction={{ late_superseded_payment_at: "2026-11-28T10:00:00Z", overpaid_amount: 5000 }}
            />
        );

        expect(screen.getByRole("status")).toHaveTextContent(/Kelebihan bayar Rp\s*5\.000 setelah retur — refund manual\./);
        // That payment was booked: saying otherwise could make a cashier refund all of it.
        expect(screen.getByRole("status")).toHaveTextContent("Perlu refund manual — cek riwayat pembayaran");
        expect(screen.getByRole("status")).not.toHaveTextContent("tidak dibukukan");
    });

    const overpaid = { late_superseded_payment_at: "2026-11-28T10:00:00Z", overpaid_amount: 10000 };

    // BR-5: the cash part of the excess has its own instruction, which depends on the sale's shift.
    it.each([
        ["id", true, "Kembalikan Rp 5.000 tunai dari laci — jangan catat kas keluar."],
        ["id", false, "Shift sudah ditutup — kembalikan Rp 5.000 dan catat sebagai kas keluar di shift berjalan."],
        ["en", true, "Return Rp 5,000 in cash from the drawer — don't record a cash out."],
        ["en", false, "The shift is closed — return Rp 5,000 and record it as a cash out in the current shift."],
    ])("tells the cashier what to do with the cash part (%s, shift open: %s)", async (language, shiftOpen, line) => {
        await i18n.changeLanguage(language);
        render(<LateRefundNotice transaction={{ ...overpaid, cash_excess: 5000, shift_open: shiftOpen }} />);

        expect(screen.getByText(line)).toBeInTheDocument();
    });

    it("says nothing about cash when the excess holds none", () => {
        render(<LateRefundNotice transaction={{ ...overpaid, cash_excess: 0, shift_open: false }} />);

        expect(screen.getByRole("status")).not.toHaveTextContent(/laci|kas keluar/);
    });

    it("formats the excess in the active language", async () => {
        await i18n.changeLanguage("en");
        render(<LateRefundNotice transaction={overpaid} />);

        expect(screen.getByText("Overpaid by Rp 10,000 after a return — refund manually.")).toBeInTheDocument();
    });
});
