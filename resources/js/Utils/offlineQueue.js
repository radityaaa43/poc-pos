import {
    claimPendingTransaction,
    getPendingTransactions,
    removePendingTransaction,
    updatePendingTransaction,
} from "@/Utils/offlineDb";

// Status writes of the offline sale queue, shared by the POS flush and the queue panel.

/** Fired on window after a status write, so an open queue panel re-reads. */
export const QUEUE_EVENT = "pos:offline-queue-changed";

const REMOVABLE = ["failed", "conflict", "discarding"];

const changed = () => window.dispatchEvent(new Event(QUEUE_EVENT));
const now = () => new Date().toISOString();

async function write(id, changes) {
    await updatePendingTransaction(id, changes);
    changed();
}

/** Marks every row the flush may send as syncing and returns them. A discarding row is never taken. */
export async function claimSendable(scopeKey) {
    const at = now();
    const claimed = await Promise.all(
        (await getPendingTransactions(scopeKey)).map((row) =>
            claimPendingTransaction(row.id, ["pending", "failed"], (current) => ({
                status: "syncing",
                attempts: current.attempts + 1,
                last_attempt_at: at,
            }))
        )
    );
    changed();

    return claimed.filter(Boolean);
}

export function markFailed(row, reason) {
    return write(row.id, { status: "failed", last_error: reason, last_attempt_at: now() });
}

/** The server refused the sale: keeps the row with the reason, which is returned. */
export async function markRefused(row, result) {
    const reason = result.reason || "kesalahan tidak diketahui";
    await write(row.id, {
        status: result.status === "conflict" ? "conflict" : "failed",
        last_error: reason,
        last_attempt_at: now(),
    });

    return reason;
}

/** The server has the sale: records that, then drops the row. */
export async function markSynced(row, status) {
    await updatePendingTransaction(row.id, {
        status: status === "pending_approval" ? "synced" : status,
        synced_at: now(),
        last_attempt_at: now(),
        last_error: null,
    });
    await removePendingTransaction(row.id);
    changed();
}

/** Takes a row for removal. Returns it as it was, or null when it can no longer be removed. */
export async function beginDiscard(id) {
    const row = await claimPendingTransaction(id, REMOVABLE, (current) => ({
        status: "discarding",
        discard_from: current.status === "discarding" ? current.discard_from : current.status,
    }));
    if (row) changed();

    return row;
}

export function restore(id, status) {
    return write(id, { status });
}

export async function finishDiscard(id) {
    await removePendingTransaction(id);
    changed();
}
