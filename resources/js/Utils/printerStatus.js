// Pure helpers for the kitchen printer's health (REQ-004 K2): error codes, the one banner status, and
// the "Belum tercetak" set. No React and no i18n here; the banner and the panel pick the words.

// A ticket still unprinted after this long is "Belum tercetak" (10 s SLA + one 5 s poll + a margin).
export const STALE_PRINT_MS = 30000;
// The stale banner stays this long after the condition cleared, or it would flash while the backlog prints.
export const STALE_HOLD_MS = 15000;

// escpos.js throws errors with a string `code` (unsupported, not_connected, claim_failed, transfer) and
// the browser's DOMException as `cause`. A raw DOMException has a numeric `code`, which is ignored here.
// driver_locked is a heuristic: on Windows a printer on its built-in driver fails to claim with NetworkError.
export function classifyPrinterError(error, userAgent = "") {
    const code = typeof error?.code === "string" ? error.code : undefined;
    if (code === "unsupported" || code === "not_connected") return code;
    const name = (error?.cause ?? error)?.name;
    if (name === "NotFoundError") return "not_connected";
    if (code !== "transfer" && (name === "NetworkError" || name === "SecurityError")) {
        return userAgent.includes("Windows") ? "driver_locked" : "claim_failed";
    }
    return code ?? "transfer";
}

// 'ok', or why WebUSB is out: no navigator.usb (iPad, Firefox, Safari) or a page that is not a secure context.
export function webUsbSupport() {
    if (typeof navigator === "undefined" || !navigator.usb) return "unsupported";
    return window.isSecureContext === false ? "insecure" : "ok";
}

// shortcut: `since` is the server's time, compared with the device's clock; send the age if shop clocks drift.
const olderThan = (since, now) => now - Date.parse(since) > STALE_PRINT_MS;

export const isStale = (unprinted, now) => unprinted.some((entry) => olderThan(entry.since, now));

// A card's chip: its ticket failed, or it has waited longer than the print SLA.
export const isNotPrinted = (entry, now) => Boolean(entry) && (entry.state === "failed" || olderThan(entry.since, now));

// Queued original tickets as {key, since, state}; cancel and manual (reprint) jobs are not "not printed".
// Only the failed head carries a lastError, so a job is 'failed' exactly when the queue stopped on it.
export function unprintedFromJobs(jobs) {
    return jobs
        .filter((job) => job.kind !== "cancel" && !job.manual)
        .map((job) => ({ key: job.key, since: job.ticket.since, state: job.lastError ? "failed" : "queued" }));
}

// One status at a time, highest priority first. `unsupported` is for the settings panel only (the page
// shows no banner for it). Everything but unsupported and a driver lock needs auto-print on, so a display
// that never prints stays quiet. `staleAt` is when the stale condition last held (the hook keeps it).
export function derivePrinterStatus({ supported, autoPrint, errorCode, disconnected, unprinted, wakeLock, now, staleAt }) {
    if (!supported) return "unsupported";
    if (errorCode === "driver_locked") return "driver_locked";
    if (!autoPrint) return null;
    if (errorCode === "not_connected" || disconnected) return "not_connected";
    if (errorCode) return "failed";
    if (isStale(unprinted, now) || (staleAt != null && now - staleAt < STALE_HOLD_MS)) return "stale";
    if (["unsupported", "refused", "released"].includes(wakeLock)) return "wake_lock_off";
    return null;
}
