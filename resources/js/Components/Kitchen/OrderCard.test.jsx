import { beforeAll, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import i18n from "@/i18n";
import OrderCard from "./OrderCard";

beforeAll(() => i18n.changeLanguage("id"));

const order = (items) => ({
    id: 1,
    number: "Meja 5",
    type: "dine_in",
    unpaid: false,
    since: new Date().toISOString(),
    items,
});

const mount = (items) => render(
    <OrderCard
        order={order(items)}
        orders={[order(items)]}
        now={Date.now()}
        multi={false}
        showAll={false}
        chosen={[]}
        activeIds={new Set()}
        canPrepare={false}
        canHandOver={false}
        stepButton={vi.fn()}
        notPrinted={() => false}
        hasPrinter={false}
        printAction={vi.fn()}
    />
);

describe("OrderCard", () => {
    it("shows the chosen options, free ones included, and the note under the menu name", () => {
        mount([{ id: 9, title: "Kopi Susu", qty: 1, note: "tanpa gula", status: "accepted", modifiers: ["Large", "Jelly"] }]);

        expect(screen.getByText("Kopi Susu", { exact: false })).toBeTruthy();
        expect(screen.getByText("+ Large")).toBeTruthy();
        expect(screen.getByText("+ Jelly")).toBeTruthy();
        expect(screen.getByText("tanpa gula")).toBeTruthy();
    });

    it("shows a plain line without options unchanged", () => {
        mount([{ id: 9, title: "Es Teh", qty: 1, note: null, status: "accepted", modifiers: [] }]);

        expect(screen.getByText("Es Teh", { exact: false })).toBeTruthy();
        expect(screen.queryByText(/^\+ /)).toBeNull();
    });
});
