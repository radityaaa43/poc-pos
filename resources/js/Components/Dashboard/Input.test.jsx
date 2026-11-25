import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import Input from "./Input";

describe("Input", () => {
    it("links the label to the input", () => {
        render(<Input label="Name" />);

        const input = screen.getByLabelText("Name");
        expect(input.labels).toHaveLength(1);
    });

    it("marks the input invalid and points it at the error message", () => {
        render(<Input label="Name" errors="Name is required" />);

        const input = screen.getByLabelText("Name");
        const error = screen.getByText("Name is required");
        expect(input).toHaveAttribute("aria-invalid", "true");
        expect(error).toHaveAttribute("id");
        expect(input).toHaveAttribute("aria-describedby", error.id);
    });

    it("sets no aria attributes without an error", () => {
        render(<Input label="Name" />);

        const input = screen.getByLabelText("Name");
        expect(input).not.toHaveAttribute("aria-invalid");
        expect(input).not.toHaveAttribute("aria-describedby");
    });

    it("keeps the id the caller passes", () => {
        render(<Input id="sku" label="SKU" errors="Taken" />);

        expect(screen.getByLabelText("SKU")).toHaveAttribute("id", "sku");
        expect(screen.getByText("Taken")).toHaveAttribute("id", "sku-error");
    });

    it("gives two inputs on one page different ids", () => {
        render(
            <>
                <Input label="First" />
                <Input label="Second" />
            </>
        );

        expect(screen.getByLabelText("First").id).not.toBe(screen.getByLabelText("Second").id);
    });

    it("merges the caller's aria-describedby with the error id", () => {
        render(<Input label="Name" aria-describedby="hint" errors="Required" />);

        const input = screen.getByLabelText("Name");
        const error = screen.getByText("Required");
        expect(input).toHaveAttribute("aria-describedby", `hint ${error.id}`);
    });
});
