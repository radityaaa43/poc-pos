import { beforeEach, describe, expect, it } from "vitest";
import {
    clearPendingTransactions,
    getPendingTransactions,
    getUnsyncedCount,
    queueTransaction,
} from "./offlineDb";
import {
    beginDiscard,
    claimSendable,
    finishDiscard,
    markFailed,
    markRefused,
    markSynced,
    restore,
} from "./offlineQueue";

const SCOPE = "5:none";
const queue = (uuid, meta = {}) => queueTransaction({ client_uuid: uuid }, SCOPE, meta);
const rows = () => getPendingTransactions(SCOPE);
const row = async (id) => (await rows()).find((item) => item.id === id);
const minutesAgo = (m) => new Date(Date.now() - m * 60 * 1000).toISOString();

beforeEach(() => clearPendingTransactions());

describe("claimSendable", () => {
    it("takes pending, failed and stalled syncing rows and marks them syncing", async () => {
        const pending = await queue("a");
        const failed = await queue("b", { status: "failed", attempts: 2 });
        const stalled = await queue("c", { status: "syncing", attempts: 1, last_attempt_at: minutesAgo(10) });

        const claimed = await claimSendable(SCOPE);

        expect(claimed.map((item) => item.id)).toEqual([pending, failed, stalled]);
        expect(claimed[0].data).toEqual({ client_uuid: "a" });
        expect(await row(failed)).toMatchObject({ status: "syncing", attempts: 3 });
        expect(Date.parse((await row(pending)).last_attempt_at)).toBeGreaterThan(Date.now() - 5000);
    });

    it("flush filter ignores discarding rows", async () => {
        const discarding = await queue("a", { status: "discarding" });
        await queue("b", { status: "conflict" });
        await queue("c", { status: "syncing", last_attempt_at: minutesAgo(1) });

        expect(await claimSendable(SCOPE)).toEqual([]);
        expect((await row(discarding)).status).toBe("discarding");
    });

    it("leaves another cashier's rows alone", async () => {
        await queueTransaction({ client_uuid: "a" }, "9:none");

        expect(await claimSendable(SCOPE)).toEqual([]);
    });
});

describe("flush results", () => {
    it("markFailed keeps the row with the reason", async () => {
        const id = await queue("a", { status: "syncing" });

        await markFailed({ id }, "Server tidak tersedia.");

        expect(await row(id)).toMatchObject({ status: "failed", last_error: "Server tidak tersedia." });
    });

    it.each([
        [{ status: "failed", reason: "Stok habis." }, "failed", "Stok habis."],
        [{ status: "conflict", reason: "UUID sudah dipakai." }, "conflict", "UUID sudah dipakai."],
        [{ status: "unknown" }, "failed", "kesalahan tidak diketahui"],
    ])("markRefused stores %o", async (result, status, reason) => {
        const id = await queue("a", { status: "syncing" });

        expect(await markRefused({ id }, result)).toBe(reason);
        expect(await row(id)).toMatchObject({ status, last_error: reason });
    });

    it.each(["synced", "pending_approval", "duplicate"])("markSynced removes a %s row", async (status) => {
        const id = await queue("a", { status: "syncing" });

        await markSynced({ id }, status);

        expect(await rows()).toEqual([]);
    });
});

describe("discard", () => {
    it.each(["failed", "conflict"])("claims a %s row and remembers where it came from", async (status) => {
        const id = await queue("a", { status });

        expect(await beginDiscard(id)).toMatchObject({ id, status });
        expect(await row(id)).toMatchObject({ status: "discarding", discard_from: status });
        // Still holds the shift close until it is gone.
        expect(await getUnsyncedCount(5)).toBe(1);
    });

    it("keeps the origin when a discarding row is removed again", async () => {
        const id = await queue("a", { status: "discarding", discard_from: "failed" });

        expect(await beginDiscard(id)).toMatchObject({ status: "discarding" });
        expect((await row(id)).discard_from).toBe("failed");
    });

    it.each([
        ["pending", {}],
        ["fresh syncing", { status: "syncing", last_attempt_at: minutesAgo(1) }],
    ])("refuses a %s row", async (_name, meta) => {
        const id = await queue("a", meta);

        expect(await beginDiscard(id)).toBeNull();
        expect((await row(id)).status).toBe(meta.status ?? "pending");
    });

    it("refuses a row that is gone", async () => {
        expect(await beginDiscard(999)).toBeNull();
        expect(await rows()).toEqual([]);
    });

    it("never lets a flush and a removal take the same row", async () => {
        const id = await queue("a", { status: "failed" });

        const [claimed, removal] = await Promise.all([claimSendable(SCOPE), beginDiscard(id)]);

        expect(claimed.length + (removal ? 1 : 0)).toBe(1);
        expect((await row(id)).status).toBe(removal ? "discarding" : "syncing");
    });

    it("restore puts the previous status back", async () => {
        const id = await queue("a", { status: "conflict" });
        const previous = await beginDiscard(id);

        await restore(id, previous.status);

        expect((await row(id)).status).toBe("conflict");
    });

    it("finishDiscard removes the row so it no longer holds the close", async () => {
        const id = await queue("a", { status: "failed" });
        await beginDiscard(id);

        await finishDiscard(id);

        expect(await rows()).toEqual([]);
        expect(await getUnsyncedCount(5)).toBe(0);
    });
});
