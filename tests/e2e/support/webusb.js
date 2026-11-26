// A fake WebUSB printer, so the ESC/POS bytes the app sends can be asserted without hardware
// (real-device printing is a Manual note in the feature folder's COVERAGE.md). Call before the first page.goto().
// `granted`: the printer was already allowed earlier, so navigator.usb.getDevices() lists it (the
// receipt page re-attaches this way); otherwise only requestDevice() (the Settings button) finds it.
export const fakePrinter = (page, { granted = false, fault = 'none' } = {}) => page.addInitScript(({ granted, fault }) => {
    window.__usbSent = [];
    // Fault switch (setPrinterFault): 'transfer' rejects transferOut, 'claim-network' rejects claimInterface, both with
    // a NetworkError DOMException like Chrome's. Unplugging (unplugPrinter) empties getDevices() and closes the device.
    window.__usbFault = fault;
    window.__usbPlugged = true;
    const device = {
        // Identity of a real USB printer: escpos.js printerKey() joins these (see PRINTER_KEY below).
        vendorId: 0x0483,
        productId: 0x5740,
        serialNumber: 'E2E-PRINTER',
        opened: false,
        configuration: { interfaces: [{ interfaceNumber: 0, alternates: [{ interfaceClass: 7, endpoints: [{ direction: 'out', endpointNumber: 1 }] }] }] },
        open: async () => { device.opened = true; },
        close: async () => { device.opened = false; },
        selectConfiguration: async () => {},
        claimInterface: async () => {
            if (window.__usbFault === 'claim-network') throw new DOMException('Unable to claim interface.', 'NetworkError');
        },
        transferOut: async (endpoint, data) => {
            if (window.__usbFault === 'transfer') throw new DOMException('A transfer error has occurred.', 'NetworkError');
            window.__usbSent.push(Array.from(new Uint8Array(data)));

            return { status: 'ok', bytesWritten: data.byteLength };
        },
    };
    window.__usbDevice = device;
    // An EventTarget like the real navigator.usb: the kitchen hook listens for connect and disconnect.
    const usb = new EventTarget();
    usb.requestDevice = async () => device;
    usb.getDevices = async () => (granted && window.__usbPlugged ? [device] : []);
    Object.defineProperty(navigator, 'usb', { configurable: true, value: usb });
}, { granted, fault });

// Flips the fault of the fake printer on the open page: 'transfer', 'claim-network' or 'none'.
export const setPrinterFault = (page, fault) => page.evaluate((value) => { window.__usbFault = value; }, fault);

// Pulls the cable: the device closes, getDevices() is empty and a `disconnect` event fires, as in Chrome.
export const unplugPrinter = (page) => page.evaluate(() => {
    window.__usbPlugged = false;
    window.__usbDevice.opened = false;
    const event = new Event('disconnect');
    event.device = window.__usbDevice;
    navigator.usb.dispatchEvent(event);
});

// Plugs it back: a granted printer is listed again and a `connect` event fires.
export const replugPrinter = (page) => page.evaluate(() => {
    window.__usbPlugged = true;
    const event = new Event('connect');
    event.device = window.__usbDevice;
    navigator.usb.dispatchEvent(event);
});

// A browser without WebUSB (iPad, Firefox, Safari): navigator.usb is undefined. Call before the first page.goto().
export const removeWebUsb = (page) => page.addInitScript(() => {
    Object.defineProperty(navigator, 'usb', { configurable: true, value: undefined });
});

// The page's user agent as the app reads it (navigator.userAgent), for the Windows driver-lock message.
// asRole() opens its own context, so test.use({ userAgent }) would not reach it. Call before the first page.goto().
export const WINDOWS_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
export const LINUX_UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
export const setUserAgent = (page, userAgent) => page.addInitScript((ua) => {
    Object.defineProperty(navigator, 'userAgent', { configurable: true, get: () => ua });
}, userAgent);

// A Wake Lock API that counts the requests and keeps a boolean `released` like the real sentinel (the app reads it).
// Call before the first page.goto(); read it with wakeLockState().
export const fakeWakeLock = (page) => page.addInitScript(() => {
    const lock = { requests: 0, sentinels: [] };
    window.__wakeLock = lock;
    Object.defineProperty(navigator, 'wakeLock', {
        configurable: true,
        value: {
            request: async () => {
                lock.requests += 1;
                const target = new EventTarget();
                const sentinel = {
                    released: false,
                    type: 'screen',
                    release: async () => {
                        if (sentinel.released) return;
                        sentinel.released = true;
                        target.dispatchEvent(new Event('release'));
                    },
                    addEventListener: (...args) => target.addEventListener(...args),
                    removeEventListener: (...args) => target.removeEventListener(...args),
                };
                lock.sentinels.push(sentinel);

                return sentinel;
            },
        },
    });
});

// A browser without the Wake Lock API: navigator.wakeLock is undefined. Call before the first page.goto().
export const removeWakeLock = (page) => page.addInitScript(() => {
    Object.defineProperty(navigator, 'wakeLock', { configurable: true, value: undefined });
});

// How often the page asked for the lock, and whether one is held now (needs fakeWakeLock).
export const wakeLockState = (page) => page.evaluate(() => ({
    requests: window.__wakeLock.requests,
    held: window.__wakeLock.sentinels.some((sentinel) => !sentinel.released),
}));

// What the page sent to the printer: one byte array per transferOut call.
export const sentToPrinter = (page) => page.evaluate(() => window.__usbSent);

// ESC/POS markers (resources/js/Utils/escpos.js).
export const INIT = [0x1b, 0x40];
export const CUT = [0x1d, 0x56, 0x42, 0x00];
export const DRAWER_KICK = [0x1b, 0x70, 0x00, 0x19, 0xfa];

// printerKey(device) of the fake printer, as the kitchen panel saves it in localStorage (kitchen.printerKey).
export const PRINTER_KEY = '1155:22336:E2E-PRINTER';

export const asText = (bytes) => String.fromCharCode(...bytes);
