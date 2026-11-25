import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import Form from "./Form";

// Inertia hands the server's validation errors to the page through useForm().
vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    useForm: (initial) => ({
        data: initial,
        setData: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        processing: false,
        errors: { name: "The name field is required." },
    }),
}));

vi.mock("@/Components/Dashboard/Button", () => ({
    default: ({ label }) => <button type="button">{label}</button>,
}));

describe("CRM campaign form", () => {
    it("shows the server's validation error under the name field", () => {
        globalThis.route = (name) => `/${name}`;

        render(<Form audienceOptions={{ segment_options: [], customer_types: [], receivable_statuses: [], voucher_filters: [] }} />);

        expect(screen.getByText("The name field is required.")).toBeTruthy();
    });
});
