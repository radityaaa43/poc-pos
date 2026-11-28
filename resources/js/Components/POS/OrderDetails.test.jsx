import { useState } from "react";
import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import i18n from "@/i18n";
import OrderDetails from "./OrderDetails";

beforeEach(() => i18n.changeLanguage("id"));

function Harness({ onOrderTypeChange = () => {} }) {
    const [name, setName] = useState("");

    return <OrderDetails orderType="in_store" onOrderTypeChange={onOrderTypeChange} orderName={name} onOrderNameChange={setName} />;
}

describe("OrderDetails", () => {
    it("picks an order type", async () => {
        const onOrderTypeChange = vi.fn();
        render(<Harness onOrderTypeChange={onOrderTypeChange} />);

        await userEvent.click(screen.getByRole("button", { name: "Bawa Pulang" }));

        expect(onOrderTypeChange).toHaveBeenCalledWith("takeaway");
    });

    it("offers an optional order name of at most 20 characters", async () => {
        render(<Harness />);
        const input = screen.getByLabelText("Nama pesanan");

        expect(input).toHaveAttribute("maxlength", "20");
        expect(input).toHaveAttribute("placeholder", "Nama / nomor buzzer (opsional)");
        await userEvent.type(input, "Budi 7 yang sangat panjang sekali");
        expect(input).toHaveValue("Budi 7 yang sangat p");
    });

    it("counts from 15 characters and warns at the limit", async () => {
        render(<Harness />);
        const input = screen.getByLabelText("Nama pesanan");

        await userEvent.type(input, "a".repeat(14));
        expect(screen.queryByText(/\/20/)).not.toBeInTheDocument();

        await userEvent.type(input, "a");
        expect(screen.getByText("15/20")).toBeInTheDocument();
        expect(screen.queryByText("Maksimal 20 karakter")).not.toBeInTheDocument();
        expect(input).toHaveAccessibleDescription("15/20");

        await userEvent.type(input, "aaaaa");
        expect(screen.getByText("20/20")).toBeInTheDocument();
        expect(screen.getByText("Maksimal 20 karakter")).toBeInTheDocument();
    });

    it("uses the English wording", async () => {
        await i18n.changeLanguage("en");
        render(<Harness />);

        const input = screen.getByLabelText("Order name");
        expect(input).toHaveAttribute("placeholder", "Name / buzzer number (optional)");
        await userEvent.type(input, "a".repeat(20));
        expect(screen.getByText("Max 20 characters")).toBeInTheDocument();
    });
});
