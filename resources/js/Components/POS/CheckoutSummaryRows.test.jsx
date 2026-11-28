import { beforeAll, describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import i18n from "@/i18n";
import CheckoutSummaryRows from "./CheckoutSummaryRows";

beforeAll(() => i18n.changeLanguage("id"));

const rows = (props = {}, bill = {}) => (
    <CheckoutSummaryRows
        voucher={0}
        loyalty={0}
        discount={0}
        shipping={0}
        bill={{ service_charge: 0, tax_total: 0, tax_kind: "ppn", ...bill }}
        format={(amount) => `Rp${amount}`}
        {...props}
    />
);
const value = (label) => screen.getByText(label).nextElementSibling;

describe("CheckoutSummaryRows", () => {
    it("shows nothing for a bill without discounts, shipping, service charge or tax", () => {
        const { container } = render(rows());

        expect(container).toBeEmptyDOMElement();
    });

    it("keeps today's rows", () => {
        render(rows({ voucher: 1, loyalty: 2, discount: 3, shipping: 4 }, { tax_total: 5 }));

        expect(value("Voucher")).toHaveTextContent("-Rp1");
        expect(value("Redeem Poin")).toHaveTextContent("-Rp2");
        expect(value("Diskon Manual")).toHaveTextContent("-Rp3");
        expect(value("Ongkir")).toHaveTextContent("+Rp4");
        expect(value("PPN")).toHaveTextContent("+Rp5");
        expect(screen.queryByText("Service charge")).not.toBeInTheDocument();
    });

    it("adds the service charge and names a PB1 bill, then drops the row with the order type", () => {
        const { rerender } = render(rows({}, { service_charge: 1000, tax_total: 2100, tax_kind: "pb1", tax_rate: 10 }));

        expect(value("Service charge")).toHaveTextContent("+Rp1000");
        expect(value("PB1 10%")).toHaveTextContent("+Rp2100");
        expect(screen.queryByText("PPN")).not.toBeInTheDocument();

        rerender(rows({}, { service_charge: 0, tax_total: 2000, tax_kind: "pb1", tax_rate: 10 }));
        expect(screen.queryByText("Service charge")).not.toBeInTheDocument();
    });

    it("shows an inclusive price's PPN on its own line, never added again (#576)", () => {
        render(rows({}, { tax_total: 16500, tax_included: 16500, tax_kind: "ppn", tax_rate: 11 }));

        expect(value("Termasuk PPN 11%")).toHaveTextContent("Rp16500");
        expect(screen.queryByText("PPN")).not.toBeInTheDocument();
    });

    it("shows both parts when a bill mixes inclusive and exclusive lines (#576)", () => {
        render(rows({}, { tax_total: 13200, tax_included: 11000, tax_kind: "ppn", tax_rate: 11 }));

        expect(value("PPN")).toHaveTextContent("+Rp2200");
        expect(value("Termasuk PPN 11%")).toHaveTextContent("Rp11000");
    });
});
