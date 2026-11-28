import { beforeAll, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import i18n from "@/i18n";
import OrderTypeLock from "./OrderTypeLock";

const state = vi.hoisted(() => ({ online: true, rate: 0 }));
vi.mock("@/Context/OnlineStatusContext", () => ({ useOnlineStatus: () => state.online }));
vi.mock("@inertiajs/react", () => ({ usePage: () => ({ props: { activeCashierShift: { service_charge_rate: state.rate } } }) }));

const view = (online, rate) => {
    Object.assign(state, { online, rate });
    render(
        <OrderTypeLock>
            <button type="button">Bawa Pulang</button>
        </OrderTypeLock>
    );
};

describe.each([
    ["id", "Tipe pesanan tidak bisa diubah saat offline di outlet ini"],
    ["en", "The order type can't be changed offline at this outlet"],
])("OrderTypeLock (%s)", (language, reason) => {
    beforeAll(() => i18n.changeLanguage(language));

    it("locks the buttons offline at an outlet with a service charge and says why", () => {
        view(false, 5);

        expect(screen.getByRole("button")).toBeDisabled();
        expect(screen.getByRole("status")).toHaveTextContent(reason);
        expect(screen.getByRole("group")).toHaveAttribute("title", reason);
    });

    it.each([
        { online: true, rate: 5 },
        { online: true, rate: 0 },
        { online: false, rate: 0 },
    ])("leaves them alone when online is $online and the rate is $rate", ({ online, rate }) => {
        view(online, rate);

        expect(screen.getByRole("button")).toBeEnabled();
        expect(screen.getByRole("status")).toBeEmptyDOMElement();
        expect(screen.getByRole("group")).not.toHaveAttribute("title");
    });
});
