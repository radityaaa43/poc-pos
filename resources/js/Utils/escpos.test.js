import { afterEach, describe, expect, it, vi } from "vitest";
import {
    buildKitchenTicketBytes,
    buildReceiptBytes,
    disconnectPrinter,
    drawerKickBytes,
    getPrinter,
    hasCachedPrinter,
    printBytes,
    printerKey,
    printReceipt,
    requestPrinter,
} from "./escpos";

const baseData = (overrides = {}) => ({
    money: (n) => String(n),
    invoice: "INV-1",
    items: [],
    subtotal: 1000,
    grand_total: 1000,
    cash_received: null,
    ...overrides,
});

const render = (data, paper) => new TextDecoder().decode(buildReceiptBytes(data, paper));
// Strip the ESC E emphasis toggles so lines can be matched as plain text.
const lines = (data, paper) =>
    // eslint-disable-next-line no-control-regex
    render(data, paper).replace(/\x1bE[\x00\x01]/g, "").split("\n");

describe("buildReceiptBytes", () => {
    it("prints the service charge and the tax label the caller translated", () => {
        const text = render(baseData({ service_charge: 1000, service_charge_label: "Service charge", tax_total: 2100, tax_label: "PB1 10%" }));

        expect(text).toMatch(/Service charge +1000/);
        expect(text).toMatch(/PB1 10% +2100/);
        expect(text).not.toContain("PPN");
    });

    it("keeps a sale without a service charge or a tax label as it was", () => {
        const text = render(baseData({ service_charge: 0, tax_total: 2200 }));

        expect(text).toMatch(/PPN +2200/);
        expect(text).not.toContain("Service charge");
    });

    it("prints what a returned sale still owes, and nothing extra otherwise", () => {
        expect(render(baseData({ amount_due: 800 }))).toMatch(/Sisa tagihan +800/);
        expect(render(baseData({ amount_due: null }))).not.toContain("Sisa tagihan");
    });

    it("starts with init and ends with the cut command", () => {
        const bytes = Array.from(buildReceiptBytes(baseData()));
        expect(bytes.slice(0, 2)).toEqual([0x1b, 0x40]);
        expect(bytes.slice(-4)).toEqual([0x1d, 0x56, 0x42, 0x00]);
    });

    it("uses a 32 character separator on 58mm and 48 otherwise", () => {
        expect(lines(baseData(), "58mm")).toContain("-".repeat(32));
        expect(lines(baseData(), "58mm")).not.toContain("-".repeat(48));
        expect(lines(baseData(), "80mm")).toContain("-".repeat(48));
        expect(lines(baseData())).toContain("-".repeat(48));
    });

    it("defaults the store name and footer", () => {
        const text = render(baseData());
        expect(text).toContain("TOKO");
        expect(text).toContain("Terima kasih!");
    });

    it("uses the given store name and footer", () => {
        const text = render(baseData({ store_name: "Akar", footer: "Sampai jumpa" }));
        expect(text).toContain("Akar");
        expect(text).toContain("Sampai jumpa");
        expect(text).not.toContain("Terima kasih!");
    });

    it("pads left/right lines to exactly the paper width", () => {
        const total = lines(baseData({ grand_total: 12345 })).find((l) => l.startsWith("TOTAL"));
        expect(total).toHaveLength(48);
        expect(total.endsWith("12345")).toBe(true);

        const total58 = lines(baseData({ grand_total: 12345 }), "58mm").find((l) =>
            l.startsWith("TOTAL")
        );
        expect(total58).toHaveLength(32);
    });

    it("shows Diskon, PPN, Ongkir and Kembali only when greater than zero", () => {
        const none = render(
            baseData({ discount_total: 0, tax_total: 0, shipping_cost: 0, change: 0 })
        );
        for (const label of ["Diskon", "PPN", "Ongkir", "Kembali"]) {
            expect(none).not.toContain(label);
        }

        const all = render(
            baseData({ discount_total: 10, tax_total: 20, shipping_cost: 30, change: 40 })
        );
        for (const label of ["Diskon", "PPN", "Ongkir", "Kembali"]) {
            expect(all).toContain(label);
        }
        expect(all).toContain("-10");
    });

    it("shows Tunai when cash received is 0 but not when it is null", () => {
        expect(render(baseData({ cash_received: 0 }))).toContain("Tunai");
        expect(render(baseData({ cash_received: null }))).not.toContain("Tunai");
    });

    it("cuts item names longer than the paper width", () => {
        const name = "A".repeat(100);
        const text = lines(baseData({ items: [{ qty: 2, name, price: 500 }] }));
        const itemLine = text.find((l) => l.startsWith("2x A"));
        expect(itemLine).toHaveLength(48);
        expect(text.some((l) => l.includes("A".repeat(49)))).toBe(false);
    });
});

