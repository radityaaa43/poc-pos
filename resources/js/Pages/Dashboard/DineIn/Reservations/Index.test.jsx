import { useState } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { router } from "@inertiajs/react";
import toast from "react-hot-toast";
import Swal from "sweetalert2";
import i18n from "@/i18n";
import Reservations from "./Index";

const post = vi.fn();
const patch = vi.fn();
let formErrors; // what the fake useForm reports as `errors`
let posted; // the form data at the time of the last save

vi.mock("@inertiajs/react", () => ({
    Head: () => null,
    router: { get: vi.fn(), post: vi.fn() },
    // Just enough of Inertia's useForm: state, setData(key, value) or setData(object), and the verbs.
    useForm: (initial) => {
        const [data, setAll] = useState(initial);
        return {
            data,
            setData: (key, value) => setAll((prev) => (typeof key === "string" ? { ...prev, [key]: value } : key)),
            post: (url, options) => { posted = data; post(url, options); },
            patch: (url, options) => { posted = data; patch(url, options); },
            processing: false,
            errors: formErrors,
            clearErrors: vi.fn(),
        };
    },
}));
vi.mock("@/Layouts/DashboardLayout", () => ({ default: ({ children }) => children }));
vi.mock("react-hot-toast", () => ({ default: { success: vi.fn(), error: vi.fn() } }));
vi.mock("sweetalert2", () => ({ default: { fire: vi.fn() } }));

const row = (over) => ({
    id: 1, guest_name: "Budi", phone: "+62 812-0000-1111", party_size: 4, dine_table_id: 7, note: "Dekat jendela",
    status: "booked", date: "2026-12-10", time: "19:00", table_name: "Meja 1", area_name: "Teras", ...over,
});
const tables = [{ id: 7, name: "Meja 1", area_name: "Teras" }, { id: 8, name: "Meja 2", area_name: null }];
const summary = { month: "2026-12", made: 7, open: 1, arrived: 2, cancelled: 3, no_show: 1, no_show_percent: 33.3 };

const page = (reservations = [row()], summaryProp = null) =>
    render(<Reservations reservations={reservations} tables={tables} date="2026-12-10" summary={summaryProp} />);
const card = (guest) => screen.getByText(guest).closest("li");

beforeEach(async () => {
    vi.clearAllMocks();
    formErrors = {};
    posted = undefined;
    window.route = (name, id) => (id ? `/${name}/${id}` : `/${name}`);
    await i18n.changeLanguage("id");
});

