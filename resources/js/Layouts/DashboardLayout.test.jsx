import { useState } from "react";
import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import DashboardLayout from "./DashboardLayout";

let outletId = 1;

vi.mock("@inertiajs/react", () => ({
    usePage: () => ({ url: "/dashboard/settings/printer", props: { auth: { currentOutlet: { id: outletId } }, security: {} } }),
}));
vi.mock("@/Components/Dashboard/Sidebar", () => ({ default: () => null }));
vi.mock("@/Components/Dashboard/Navbar", () => ({ default: () => null }));
vi.mock("@/Context/ThemeSwitcherContext", () => ({ useTheme: () => ({ darkMode: false, themeSwitcher: vi.fn() }) }));

// A page that seeds its form state once, like Printer.jsx seeds useForm from props.
function Page() {
    const [paper, setPaper] = useState("80mm");
    return <button onClick={() => setPaper("58mm")}>{paper}</button>;
}

describe("DashboardLayout", () => {
    it("remounts the page when the active outlet changes, so seeded form state resets", async () => {
        const { rerender } = render(<DashboardLayout><Page /></DashboardLayout>);
        await userEvent.click(screen.getByRole("button"));
        expect(screen.getByRole("button")).toHaveTextContent("58mm");

        outletId = 2;
        rerender(<DashboardLayout><Page /></DashboardLayout>);

        expect(screen.getByRole("button")).toHaveTextContent("80mm");
    });
});
