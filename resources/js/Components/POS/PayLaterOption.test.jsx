import { describe, it, expect, vi, beforeAll, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import i18n from "@/i18n";
import PayLaterOption from "./PayLaterOption";

let online = true;
vi.mock("@/Context/OnlineStatusContext", () => ({ useOnlineStatus: () => online }));

const props = {
    enabled: true,
    payLater: false,
    onPayLaterChange: vi.fn(),
    customer: null,
    dueDate: "",
    onDueDateChange: vi.fn(),
    note: "",
    onNoteChange: vi.fn(),
};

beforeAll(() => i18n.changeLanguage("id"));
beforeEach(() => {
    vi.clearAllMocks();
    online = true;
});

describe("PayLaterOption", () => {
    it("renders nothing when the outlet has pay later off", () => {
        const { container } = render(<PayLaterOption {...props} enabled={false} />);

        expect(container).toBeEmptyDOMElement();
    });

    it("offers the toggle when enabled and online", async () => {
        render(<PayLaterOption {...props} />);

        await userEvent.click(screen.getByRole("checkbox", { name: /Bayar Belakangan/ }));

        expect(props.onPayLaterChange).toHaveBeenCalledWith(true);
    });

    it("shows only the notice offline, and switches pay later off", () => {
        online = false;
        render(<PayLaterOption {...props} payLater />);

        expect(screen.getByText("Bayar belakangan tidak tersedia saat offline")).toBeInTheDocument();
        expect(screen.queryByRole("checkbox")).not.toBeInTheDocument();
        expect(props.onPayLaterChange).toHaveBeenCalledWith(false);
    });

    it("asks a guest for a required note, not a due date", () => {
        render(<PayLaterOption {...props} payLater />);

        expect(screen.getByLabelText(/Catatan/)).toBeRequired();
        expect(screen.queryByLabelText("Tanggal Jatuh Tempo")).not.toBeInTheDocument();
    });

    it("asks a credit customer for a due date, not a note", () => {
        render(<PayLaterOption {...props} payLater customer={{ id: 5, may_buy_on_credit: true }} />);

        expect(screen.getByLabelText("Tanggal Jatuh Tempo")).toBeInTheDocument();
        expect(screen.queryByLabelText(/Catatan/)).not.toBeInTheDocument();
    });

    it("asks a customer without credit for neither", () => {
        render(<PayLaterOption {...props} payLater customer={{ id: 6, may_buy_on_credit: false }} />);

        expect(screen.queryByLabelText("Tanggal Jatuh Tempo")).not.toBeInTheDocument();
        expect(screen.queryByLabelText(/Catatan/)).not.toBeInTheDocument();
    });
});