describe("drawerKickBytes", () => {
    it("kicks pin 2 by default", () => {
        expect(Array.from(drawerKickBytes())).toEqual([27, 112, 0, 25, 250]);
    });

    it("kicks pin 5 when asked", () => {
        expect(Array.from(drawerKickBytes(5))).toEqual([27, 112, 1, 25, 250]);
    });
});

describe("printer connection", () => {
    const makeDevice = ({ transferOut = vi.fn().mockResolvedValue({ status: "ok" }) } = {}) => {
        const device = {
            opened: false,
            configuration: {
                interfaces: [
                    {
                        interfaceNumber: 0,
                        alternates: [
                            {
                                interfaceClass: 7,
                                endpoints: [{ direction: "out", endpointNumber: 2 }],
                            },
                        ],
                    },
                ],
            },
            open: vi.fn(async () => {
                device.opened = true;
            }),
            close: vi.fn(async () => {
                device.opened = false;
            }),
            selectConfiguration: vi.fn(),
            claimInterface: vi.fn(),
            transferOut,
        };
        return device;
    };

    afterEach(async () => {
        await disconnectPrinter();
        vi.unstubAllGlobals();
    });

    it("requestPrinter without WebUSB rejects with a clear message", async () => {
        vi.stubGlobal("navigator", { usb: undefined });
        await expect(requestPrinter()).rejects.toThrow(
            "WebUSB tidak didukung browser ini. Gunakan Chrome/Edge."
        );
    });

    it("printBytes rejects when no printer is connected", async () => {
        vi.stubGlobal("navigator", { usb: { getDevices: vi.fn().mockResolvedValue([]) } });
        await expect(printBytes(new Uint8Array([1]))).rejects.toThrow("Printer belum terhubung.");
    });

    it("has no cached printer before any connection", () => {
        expect(hasCachedPrinter()).toBe(false);
    });

    it("printReceipt sends the receipt bytes to the printer endpoint", async () => {
        const device = makeDevice();
        vi.stubGlobal("navigator", { usb: { requestDevice: vi.fn().mockResolvedValue(device) } });
        await requestPrinter();
        expect(hasCachedPrinter()).toBe(true);

        const data = baseData();
        await printReceipt(data, "80mm");

        expect(device.transferOut).toHaveBeenCalledTimes(1);
        const [endpoint, sent] = device.transferOut.mock.calls[0];
        expect(endpoint).toBe(2);
        expect(Array.from(sent)).toEqual(Array.from(buildReceiptBytes(data, "80mm")));
    });

    it("rejects and drops the cached printer when the transfer fails", async () => {
        const device = makeDevice({ transferOut: vi.fn().mockRejectedValue(new Error("usb")) });
        vi.stubGlobal("navigator", {
            usb: {
                requestDevice: vi.fn().mockResolvedValue(device),
                getDevices: vi.fn().mockResolvedValue([]),
            },
        });
        await requestPrinter();

        await expect(printBytes(new Uint8Array([1]))).rejects.toThrow(
            "Gagal mengirim data ke printer. Periksa koneksi printer."
        );
        expect(hasCachedPrinter()).toBe(false);
    });

    describe("error codes (REQ-004 K2)", () => {
        const dom = (name) => new DOMException("x", name);
        const rejected = (promise) => promise.then(() => null, (error) => error);

        it("requestPrinter without WebUSB is coded unsupported", async () => {
            vi.stubGlobal("navigator", { usb: undefined });
            expect(await rejected(requestPrinter())).toMatchObject({ code: "unsupported" });
        });

        it("getPrinter without WebUSB is coded unsupported", async () => {
            vi.stubGlobal("navigator", { usb: undefined });
            expect(await rejected(printBytes(new Uint8Array([1])))).toMatchObject({ code: "unsupported" });
        });

        it("printBytes with no granted printer is coded not_connected", async () => {
            vi.stubGlobal("navigator", { usb: { getDevices: vi.fn().mockResolvedValue([]) } });
            expect(await rejected(printBytes(new Uint8Array([1])))).toMatchObject({ code: "not_connected" });
        });

        it("a failed transfer is coded transfer and keeps the browser's error as cause", async () => {
            const failure = dom("NetworkError");
            const device = makeDevice({ transferOut: vi.fn().mockRejectedValue(failure) });
            vi.stubGlobal("navigator", {
                usb: { requestDevice: vi.fn().mockResolvedValue(device), getDevices: vi.fn().mockResolvedValue([]) },
            });
            await requestPrinter();

            const error = await rejected(printBytes(new Uint8Array([1])));

            expect(error).toMatchObject({ code: "transfer", message: "Gagal mengirim data ke printer. Periksa koneksi printer." });
            expect(error.cause).toBe(failure);
        });

        const connect = async (device) => {
            vi.stubGlobal("navigator", {
                usb: { requestDevice: vi.fn().mockResolvedValue(device), getDevices: vi.fn().mockResolvedValue([]) },
            });
            await requestPrinter();
        };

        it("a stalled transfer is a failed send, and the endpoint is cleared (#410 D3)", async () => {
            const device = makeDevice({ transferOut: vi.fn().mockResolvedValue({ status: "stall" }) });
            device.clearHalt = vi.fn();
            await connect(device);

            expect(await rejected(printBytes(new Uint8Array([1])))).toMatchObject({ code: "transfer" });
            expect(device.clearHalt).toHaveBeenCalledWith("out", 2);
        });

        it("a transfer that never settles fails after 15 s and closes the device (#410 D6)", async () => {
            const device = makeDevice({ transferOut: vi.fn(() => new Promise(() => {})) });
            await connect(device);
            vi.useFakeTimers();
            try {
                const outcome = rejected(printBytes(new Uint8Array([1])));
                await vi.advanceTimersByTimeAsync(15000);

                expect(await outcome).toMatchObject({ code: "transfer" });
                expect(device.close).toHaveBeenCalled();
            } finally {
                vi.useRealTimers();
            }
        }, 4000);

        it("a claim that never answers fails the send after 15 s, closes the device and prints nothing later (#410 D6)", async () => {
            const device = makeDevice();
            let claim;
            device.claimInterface.mockImplementation(() => new Promise((resolve) => (claim = resolve)));
            vi.stubGlobal("navigator", { usb: { getDevices: vi.fn().mockResolvedValue([device]) } });
            vi.useFakeTimers();
            try {
                const outcome = rejected(printBytes(new Uint8Array([1])));
                await vi.advanceTimersByTimeAsync(15000);

                expect(await outcome).toMatchObject({ code: "transfer" });
                expect(device.close).toHaveBeenCalled();
                claim(); // the late answer
                await vi.advanceTimersByTimeAsync(0);
                expect(device.transferOut).not.toHaveBeenCalled();
            } finally {
                vi.useRealTimers();
            }
        }, 4000);

        it("a claimInterface failure is coded claim_failed, keeps its message and cause, and closes the device", async () => {
            const failure = dom("NetworkError");
            const device = makeDevice();
            device.claimInterface.mockRejectedValue(failure);
            vi.stubGlobal("navigator", { usb: { requestDevice: vi.fn().mockResolvedValue(device) } });

            const error = await rejected(requestPrinter());

            expect(error).toMatchObject({ code: "claim_failed", message: failure.message });
            expect(error.cause).toBe(failure);
            expect(device.close).toHaveBeenCalled();
        });

        it("an open failure on re-attach is coded claim_failed", async () => {
            const failure = dom("SecurityError");
            const device = makeDevice();
            device.open.mockRejectedValue(failure);
            vi.stubGlobal("navigator", { usb: { getDevices: vi.fn().mockResolvedValue([device]) } });

            const error = await rejected(getPrinter());

            expect(error).toMatchObject({ code: "claim_failed" });
            expect(error.cause).toBe(failure);
        });

        it("a device without an output interface is coded claim_failed", async () => {
            const device = makeDevice();
            device.configuration.interfaces = [];
            vi.stubGlobal("navigator", { usb: { requestDevice: vi.fn().mockResolvedValue(device) } });

            expect(await rejected(requestPrinter())).toMatchObject({ code: "claim_failed" });
        });
    });
});

