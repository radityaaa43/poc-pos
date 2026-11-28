import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { router } from "@inertiajs/react";
import Swal from "sweetalert2";
import Show from "./Show";

let canApprove;

vi.mock("sweetalert2", () => ({ default: { fire: vi.fn() } }));
vi.mock("react-i18next", () => ({ useTranslation: () => ({ t: (key) => key }) }));
vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));
vi.mock("@/Utils/Permission", () => ({ default: () => canApprove }));
vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    Link: ({ children }) => children,
    router: { patch: vi.fn() },
    useForm: () => ({ data: { segment_ids: [] }, setData: vi.fn(), put: vi.fn(), errors: {} }),
}));

const renderShow = (mayBuyOnCredit = false, creditStanding = null) =>
    render(
        <Show
            customer={{ id: 7, name: "Budi", loyalty_points: 0, is_loyalty_member: true, may_buy_on_credit: mayBuyOnCredit }}
            stats={{}}
            recentTransactions={[]}
            frequentProducts={[]}
            rewardHistory={[]}
            vouchers={[]}
            creditStanding={creditStanding}
        />
    );

describe("Customer page: may buy on credit", () => {
    beforeEach(() => {
        vi.clearAllMocks();
        canApprove = true;
        globalThis.route = (name, id) => `/${name}/${id}`;
    });

    it("lets an approver switch it on after confirming", async () => {
        Swal.fire.mockResolvedValue({ isConfirmed: true });
        renderShow(false);

        await userEvent.click(screen.getByRole("switch"));

        await waitFor(() =>
            expect(router.patch).toHaveBeenCalledWith("/customers.credit/7", { may_buy_on_credit: true }, { preserveScroll: true })
        );
    });

    it("sends nothing when the approver cancels", async () => {
        Swal.fire.mockResolvedValue({ isConfirmed: false });
        renderShow(true);

        await userEvent.click(screen.getByRole("switch"));

        await waitFor(() => expect(Swal.fire).toHaveBeenCalled());
        expect(router.patch).not.toHaveBeenCalled();
    });

    it("shows the credit limit card to credit customers only", () => {
        const standing = { credit_limit: null, used: 0, has_overdue: false };
        const { unmount } = renderShow(true, standing);

        expect(screen.getByRole("heading", { name: /customers\.credit\.limitLabel: customers\.credit\.noLimit/ })).toBeTruthy();
        unmount();

        renderShow(false, standing);
        expect(screen.queryByRole("heading", { name: /customers\.credit\.limitLabel/ })).toBeNull();
    });

    it("hides the button from users without the permission", () => {
        canApprove = false;
        renderShow(false);

        expect(screen.queryByRole("switch")).toBeNull();
        expect(screen.getByText("customers.credit.label: customers.credit.no")).toBeTruthy();
    });
});
