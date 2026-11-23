import { createRef } from "react";
import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";

import InputError from "./InputError";
import InputLabel from "./InputLabel";
import TextInput from "./TextInput";
import Modal from "./Modal";
import Dropdown from "./Dropdown";

// Dropdown.Link is an Inertia Link; a plain anchor is enough at this boundary.
// The test uses a hash href because jsdom cannot navigate to a real URL.
vi.mock("@inertiajs/react", () => ({
    Link: ({ children, ...props }) => (
        <a {...props}>
            {children}
        </a>
    ),
}));

// jsdom has no getAnimations; headlessui polyfills it with a console warning
// unless it exists. Stubbing it here keeps the output clean.
Element.prototype.getAnimations = () => [];

describe("InputError", () => {
    it("renders the message", () => {
        render(<InputError message="The name is required." />);

        expect(screen.getByText("The name is required.")).toBeInTheDocument();
    });

    it("renders nothing when the message is empty", () => {
        const { container } = render(<InputError message="" />);

        expect(container).toBeEmptyDOMElement();
    });
});

describe("InputLabel", () => {
    it("prefers value over children", () => {
        render(<InputLabel value="From value">From children</InputLabel>);

        expect(screen.getByText("From value")).toBeInTheDocument();
        expect(screen.queryByText("From children")).not.toBeInTheDocument();
    });

    it("falls back to children when there is no value", () => {
        render(<InputLabel>From children</InputLabel>);

        expect(screen.getByText("From children")).toBeInTheDocument();
    });
});

describe("TextInput", () => {
    it("forwards a ref to the input", () => {
        const ref = createRef();
        render(<TextInput ref={ref} placeholder="Name" />);

        expect(ref.current).toBe(screen.getByPlaceholderText("Name"));
    });

    it("focuses itself when isFocused", () => {
        render(<TextInput isFocused placeholder="Name" />);

        expect(screen.getByPlaceholderText("Name")).toHaveFocus();
    });

    it("does not take focus by default", () => {
        render(<TextInput placeholder="Name" />);

        expect(screen.getByPlaceholderText("Name")).not.toHaveFocus();
    });
});

describe("Modal", () => {
    it("shows its children when show is true", async () => {
        render(
            <Modal show onClose={() => {}}>
                <p>Modal body</p>
            </Modal>,
        );

        expect(await screen.findByText("Modal body")).toBeInTheDocument();
    });

    it("renders nothing when show is false", () => {
        render(
            <Modal show={false}>
                <p>Modal body</p>
            </Modal>,
        );

        expect(screen.queryByText("Modal body")).not.toBeInTheDocument();
    });

    it("calls onClose on Escape when closeable", async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        render(
            <Modal show onClose={onClose}>
                <p>Modal body</p>
            </Modal>,
        );
        await screen.findByText("Modal body");

        await user.keyboard("{Escape}");

        await waitFor(() => expect(onClose).toHaveBeenCalledTimes(1));
    });

    it("does not call onClose on Escape when not closeable", async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        render(
            <Modal show closeable={false} onClose={onClose}>
                <p>Modal body</p>
            </Modal>,
        );
        await screen.findByText("Modal body");

        await user.keyboard("{Escape}");

        expect(onClose).not.toHaveBeenCalled();
    });
});

describe("Dropdown", () => {
    function renderDropdown() {
        return render(
            <Dropdown>
                <Dropdown.Trigger>
                    <button type="button">Open menu</button>
                </Dropdown.Trigger>
                <Dropdown.Content>
                    <Dropdown.Link href="#profile">Profile</Dropdown.Link>
                </Dropdown.Content>
            </Dropdown>,
        );
    }

    it("keeps the content hidden until the trigger is clicked", () => {
        renderDropdown();

        expect(screen.queryByText("Profile")).not.toBeInTheDocument();
    });

    it("opens on trigger click and closes on a content click", async () => {
        const user = userEvent.setup();
        renderDropdown();

        await user.click(screen.getByRole("button", { name: "Open menu" }));
        const link = await screen.findByRole("link", { name: "Profile" });
        expect(link).toHaveAttribute("href", "#profile");

        await user.click(link);

        await waitFor(() =>
            expect(screen.queryByText("Profile")).not.toBeInTheDocument(),
        );
    });
});
