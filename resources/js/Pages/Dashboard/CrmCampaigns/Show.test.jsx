import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { router } from "@inertiajs/react";
import Show from "./Show";

vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    Link: ({ children }) => <a>{children}</a>,
    router: { post: vi.fn() },
}));
vi.mock("react-hot-toast", () => ({ default: { success: vi.fn(), error: vi.fn() } }));
vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));

const log = (id, status) => ({ id, status, customer: { id, name: `C${id}` }, payload: {} });
const campaign = {
    id: 1,
    name: "Promo",
    type: "promo_broadcast",
    status: "ready",
    logs: [log(1, "ready_to_send"), log(2, "sent"), log(3, "skipped")],
    audience_snapshot: [],
};

beforeEach(() => {
    vi.clearAllMocks();
    globalThis.route = (name, id) => `/${name}/${id}`;
});

describe("CrmCampaigns/Show", () => {
    it("lets an unsent log be skipped, and only an unsent one", async () => {
        render(<Show campaign={campaign} />);

        const skip = screen.getAllByRole("button", { name: "Lewati" });
        expect(skip).toHaveLength(1);

        await userEvent.click(skip[0]);

        expect(router.post).toHaveBeenCalledWith("/crm-campaign-logs.skip/1");
    });
});
