import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import toast from "react-hot-toast";
import i18n from "@/i18n";
import CreditLimitCard from "./CreditLimitCard";

const patch = vi.fn();
let formErrors = {};

vi.mock("react-hot-toast", () => ({ default: { success: vi.fn() } }));
vi.mock("@inertiajs/react", async () => {
    const { useState } = await import("react");
    return {
        useForm: (initial) => {
            const [data, setState] = useState(initial);
            return {
                data,
                setData: (key, value) => setState((current) => ({ ...current, [key]: value })),
                patch: (url, options) => patch(url, data, options),
                processing: false,
                errors: formErrors,
            };
        },
    };
});

const renderCard = (standing = {}, canApprove = true) =>
    render(
        <CreditLimitCard
            customer={{ id: 7 }}
            standing={{ credit_limit: null, used: 0, has_overdue: false, ...standing }}
            canApprove={canApprove}
        />
    );

const input = () => screen.getByRole("spinbutton", { name: "Batas piutang" });
const save = () => userEvent.click(screen.getByRole("button", { name: "Simpan" }));

describe("CreditLimitCard", () => {
    beforeAll(() => i18n.changeLanguage("id"));
    afterAll(() => i18n.changeLanguage("id"));
    beforeEach(() => {
        vi.clearAllMocks();
        formErrors = {};
        globalThis.route = (name, id) => `/${name}/${id}`;
    });

    it("shows no limit and what is used, with the field empty", () => {
        renderCard({ used: 300000 });

        expect(screen.getByRole("heading")).toHaveTextContent("Batas piutang: Tanpa batas");
        expect(screen.getByText(/^Terpakai:/)).toHaveTextContent("Terpakai: Rp 300.000");
        expect(input()).toHaveValue(null);
        expect(input()).toHaveAccessibleDescription("Kosongkan untuk Tanpa batas");
        expect(screen.queryByText("Ada piutang lewat jatuh tempo")).not.toBeInTheDocument();
    });

    it("lets an approver set a limit and says it was saved", async () => {
        renderCard();

        await userEvent.type(input(), "2000000");
        await save();

        const [url, data, options] = patch.mock.calls[0];
        expect(url).toBe("/customers.credit-limit/7");
        expect(data).toEqual({ credit_limit: "2000000" });
        options.onSuccess();
        expect(toast.success).toHaveBeenCalledWith("Batas piutang disimpan");
    });

    it("sends null, never 0, for an emptied field, and keeps a typed 0", async () => {
        renderCard({ credit_limit: 2000000 });

        await userEvent.clear(input());
        await save();
        await userEvent.type(input(), "0");
        await save();

        expect(patch.mock.calls.map((call) => call[1])).toEqual([{ credit_limit: null }, { credit_limit: "0" }]);
    });

    it("is read-only without the permission", () => {
        renderCard({ credit_limit: 2000000, has_overdue: true }, false);

        expect(screen.getByRole("heading")).toHaveTextContent("Batas piutang: Rp 2.000.000");
        expect(screen.getByText("Ada piutang lewat jatuh tempo")).toBeInTheDocument();
        expect(screen.queryByRole("spinbutton")).not.toBeInTheDocument();
        expect(screen.queryByRole("button")).not.toBeInTheDocument();
    });

    it("announces a refusal under the field", () => {
        formErrors = { credit_limit: "The credit limit field must be at least 0." };
        renderCard();

        expect(screen.getByRole("alert")).toHaveTextContent("must be at least 0");
        expect(input()).toBeInvalid();
    });

    it("speaks English and still formats rupiah", async () => {
        await i18n.changeLanguage("en");
        renderCard({ credit_limit: 2000000 });

        expect(screen.getByRole("heading")).toHaveTextContent("Credit limit: Rp 2.000.000");
    });
});