describe("Reservations page", () => {
    it("lists a booking with a tel link, its table and its status as text", () => {
        page();

        expect(screen.getByRole("heading", { name: "Reservasi" })).toBeInTheDocument();
        expect(screen.getByRole("link", { name: "+62 812-0000-1111" })).toHaveAttribute("href", "tel:+6281200001111");
        const line = card("Budi");
        expect(within(line).getByText("19:00")).toBeInTheDocument();
        expect(within(line).getByText("Meja 1 (Teras)")).toBeInTheDocument();
        expect(within(line).getByText("Dicatat")).toBeInTheDocument();
        expect(within(line).queryByText(/Des 2026/)).not.toBeInTheDocument();
    });

    it("shows the date of an unmarked booking from an earlier day", () => {
        page([row({ date: "2026-12-08" })]);

        expect(within(card("Budi")).getByText("8 Des 2026")).toBeInTheDocument();
    });

    it("says the table was removed on a marked reservation that outlived it", () => {
        page([row({ status: "arrived", dine_table_id: null, table_name: null, area_name: null })]);

        expect(within(card("Budi")).getByText("Meja sudah dihapus")).toBeInTheDocument();
    });

    it("offers the three marks and Ubah only on a booked row", () => {
        page([row(), row({ id: 2, guest_name: "Sari", status: "arrived" })]);

        for (const action of ["Datang", "Tidak datang", "Batal", "Ubah"]) {
            expect(screen.getByRole("button", { name: `${action}: Budi` })).toBeInTheDocument();
            expect(screen.queryByRole("button", { name: `${action}: Sari` })).not.toBeInTheDocument();
        }
        expect(within(card("Sari")).getByText("Datang")).toBeInTheDocument();
    });

    it("keeps the actions in the booking's own card, where they wrap instead of running off screen", () => {
        page();

        const actions = within(card("Budi")).getAllByRole("button");
        expect(actions.map((button) => button.textContent)).toEqual(["Datang", "Tidak datang", "Batal", "Ubah"]);
        expect(actions[0].parentElement).toHaveClass("flex-wrap");
        expect(screen.queryByRole("table")).not.toBeInTheDocument();
    });

    it.each([["Datang", "arrived"], ["Tidak datang", "no_show"], ["Batal", "cancelled"]])(
        "marks %s only after the confirmation",
        async (label, status) => {
            Swal.fire.mockResolvedValueOnce({ isConfirmed: false }).mockResolvedValueOnce({ isConfirmed: true });
            const user = userEvent.setup();
            page();

            await user.click(screen.getByRole("button", { name: `${label}: Budi` }));
            expect(router.post).not.toHaveBeenCalled();

            await user.click(screen.getByRole("button", { name: `${label}: Budi` }));
            expect(router.post).toHaveBeenCalledWith("/dine-reservations.mark/1", { status }, expect.anything());
        },
    );

    it("tells the user when someone else already marked it", async () => {
        Swal.fire.mockResolvedValue({ isConfirmed: true });
        router.post.mockImplementation((url, data, options) => options.onError({ status: "Sudah ditandai." }));
        const user = userEvent.setup();
        page();

        await user.click(screen.getByRole("button", { name: "Datang: Budi" }));

        expect(toast.error).toHaveBeenCalledWith("Sudah ditandai.");
    });

    it("posts a new booking with the date and time split, starting on the shown date", async () => {
        const user = userEvent.setup();
        page([]);

        await user.click(screen.getByRole("button", { name: "Tambah reservasi" }));
        await user.type(screen.getByLabelText("Nama tamu"), "Sari");
        await user.type(screen.getByLabelText("Nomor HP"), "0812 1234 5678");
        await user.type(screen.getByLabelText("Jumlah tamu"), "3");
        await user.type(screen.getByLabelText("Jam"), "18:30");
        await user.selectOptions(screen.getByLabelText("Meja"), "8");
        await user.click(screen.getByRole("button", { name: "Simpan" }));

        expect(post).toHaveBeenCalledWith("/dine-reservations.store", expect.anything());
        expect(posted).toEqual({
            guest_name: "Sari", phone: "0812 1234 5678", party_size: "3", reserved_date: "2026-12-10",
            reserved_time: "18:30", dine_table_id: "8", note: "",
        });
    });

    it("patches a changed booking from its current values", async () => {
        const user = userEvent.setup();
        page();

        await user.click(screen.getByRole("button", { name: "Ubah: Budi" }));
        await user.click(screen.getByRole("button", { name: "Simpan" }));

        expect(patch).toHaveBeenCalledWith("/dine-reservations.update/1", expect.anything());
        expect(posted).toEqual({
            guest_name: "Budi", phone: "+62 812-0000-1111", party_size: 4, reserved_date: "2026-12-10",
            reserved_time: "19:00", dine_table_id: 7, note: "Dekat jendela",
        });
    });

    it("keeps a booked table selectable after it has left this outlet", async () => {
        const user = userEvent.setup();
        page([row({ dine_table_id: 99, table_name: "Meja Pindah", area_name: null })]);

        await user.click(screen.getByRole("button", { name: "Ubah: Budi" }));

        expect(screen.getByLabelText("Meja")).toHaveDisplayValue("Meja Pindah");
    });

    it("shows the clash message under Jam", async () => {
        formErrors = { reserved_time: "Meja ini sudah direservasi atas nama Budi." };
        const user = userEvent.setup();
        page([]);

        await user.click(screen.getByRole("button", { name: "Tambah reservasi" }));

        expect(screen.getByLabelText("Jam")).toHaveAccessibleDescription("Meja ini sudah direservasi atas nama Budi.");
    });

    it("reloads the list for another date", async () => {
        const user = userEvent.setup();
        page();

        await user.clear(screen.getByLabelText("Tanggal"));
        expect(router.get).not.toHaveBeenCalled();
        await user.type(screen.getByLabelText("Tanggal"), "2026-12-11");

        expect(router.get).toHaveBeenLastCalledWith(
            "/dine-reservations.index", { date: "2026-12-11" }, { preserveState: true, replace: true },
        );
    });

    it("says when a date has no booking, in Indonesian and in English", async () => {
        const { unmount } = page([]);
        expect(screen.getByText("Belum ada reservasi pada tanggal ini")).toBeInTheDocument();
        unmount();

        await i18n.changeLanguage("en");
        page([]);
        expect(screen.getByText("No reservations on this date")).toBeInTheDocument();
    });

    // The value next to a term of the month card.
    const stat = (card, label) => within(card).getByText(label, { exact: true }).nextElementSibling;

    it("summarises the month in Indonesian with the no-show rate", () => {
        page([], summary);

        const card = screen.getByRole("region", { name: "Ringkasan Desember 2026" });
        expect(stat(card, "Reservasi dibuat")).toHaveTextContent("7");
        expect(stat(card, "Datang")).toHaveTextContent("2");
        expect(stat(card, "Batal")).toHaveTextContent("3");
        expect(stat(card, "Tidak datang")).toHaveTextContent("1");
        expect(stat(card, "Belum ditandai")).toHaveTextContent("1");
        expect(stat(card, "Tingkat tidak datang")).toHaveTextContent("33,3%");
    });

    it("shows the month summary in English", async () => {
        await i18n.changeLanguage("en");
        page([], summary);

        const card = screen.getByRole("region", { name: "December 2026 summary" });
        expect(stat(card, "Reservations made")).toHaveTextContent("7");
        expect(stat(card, "Not marked")).toHaveTextContent("1");
        expect(stat(card, "No-show rate")).toHaveTextContent("33.3%");
    });

    it("shows a dash instead of a rate while nobody arrived or stayed away", () => {
        page([], { ...summary, arrived: 0, no_show: 0, no_show_percent: null });

        const rate = stat(screen.getByRole("region", { name: "Ringkasan Desember 2026" }), "Tingkat tidak datang");
        expect(rate).toHaveTextContent("—");
        expect(rate).toHaveAttribute("title", "Belum ada tamu datang atau tidak datang bulan ini");
    });
});
