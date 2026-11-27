import { openDB } from "idb";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
    cacheCustomers,
    cacheProducts,
    clearPendingTransactions,
    getCachedCustomers,
    getCachedProducts,
    getPendingCount,
    getPendingTransactions,
    getUnsyncedCount,
    pruneExpiredPendingTransactions,
    queueTransaction,
    removePendingTransaction,
    updatePendingTransaction,
} from "./offlineDb";

const NOW = new Date("2026-06-15T12:00:00.000Z");
const minutesAgo = (m) => new Date(NOW.getTime() - m * 60 * 1000).toISOString();
const daysAgo = (d) => minutesAgo(d * 24 * 60);

// Writes a row as-is, bypassing queueTransaction, to control timestamps / legacy shape.
async function putRaw(row) {
    const db = await openDB("pos-offline", 2);
    const id = await db.add("pending_transactions", row);
    db.close();
    return id;
}

beforeEach(async () => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(NOW);
    await clearPendingTransactions();
});

afterEach(() => {
    vi.useRealTimers();
});

describe("queueTransaction", () => {
    it("stores a normalized pending record", async () => {
        await queueTransaction({ client_uuid: "u-1", total: 5 }, "user1:wh1");

        const [row] = await getPendingTransactions("user1:wh1");
        expect(row).toMatchObject({
            status: "pending",
            attempts: 0,
            client_uuid: "u-1",
            scope_key: "user1:wh1",
            last_error: null,
            data: { client_uuid: "u-1", total: 5 },
        });
        expect(row.created_at).toBe(NOW.toISOString());
    });
});

describe("getPendingTransactions", () => {
    it("filters by scope and keeps legacy rows", async () => {
        await queueTransaction({ client_uuid: "a" }, "user1:wh1");
        await queueTransaction({ client_uuid: "b" }, "user2:wh1");
        await putRaw({ data: { client_uuid: "legacy" } });

        const rows = await getPendingTransactions("user1:wh1");

        expect(rows.map((r) => r.client_uuid).sort()).toEqual(["a", "legacy"]);
    });

    it("reports stale syncing rows as failed", async () => {
        await putRaw({ data: {}, scope_key: "s", status: "syncing", last_attempt_at: minutesAgo(6) });
        await putRaw({ data: {}, scope_key: "s", status: "syncing", last_attempt_at: minutesAgo(1) });

        const rows = await getPendingTransactions("s");
        const stale = rows.find((r) => r.last_attempt_at === minutesAgo(6));
        const fresh = rows.find((r) => r.last_attempt_at === minutesAgo(1));

        expect(stale.status).toBe("failed");
        expect(stale.last_error).toBe("Sync sebelumnya terhenti.");
        expect(fresh.status).toBe("syncing");
    });
});

describe("getPendingCount", () => {
    it("ignores synced and duplicate rows", async () => {
        for (const status of ["pending", "failed", "synced", "duplicate"]) {
            await putRaw({ data: {}, scope_key: "s", status });
        }

        expect(await getPendingCount("s")).toBe(2);
    });
});

describe("getUnsyncedCount", () => {
    it("counts one cashier's unsent rows whatever the warehouse part of the key, legacy rows included", async () => {
        for (const [scope_key, status] of [["5:none", "pending"], ["5:3", "failed"], [null, "syncing"], ["5:3", "conflict"], ["5:3", "synced"], ["51:none", "pending"]]) {
            await putRaw({ data: {}, scope_key, status });
        }

        expect(await getUnsyncedCount(5)).toBe(3);
    });
});

describe("pruneExpiredPendingTransactions", () => {
    it("only removes old failed and conflict rows", async () => {
        const make = (status, days) => putRaw({ data: { status, days }, scope_key: "s", status, created_at: daysAgo(days) });
        await make("failed", 31);
        await make("conflict", 31);
        await make("failed", 29);
        await make("pending", 40);
        await make("syncing", 40);

        await pruneExpiredPendingTransactions(30);

        const rows = await getPendingTransactions("s");
        expect(rows.map((r) => `${r.status}:${r.data.days}`).sort()).toEqual([
            "failed:29",
            "pending:40",
            "syncing:40",
        ]);
    });
});

describe("updatePendingTransaction", () => {
    it("merges changes and keeps data", async () => {
        const id = await queueTransaction({ client_uuid: "u", total: 1 }, "s");

        await updatePendingTransaction(id, { status: "synced", attempts: 1 });

        const [row] = await getPendingTransactions("s");
        expect(row).toMatchObject({ status: "synced", attempts: 1, data: { client_uuid: "u", total: 1 } });
    });

    it("resolves without changes for an unknown id", async () => {
        await queueTransaction({ client_uuid: "u" }, "s");

        await expect(updatePendingTransaction(9999, { status: "synced" })).resolves.toBeUndefined();

        const rows = await getPendingTransactions("s");
        expect(rows).toHaveLength(1);
        expect(rows[0].status).toBe("pending");
    });
});

describe("removing rows", () => {
    it("removePendingTransaction deletes one row", async () => {
        const id = await queueTransaction({ client_uuid: "a" }, "s");
        await queueTransaction({ client_uuid: "b" }, "s");

        await removePendingTransaction(id);

        const rows = await getPendingTransactions("s");
        expect(rows.map((r) => r.client_uuid)).toEqual(["b"]);
    });

    it("clearPendingTransactions empties the queue", async () => {
        await queueTransaction({ client_uuid: "a" }, "s");
        await queueTransaction({ client_uuid: "b" }, "s");

        await clearPendingTransactions();

        expect(await getPendingTransactions("s")).toEqual([]);
    });
});

describe("catalog caches", () => {
    it("cacheProducts round-trips and overwrites by id", async () => {
        await cacheProducts([{ id: 1, name: "A" }]);
        await cacheProducts([{ id: 1, name: "B" }, { id: 2, name: "C" }]);

        const products = await getCachedProducts();
        expect(products).toHaveLength(2);
        expect(products.find((p) => p.id === 1).name).toBe("B");
    });

    it("cacheCustomers round-trips and overwrites by id", async () => {
        await cacheCustomers([{ id: 1, name: "A" }]);
        await cacheCustomers([{ id: 1, name: "B" }, { id: 2, name: "C" }]);

        const customers = await getCachedCustomers();
        expect(customers).toHaveLength(2);
        expect(customers.find((c) => c.id === 1).name).toBe("B");
    });
});
