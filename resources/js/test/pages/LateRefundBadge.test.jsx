// Lives outside Pages/ because the Inertia page glob would bundle a test placed there.
import { beforeAll, describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import i18n from "@/i18n";
import LateRefundBadge, { LateRefundNotice } from "@/Pages/Dashboard/Transactions/LateRefundBadge";

beforeAll(() => i18n.changeLanguage("id"));

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
    });
});
