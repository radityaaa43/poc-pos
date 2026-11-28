import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import Swal from "sweetalert2";
import Button from "./Button";

let answer; // how the server answers the delete
vi.mock("sweetalert2", () => ({ default: { fire: vi.fn(async () => ({ isConfirmed: true })) } }));
vi.mock("@inertiajs/react", () => ({ useForm: () => ({ delete: (url, options) => answer(options) }) }));

describe("delete Button", () => {
    it.each([
        ["onSuccess", { icon: "success" }],
        ["onError", { icon: "error", title: "Dipakai opsi" }],
    ])("announces what the server answered (%s)", async (callback, dialog) => {
        answer = (options) => options?.[callback]({ delete: "Dipakai opsi" });
        render(<Button type="delete" url="/products/1" icon="x" />);

        await userEvent.click(screen.getByRole("button"));

        expect(Swal.fire).toHaveBeenLastCalledWith(expect.objectContaining(dialog));
    });
});