describe("buildKitchenTicketBytes", () => {
    const labels = { type: "Makan di tempat", unpaid: "BELUM LUNAS" };
    const ticket = (overrides = {}) => ({
        number: "Meja 5",
        type: "dine_in",
        unpaid: false,
        since: "2026-11-25T14:07:00",
        items: [{ title: "Nasi Goreng", qty: 2, note: null }],
        ...overrides,
    });
    const bytesOf = (t, paper, l = labels) => Array.from(buildKitchenTicketBytes(t, paper, l));
    const textOf = (t, paper, l = labels) => new TextDecoder().decode(buildKitchenTicketBytes(t, paper, l));
    // Strip the ESC/GS formatting commands so the lines can be matched as plain text.
    const kitchenLines = (t, paper, l = labels) =>
        textOf(t, paper, l)
            // eslint-disable-next-line no-control-regex
            .replace(/\x1b@|\x1bE[\x00\x01]|\x1d![\s\S]|\x1dVB\x00/g, "")
            .split("\n");

    it("starts with init and ends with the cut command", () => {
        const bytes = bytesOf(ticket());
        expect(bytes.slice(0, 2)).toEqual([0x1b, 0x40]);
        expect(bytes.slice(-4)).toEqual([0x1d, 0x56, 0x42, 0x00]);
    });

    it("prints the number in double size at the top, then resets the size", () => {
        // eslint-disable-next-line no-control-regex
        expect(textOf(ticket())).toMatch(/^\x1b@\x1d!\x11Meja 5\n\x1d!\x00/);
    });

    it("shows the type and the time, right aligned to the paper width", () => {
        const head = kitchenLines(ticket()).find((l) => l.startsWith("Makan di tempat"));
        expect(head.endsWith("14:07")).toBe(true);
        expect(head).toHaveLength(48);
        expect(kitchenLines(ticket(), "58mm").find((l) => l.includes("14:07"))).toHaveLength(32);
    });

    it("prints the unpaid label in emphasis only when unpaid", () => {
        expect(textOf(ticket())).not.toContain("BELUM LUNAS");
        // eslint-disable-next-line no-control-regex
        expect(textOf(ticket({ unpaid: true }))).toMatch(/\x1bE\x01BELUM LUNAS\n\x1bE\x00/);
    });

    it("uses the translated labels it is given", () => {
        const text = textOf(ticket({ unpaid: true }), "80mm", { type: "Dine in", unpaid: "UNPAID" });
        expect(text).toContain("Dine in");
        expect(text).toContain("UNPAID");
        expect(text).not.toContain("BELUM LUNAS");
    });

    it("uses rules of 32 characters on 58mm and 48 otherwise", () => {
        expect(kitchenLines(ticket(), "58mm")).toContain("-".repeat(32));
        expect(kitchenLines(ticket(), "58mm")).not.toContain("-".repeat(48));
        expect(kitchenLines(ticket(), "80mm")).toContain("-".repeat(48));
        expect(kitchenLines(ticket(), undefined)).toContain("-".repeat(48));
    });

    it("prints each item as qty x title with the note on the following lines", () => {
        const lines = kitchenLines(
            ticket({
                items: [
                    { title: "Nasi Goreng", qty: 2, note: "tanpa pedas" },
                    { title: "Es Teh", qty: 1, note: "" },
                ],
            })
        );
        const i = lines.indexOf("2 x Nasi Goreng");
        expect(i).toBeGreaterThan(-1);
        expect(lines[i + 1].trim()).toBe("tanpa pedas");
        expect(lines[i + 2]).toBe("1 x Es Teh");
    });

    it("never prints a price", () => {
        const text = textOf(
            ticket({ items: [{ title: "Kopi", qty: 1, note: null, price: 25000, subtotal: 25000 }] })
        );
        expect(text).not.toMatch(/Rp|25000|25\.000|total/i);
    });

    // REQ-004 AC-03: an add-on order's own ticket.
    it.each([
        ["58mm", "TAMBAHAN"],
        ["80mm", "TAMBAHAN"],
        ["58mm", "ADD-ON"],
        ["80mm", "ADD-ON"],
    ])("marks an add-on ticket in double size under the number on %s with %s, without a price", (paper, addon) => {
        const add = ticket({ kind: "addon", items: [{ title: "Es Teh", qty: 1, note: null, price: 25000 }] });
        const text = textOf(add, paper, { ...labels, addon });

        expect(text.startsWith(`\x1b@\x1d!\x11Meja 5\n${addon}\n\x1d!\x00`)).toBe(true);
        expect(text).toContain("1 x Es Teh");
        expect(text).not.toMatch(/Rp|25000|25\.000|total/i);
    });

    it("keeps BELUM LUNAS on an unpaid add-on", () => {
        const text = textOf(ticket({ kind: "addon", unpaid: true }), "80mm", { ...labels, addon: "TAMBAHAN" });

        expect(text).toContain("TAMBAHAN");
        expect(text).toContain("BELUM LUNAS");
    });

    it("prints no add-on marker on an order or a cancellation ticket", () => {
        const all = { ...labels, addon: "TAMBAHAN", cancelled: "DIBATALKAN" };

        for (const kind of ["order", "cancel", undefined]) {
            expect(textOf(ticket({ kind }), "80mm", all)).not.toContain("TAMBAHAN");
        }
    });

    it.each([
        ["58mm", 32],
        ["80mm", 48],
    ])("wraps long titles and notes on %s and never cuts them", (paper, width) => {
        const title = "Nasi Goreng Spesial Dengan Telur Mata Sapi Dan Sambal Terasi Pedas";
        const note = "tolong jangan terlalu pedas dan tambah kerupuk yang banyak ya";
        const lines = kitchenLines(ticket({ items: [{ title, qty: 3, note }] }), paper);

        expect(lines.every((l) => l.length <= width)).toBe(true);
        // Every word of the item and the note is still there, in order.
        const words = lines.join(" ").split(/\s+/);
        let at = 0;
        for (const word of ["3", "x", ...title.split(" "), ...note.split(" ")]) {
            at = words.indexOf(word, at);
            expect(at).toBeGreaterThan(-1);
        }
    });

    it("splits a single word longer than the paper over lines instead of dropping it", () => {
        const lines = kitchenLines(
            ticket({ items: [{ title: "A".repeat(100), qty: 1, note: null }] }),
            "58mm"
        );
        expect(lines.every((l) => l.length <= 32)).toBe(true);
        expect(lines.join("").replace(/[^A]/g, "")).toHaveLength(100);
    });

    it("prints an order of 30 items as one ticket with every line", () => {
        const items = Array.from({ length: 30 }, (_, n) => ({
            title: `Menu ${n + 1}`,
            qty: 1,
            note: null,
        }));
        const lines = kitchenLines(ticket({ items }));
        for (let n = 1; n <= 30; n++) expect(lines).toContain(`1 x Menu ${n}`);
        expect(bytesOf(ticket({ items })).slice(-4)).toEqual([0x1d, 0x56, 0x42, 0x00]);
    });

    it("removes control characters so a guest note cannot send printer commands", () => {
        const kick = "\x1bp\x00\x19\xfa";
        const bytes = bytesOf(
            ticket({
                number: "M\x1b@5",
                items: [
                    {
                        title: "Es\x00 Teh\x7f",
                        qty: 1,
                        note: `manis${kick}\x1d!\x11\r\nbanyak\tes`,
                    },
                ],
            })
        );
        const text = new TextDecoder().decode(new Uint8Array(bytes));

        expect(text).toContain("1 x Es Teh");
        expect(text).toContain("manis");
        expect(text).toContain("banyak es");
        expect(text).not.toContain("\x7f");
        expect(text).not.toContain("\x1bp");
        // Only the builder's own commands remain: one ESC (init) and three GS (size on, off, cut).
        expect(bytes.filter((b) => b === 0x1b)).toHaveLength(1);
        expect(bytes.filter((b) => b === 0x1d)).toHaveLength(3);
        // No bytes below 0x20 other than those commands and the line feeds.
        expect(bytes.filter((b) => b < 0x20 && ![0x0a, 0x1b, 0x1d].includes(b))).toEqual([
            0x11, 0x00, 0x00,
        ]);
    });

    it("titles the ticket with the station name in double size, in capitals, under the number (#345)", () => {
        const text = textOf(ticket({ station: "Bar" }), "80mm");
        expect(text.startsWith(`\x1b@\x1d!\x11Meja 5\nBAR\n\x1d!\x00`)).toBe(true);
    });

    describe("cancel layout", () => {
        const cancelLabels = { ...labels, cancelled: "DIBATALKAN" };
        const cancel = (overrides = {}) =>
            ticket({
                kind: "cancel",
                unpaid: true,
                items: [{ id: 9, title: "Nasi Goreng", qty: 1, note: "tanpa pedas" }],
                ...overrides,
            });

        it("prints the cancelled label, then the number, both in double size, before the type and time", () => {
            // eslint-disable-next-line no-control-regex
            expect(textOf(cancel(), "80mm", cancelLabels)).toMatch(/^\x1b@\x1d!\x11DIBATALKAN\nMeja 5\n\x1d!\x00Makan di tempat/);
        });

        it("uses the cancelled label it is given, so the English device prints CANCELLED", () => {
            const text = textOf(cancel(), "80mm", { ...cancelLabels, cancelled: "CANCELLED" });
            expect(text).toContain("CANCELLED");
            expect(text).not.toContain("DIBATALKAN");
        });

        it("shows the type and the cancel time right aligned, and no unpaid line", () => {
            const lines = kitchenLines(cancel(), "80mm", cancelLabels);
            expect(lines.find((l) => l.startsWith("Makan di tempat"))).toHaveLength(48);
            expect(lines.find((l) => l.includes("14:07"))).toBeDefined();
            expect(lines.join("\n")).not.toContain("BELUM LUNAS");
        });

        it("prints the cancelled quantity, with the note on the following line", () => {
            const lines = kitchenLines(cancel({ items: [{ id: 9, title: "Nasi Goreng", qty: 1, note: "tanpa pedas" }] }), "80mm", cancelLabels);
            const i = lines.indexOf("1 x Nasi Goreng");
            expect(i).toBeGreaterThan(-1);
            expect(lines[i + 1].trim()).toBe("tanpa pedas");
            expect(lines).not.toContain("2 x Nasi Goreng");
        });

        it("never prints a price", () => {
            const text = textOf(
                cancel({ items: [{ id: 9, title: "Nasi", qty: 1, note: null, price: 25000, subtotal: 25000 }], total: 25000 }),
                "80mm",
                cancelLabels
            );
            expect(text).not.toMatch(/25\.?000|Rp/);
        });

        it("wraps and strips control characters like the original layout", () => {
            const bytes = bytesOf(
                cancel({ number: "M\x1b@5", items: [{ id: 9, title: "Es\x00 Teh", qty: 1, note: "manis\x1bp\x00\x19\xfa\x1d!\x11" }] }),
                "58mm",
                cancelLabels
            );
            expect(bytes.filter((b) => b === 0x1b)).toHaveLength(1);
            expect(bytes.filter((b) => b === 0x1d)).toHaveLength(3);
            const long = kitchenLines(cancel({ items: [{ id: 9, title: "x".repeat(40), qty: 1, note: null }] }), "58mm", cancelLabels);
            expect(Math.max(...long.map((l) => l.length))).toBeLessThanOrEqual(32);
        });

        it("starts with init and ends with the cut command", () => {
            const bytes = bytesOf(cancel(), "80mm", cancelLabels);
            expect(bytes.slice(0, 2)).toEqual([0x1b, 0x40]);
            expect(bytes.slice(-4)).toEqual([0x1d, 0x56, 0x42, 0x00]);
        });

        it("leaves the original layout untouched when kind is absent or not cancel", () => {
            const original = bytesOf(ticket(), "80mm", labels);
            expect(bytesOf(ticket({ kind: "original" }), "80mm", cancelLabels)).toEqual(original);
            expect(textOf(ticket(), "80mm", cancelLabels)).not.toContain("DIBATALKAN");
        });
    });

    describe("reprint layout", () => {
        const reprintLabels = { ...labels, reprint: "CETAK ULANG" };

        it("prints the reprint label, then the number, both in double size, before the type and time", () => {
            // eslint-disable-next-line no-control-regex
            expect(textOf(ticket({ reprint: true }), "80mm", reprintLabels)).toMatch(/^\x1b@\x1d!\x11CETAK ULANG\nMeja 5\n\x1d!\x00Makan di tempat/);
        });

        it("uses the label it is given, so the English device prints REPRINT", () => {
            const text = textOf(ticket({ reprint: true }), "80mm", { ...reprintLabels, reprint: "REPRINT" });
            expect(text).toContain("REPRINT");
            expect(text).not.toContain("CETAK ULANG");
        });

        it("keeps the unpaid line and the items of the original", () => {
            const lines = kitchenLines(ticket({ reprint: true, unpaid: true }), "80mm", { ...reprintLabels, unpaid: "BELUM LUNAS" });
            expect(lines).toContain("BELUM LUNAS");
            expect(lines.some((l) => l.includes("x "))).toBe(true);
        });

        it("splits a long label in double size like the cancelled label", () => {
            const lines = kitchenLines(ticket({ reprint: true }), "58mm", { ...reprintLabels, reprint: "CETAK ULANG TIKET" });
            expect(Math.max(...lines.slice(0, 3).map((l) => l.length))).toBeLessThanOrEqual(16);
        });

        it("leaves the bytes of a ticket without reprint untouched", () => {
            const original = bytesOf(ticket(), "80mm", labels);
            expect(bytesOf(ticket({ reprint: false }), "80mm", reprintLabels)).toEqual(original);
            expect(textOf(ticket(), "80mm", reprintLabels)).not.toContain("CETAK ULANG");
        });
    });
});

