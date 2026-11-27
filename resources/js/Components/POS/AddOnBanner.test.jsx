import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { router } from "@inertiajs/react";
import i18n from "@/i18n";
import AddOnBanner from "./AddOnBanner";

let online;
let props;

vi.mock("@inertiajs/react", () => ({ router: { replace: vi.fn() }, usePage: () => ({ props }) }));
vi.mock("@/Context/OnlineStatusContext", () => ({ useOnlineStatus: () => online }));

const addOn = (state, reason = null) => ({ invoice: "TRX-1", state, reason });
const show = (value) => {
    props = { addOn: value };
    return render(<AddOnBanner />);
};

beforeAll(() => i18n.changeLanguage("id"));
beforeEach(() => {
    vi.clearAllMocks();
    online = true;
});

describe("AddOnBanner", () => {
    it("renders nothing for a normal sale", () => {
        const { container } = show(null);

        expect(container).toBeEmptyDOMElement();
    });

    it("names the original order of an add-on", () => {
        show(addOn("linked"));

        expect(screen.getByRole("status")).toHaveTextContent("Pesanan tambahan untuk TRX-1");
    });

    it("says a finished order starts a new one (BR-6)", () => {
        show(addOn("completed"));

        expect(screen.getByRole("status")).toHaveTextContent("Pesanan sebelumnya sudah selesai. Dibuat sebagai pesanan baru.");
    });

    it("shows the refusal of a cancelled order (BR-11)", () => {
        show(addOn("cancelled", "Pesanan asal sudah dibatalkan — buat pesanan baru"));

        expect(screen.getByRole("alert")).toHaveTextContent("Pesanan asal sudah dibatalkan — buat pesanan baru");
    });

    it("shows any other refusal in the server's words", () => {
        show(addOn("refused", "Buka shift di outlet pesanan ini"));

        expect(screen.getByRole("alert")).toHaveTextContent("Buka shift di outlet pesanan ini");
    });

    it("says add-ons are not available offline (BR-7)", () => {
        online = false;
        show(addOn("linked"));

        expect(screen.getByRole("alert")).toHaveTextContent("Pesanan tambahan tidak tersedia saat offline");
    });

    it("drops the add-on without asking the server", async () => {
        show(addOn("linked"));

        await userEvent.click(screen.getByRole("button", { name: "Batal" }));

        const visit = router.replace.mock.calls[0][0];
        expect(visit.url).toBe("/transactions.index");
        expect(visit.props({ carts: [1], addOn: addOn("linked") })).toEqual({ carts: [1], addOn: null });
    });
});
