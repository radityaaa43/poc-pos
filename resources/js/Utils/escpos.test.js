import { afterEach, describe, expect, it, vi } from "vitest";
import {
    buildReceiptBytes,
    disconnectPrinter,
    drawerKickBytes,
    hasCachedPrinter,
    printBytes,
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
});
