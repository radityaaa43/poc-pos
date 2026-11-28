import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { router } from "@inertiajs/react";
import i18n from "@/i18n";
import ForceCloseSummary from "./ForceCloseSummary";

vi.mock("@inertiajs/react", () => ({
    router: { get: vi.fn() },
    Link: ({ children, href }) => <a href={href}>{children}</a>,
}));

const originalRoute = globalThis.route;
const overHoldShift = {
    id: 77,
    cashier: "E2E Cashier",
    closed_at: "2024-02-12T10:00:00.000Z",
    close_notes: "Tamu sudah pulang",
};
const summary = { month: "2024-02", closed: 3, force_closed: 2, held: 1, held_shifts: [overHoldShift] };
const props = {
    summaryFilters: { outlet_id: 1, month: "2024-02" },
    summaryOutlets: [{ id: 1, code: "E2E", name: "Outlet E2E" }],
};

beforeAll(() => i18n.changeLanguage("id"));
beforeEach(async () => {
    await i18n.changeLanguage("id");
    vi.clearAllMocks();
    globalThis.route = (name, params) => (params === undefined ? `/${name}` : `/${name}/${params}`);
});
afterEach(() => {
    globalThis.route = originalRoute;
});

describe("ForceCloseSummary", () => {
    it.each([
        ["id", { closed: "Shift ditutup", forceClosed: "Ditutup paksa", overHold: "Ditutup paksa saat tertahan", link: "Lihat shift #77" }],
        ["en", { closed: "Shifts closed", forceClosed: "Force-closed", overHold: "Force-closed over a hold", link: "View shift #77" }],
    ])("shows the three figures and the over-hold row in %s", async (language, labels) => {
        await i18n.changeLanguage(language);
        render(<ForceCloseSummary forceCloseSummary={summary} {...props} />);

        expect(screen.getByText(labels.closed).parentElement).toHaveTextContent("3");
        expect(screen.getByText(labels.forceClosed).parentElement).toHaveTextContent("2");
        expect(screen.getByText(labels.overHold).parentElement).toHaveTextContent("1");
        expect(screen.getByText("E2E Cashier")).toBeInTheDocument();
        expect(screen.getByText("Tamu sudah pulang")).toBeInTheDocument();
        expect(screen.getByRole("link", { name: labels.link })).toHaveAttribute("href", "/cashier-shifts.show/77");

        await i18n.changeLanguage("id");
    });

    it("says so when the month closed no shift and when none was force-closed over a hold", () => {
        const { rerender } = render(
            <ForceCloseSummary forceCloseSummary={{ ...summary, closed: 0, force_closed: 0, held: 0, held_shifts: [] }} {...props} />
        );
        expect(screen.getByText("Belum ada shift ditutup bulan ini")).toBeInTheDocument();

        rerender(<ForceCloseSummary forceCloseSummary={{ ...summary, held: 0, held_shifts: [] }} {...props} />);
        expect(screen.getByText("Tidak ada shift yang ditutup paksa saat tertahan")).toBeInTheDocument();
    });

    it("shows '-' when an over-hold shift has no close note", () => {
        render(
            <ForceCloseSummary
                forceCloseSummary={{ ...summary, held_shifts: [{ ...overHoldShift, close_notes: null }] }}
                {...props}
            />
        );

        expect(screen.getByText("-")).toBeInTheDocument();
    });

    it("shows the outlet select only when the user has more than one outlet", () => {
        const { rerender } = render(
            <ForceCloseSummary forceCloseSummary={summary} summaryFilters={{}} summaryOutlets={[{ id: 1, code: "A", name: "Outlet A" }]} />
        );
        expect(screen.queryByLabelText("Outlet")).not.toBeInTheDocument();

        rerender(
            <ForceCloseSummary
                forceCloseSummary={summary}
                summaryFilters={{}}
                summaryOutlets={[{ id: 1, code: "A", name: "Outlet A" }, { id: 2, code: "B", name: "Outlet B" }]}
            />
        );
        expect(screen.getByLabelText("Outlet")).toBeInTheDocument();
    });

    it("shows the validation error under the fields", () => {
        render(<ForceCloseSummary forceCloseSummary={summary} errors={{ summary_month: "Format bulan tidak valid" }} {...props} />);

        expect(screen.getByText("Format bulan tidak valid")).toBeInTheDocument();
    });

    it("keeps the list filters when the summary is applied", async () => {
        render(
            <ForceCloseSummary
                forceCloseSummary={summary}
                {...props}
                listFilters={{ status: "closed", cashier_id: "5", opened_from: "", opened_to: "" }}
            />
        );

        fireEvent.change(screen.getByLabelText("Bulan"), { target: { value: "2024-03" } });
        await userEvent.click(screen.getByRole("button", { name: "Terapkan" }));

        expect(router.get).toHaveBeenCalledWith(
            "/cashier-shifts.index",
            { status: "closed", cashier_id: "5", summary_outlet_id: "1", summary_month: "2024-03" },
            { preserveState: true, preserveScroll: true }
        );
    });
});
