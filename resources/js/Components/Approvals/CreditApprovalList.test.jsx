import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import toast from "react-hot-toast";
import i18n from "@/i18n";
import DiscountApprovals from "@/Pages/Dashboard/DiscountApprovals";
import CreditApprovalList from "./CreditApprovalList";

const post = vi.fn();
let errors = {};
let processing = false;
vi.mock("@inertiajs/react", async () => {
    const { useState } = await import("react");

    return {
        Head: () => null,
        Link: ({ children }) => <a>{children}</a>,
        router: { post: vi.fn() },
        useForm: (initial) => {
            const [data, set] = useState(initial);

            return {
                data,
                errors,
                processing,
                setData: (key, value) => set((old) => ({ ...old, [key]: value })),
                post: (url, options) => post(url, data, options),
            };
        },
    };
});
vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));
vi.mock("react-hot-toast", () => ({ default: { success: vi.fn(), error: vi.fn() } }));

const request = {
    id: 7,
    customer: "Budi",
    cashier: "Sari",
    amount: 210000,
    refusal: "Melebihi batas piutang — sisa Rp 200.000",
    created_at: "2026-11-28T03:00:00Z",
};

beforeAll(() => i18n.changeLanguage("id"));
beforeEach(() => {
    vi.clearAllMocks();
    errors = {};
    processing = false;
    globalThis.route = (name, id) => `/${name}/${id}`;
});

describe("CreditApprovalList", () => {
    it("says when nothing is waiting", () => {
        render(<CreditApprovalList requests={[]} />);

        expect(screen.getByRole("heading", { name: "Menunggu persetujuan kredit" })).toBeInTheDocument();
        expect(screen.getByText("Tidak ada pengajuan kredit.")).toBeInTheDocument();
    });

    it("shows who, how much and why, and sends the typed reason with either answer", async () => {
        const user = userEvent.setup();
        render(<CreditApprovalList requests={[request]} />);

        for (const text of ["Budi", "Sari", request.refusal]) expect(screen.getByText(text)).toBeInTheDocument();
        expect(screen.getByText(/Rp\s*210\.000/)).toBeInTheDocument();

        await user.type(screen.getByLabelText("Alasan"), "Pelanggan lama");
        await user.click(screen.getByRole("button", { name: "Setujui" }));
        await user.click(screen.getByRole("button", { name: "Tolak" }));

        expect(post.mock.calls.map(([url, data]) => [url, data.reason])).toEqual([
            ["/credit-approvals.approve/7", "Pelanggan lama"],
            ["/credit-approvals.deny/7", "Pelanggan lama"],
        ]);
    });

    it("toasts the server's answer, so it stays visible when the card leaves the list", async () => {
        const user = userEvent.setup();
        render(<CreditApprovalList requests={[request]} />);
        await user.click(screen.getByRole("button", { name: "Setujui" }));
        const options = post.mock.lastCall[2];

        options.onSuccess({ props: { flash: { success: "Disetujui" } } });
        options.onError({ reason: "Sudah ditolak" });

        expect(toast.success).toHaveBeenCalledWith("Disetujui");
        expect(toast.error).toHaveBeenCalledWith("Sudah ditolak");
    });

    it.each([["reason"], ["stock"]])("announces a %s error by the reason field", (field) => {
        errors = { [field]: "Pesan galat" };
        render(<CreditApprovalList requests={[request]} />);

        expect(screen.getByRole("alert")).toHaveTextContent("Pesan galat");
        expect(screen.getByLabelText("Alasan")).toHaveAccessibleDescription("Pesan galat");
        expect(screen.getByLabelText("Alasan")).toBeInvalid();
    });

    it("locks both answers while one is being sent", () => {
        processing = true;
        render(<CreditApprovalList requests={[request]} />);

        expect(screen.getByRole("button", { name: "Setujui" })).toBeDisabled();
        expect(screen.getByRole("button", { name: "Tolak" })).toBeDisabled();
    });
});

describe("approval page", () => {
    const title = () => screen.getAllByRole("heading", { level: 1 }).map((heading) => heading.textContent);

    it("shows a credit approver only the credit requests", () => {
        render(<DiscountApprovals pendingTransactions={null} creditRequests={[request]} />);

        expect(title()).toEqual(["Menunggu persetujuan kredit"]);
        expect(screen.queryByText("Approval Diskon")).not.toBeInTheDocument();
    });

    it("shows a discount approver only the discounts", () => {
        render(<DiscountApprovals pendingTransactions={[]} creditRequests={null} />);

        expect(title()).toEqual(["Approval Diskon"]);
        expect(screen.queryByText("Menunggu persetujuan kredit")).not.toBeInTheDocument();
    });

    it("shows both to someone who may decide both", () => {
        render(<DiscountApprovals pendingTransactions={[]} creditRequests={[request]} />);

        expect(title()).toEqual(["Approval Diskon"]);
        expect(screen.getByRole("heading", { level: 2, name: "Menunggu persetujuan kredit" })).toBeInTheDocument();
    });
});
