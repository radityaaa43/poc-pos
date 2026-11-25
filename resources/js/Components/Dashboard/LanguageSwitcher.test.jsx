import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import LanguageSwitcher from "./LanguageSwitcher";

const post = vi.fn();

// A form fake that applies transform() at submit time, like Inertia's useForm.
vi.mock("@inertiajs/react", () => ({
    usePage: () => ({ props: { locale: { current: "id" } } }),
    useForm: (initial) => {
        let transformer = (data) => data;
        return {
            processing: false,
            transform: (fn) => {
                transformer = fn;
            },
            post: (url, options) => post(url, transformer(initial), options),
        };
    },
}));

describe("LanguageSwitcher", () => {
    beforeEach(() => {
        post.mockClear();
        globalThis.route = (name) => `/${name}`;
        // Headless UI menus need it; jsdom has none.
        globalThis.ResizeObserver = class {
            observe() {}
            unobserve() {}
            disconnect() {}
        };
    });

    it("posts the chosen language as the body, not the current one", async () => {
        render(<LanguageSwitcher />);

        await userEvent.click(screen.getByTitle("Change Language"));
        await userEvent.click(screen.getByText("English"));

        expect(post).toHaveBeenCalledTimes(1);
        const [url, body] = post.mock.calls[0];
        expect(url).toBe("/language.switch");
        expect(body).toEqual({ locale: "en" });
    });
});