describe("printerKey and getPrinter", () => {
    const makeDevice = (vendorId, productId, serialNumber) => {
        const device = {
            vendorId,
            productId,
            serialNumber,
            opened: false,
            configuration: {
                interfaces: [
                    {
                        interfaceNumber: 0,
                        alternates: [
                            {
                                interfaceClass: 7,
                                endpoints: [{ direction: "out", endpointNumber: 2 }],
                            },
                        ],
                    },
                ],
            },
            open: vi.fn(async () => {
                device.opened = true;
            }),
            close: vi.fn(async () => {
                device.opened = false;
            }),
            selectConfiguration: vi.fn(),
            claimInterface: vi.fn(),
            transferOut: vi.fn().mockResolvedValue({ status: "ok" }),
        };
        return device;
    };
    const receipt = () => makeDevice(1, 10, "R1");
    const kitchen = () => makeDevice(2, 20, "K1");
    const grant = (...devices) =>
        vi.stubGlobal("navigator", { usb: { getDevices: vi.fn().mockResolvedValue(devices) } });

    afterEach(async () => {
        await disconnectPrinter();
        localStorage.clear();
        vi.unstubAllGlobals();
    });

    it("builds the key from vendor, product and serial", () => {
        expect(printerKey(receipt())).toBe("1:10:R1");
        expect(printerKey(makeDevice(2, 20, null))).toBe("2:20:");
    });

    it("uses the keyed device out of two granted ones", async () => {
        const [r, k] = [receipt(), kitchen()];
        grant(r, k);

        await printBytes(new Uint8Array([1]), { deviceKey: printerKey(k) });

        expect(k.transferOut).toHaveBeenCalledTimes(1);
        expect(r.transferOut).not.toHaveBeenCalled();
    });

    it("finds no printer when no granted device has the key", async () => {
        grant(receipt());
        expect(await getPrinter("9:9:")).toBeNull();
        await expect(printBytes(new Uint8Array([1]), { deviceKey: "9:9:" })).rejects.toThrow(
            "Printer belum terhubung."
        );
    });

    it("without a key skips the kitchen printer and takes the other one", async () => {
        const [k, r] = [kitchen(), receipt()];
        localStorage.setItem("kitchen.printerKey", printerKey(k));
        grant(k, r);

        await printBytes(new Uint8Array([1]));

        expect(r.transferOut).toHaveBeenCalledTimes(1);
        expect(k.transferOut).not.toHaveBeenCalled();
    });

    it("without a key and without a stored kitchen key takes the first device as before", async () => {
        const [a, b] = [receipt(), kitchen()];
        grant(a, b);

        expect(await getPrinter()).toBe(a);
    });

    it("with one device and no kitchen key prints as before", async () => {
        const only = receipt();
        grant(only);

        await printBytes(new Uint8Array([1]));
        expect(only.transferOut).toHaveBeenCalledTimes(1);
    });

    it("a single printer shared by receipts and kitchen tickets keeps working", async () => {
        const only = kitchen();
        localStorage.setItem("kitchen.printerKey", printerKey(only));
        grant(only);

        await printBytes(new Uint8Array([1]));

        expect(only.transferOut).toHaveBeenCalledTimes(1);
    });

    it("does not fall back to the kitchen printer when two devices are granted and the other is missing", async () => {
        const [k, k2] = [kitchen(), makeDevice(2, 20, "K1")];
        localStorage.setItem("kitchen.printerKey", printerKey(k));
        grant(k, k2);

        expect(await getPrinter()).toBeNull();
    });

    it("does not hand the cached kitchen printer to a receipt print", async () => {
        const [r, k] = [receipt(), kitchen()];
        localStorage.setItem("kitchen.printerKey", printerKey(k));
        grant(k, r);

        expect(await getPrinter(printerKey(k))).toBe(k);
        expect(await getPrinter()).toBe(r);
        expect(await getPrinter(printerKey(k))).toBe(k);
        expect(k.open).toHaveBeenCalledTimes(1);
    });
});
