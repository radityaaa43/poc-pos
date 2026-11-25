// A fake WebUSB printer, so the ESC/POS bytes the app sends can be asserted without hardware
// (real-device printing is a Manual row in COVERAGE.md). Call before the first page.goto().
// `granted`: the printer was already allowed earlier, so navigator.usb.getDevices() lists it (the
// receipt page re-attaches this way); otherwise only requestDevice() (the Settings button) finds it.
export const fakePrinter = (page, { granted = false } = {}) => page.addInitScript((granted) => {
    window.__usbSent = [];
    const device = {
        opened: false,
        configuration: { interfaces: [{ interfaceNumber: 0, alternates: [{ interfaceClass: 7, endpoints: [{ direction: 'out', endpointNumber: 1 }] }] }] },
        open: async () => { device.opened = true; },
        close: async () => { device.opened = false; },
        selectConfiguration: async () => {},
        claimInterface: async () => {},
        transferOut: async (endpoint, data) => {
            window.__usbSent.push(Array.from(new Uint8Array(data)));

            return { status: 'ok', bytesWritten: data.byteLength };
        },
    };
    Object.defineProperty(navigator, 'usb', {
        configurable: true,
        value: { requestDevice: async () => device, getDevices: async () => (granted ? [device] : []) },
    });
}, granted);

// What the page sent to the printer: one byte array per transferOut call.
export const sentToPrinter = (page) => page.evaluate(() => window.__usbSent);

// ESC/POS markers (resources/js/Utils/escpos.js).
export const INIT = [0x1b, 0x40];
export const CUT = [0x1d, 0x56, 0x42, 0x00];
export const DRAWER_KICK = [0x1b, 0x70, 0x00, 0x19, 0xfa];

export const asText = (bytes) => String.fromCharCode(...bytes);
