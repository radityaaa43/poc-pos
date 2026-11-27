import { afterEach, describe, expect, it, vi } from "vitest";
import { createPrintQueue } from "./printQueue";

afterEach(() => {
    vi.restoreAllMocks();
});

describe("createPrintQueue", () => {
    it("starts idle and empty", () => {
        const queue = createPrintQueue({ send: vi.fn() });
        expect(queue.status).toBe("idle");
        expect(queue.jobs).toEqual([]);
    });

    it("sends jobs in arrival order and empties the queue", async () => {
        const sent = [];
        const queue = createPrintQueue({ send: async (job) => sent.push(job.key) });
        queue.enqueue({ key: "a" });
        queue.enqueue({ key: "b" });
        queue.enqueue({ key: "c" });

        await queue.drain();

        expect(sent).toEqual(["a", "b", "c"]);
        expect(queue.jobs).toEqual([]);
        expect(queue.status).toBe("idle");
    });

    it("sends one job at a time and keeps a job until its send resolves", async () => {
        let running = 0;
        let maxRunning = 0;
        const releases = [];
        const send = vi.fn(async () => {
            running += 1;
            maxRunning = Math.max(maxRunning, running);
            await new Promise((resolve) => releases.push(resolve));
            running -= 1;
        });
        const queue = createPrintQueue({ send });
        queue.enqueue({ key: "a" });
        queue.enqueue({ key: "b" });

        const done = queue.drain();
        await Promise.resolve();
        expect(queue.status).toBe("printing");
        expect(send).toHaveBeenCalledTimes(1);
        expect(queue.jobs.map((j) => j.key)).toEqual(["a", "b"]);

        releases[0]();
        await vi.waitFor(() => expect(send).toHaveBeenCalledTimes(2));
        expect(queue.jobs.map((j) => j.key)).toEqual(["b"]);

        releases[1]();
        await done;
        expect(maxRunning).toBe(1);
        expect(queue.jobs).toEqual([]);
    });

    it("a second drain while one runs does not send anything twice", async () => {
        let release;
        const send = vi.fn(() => new Promise((resolve) => (release = resolve)));
        const queue = createPrintQueue({ send });
        queue.enqueue({ key: "a" });

        const first = queue.drain();
        const second = queue.drain();
        expect(send).toHaveBeenCalledTimes(1);

        release();
        await Promise.all([first, second]);
        expect(send).toHaveBeenCalledTimes(1);
        expect(queue.jobs).toEqual([]);
    });

    it("keeps a failed job at the head with every later job behind it", async () => {
        const warn = vi.spyOn(console, "warn").mockImplementation(() => {});
        const send = vi
            .fn()
            .mockResolvedValueOnce(undefined)
            .mockRejectedValueOnce(new Error("usb"));
        const queue = createPrintQueue({ send });
        ["a", "b", "c", "d"].forEach((key) => queue.enqueue({ key }));

        await queue.drain();

        expect(queue.status).toBe("failed");
        expect(queue.jobs.map((j) => j.key)).toEqual(["b", "c", "d"]);
        expect(send).toHaveBeenCalledTimes(2);
        expect(warn).toHaveBeenCalledWith(expect.stringContaining("b"), expect.anything());
    });

    it("retries the failed head on the next drain, then carries on in order", async () => {
        vi.spyOn(console, "warn").mockImplementation(() => {});
        const sent = [];
        let fail = true;
        const send = async (job) => {
            if (job.key === "a" && fail) throw new Error("usb");
            sent.push(job.key);
        };
        const queue = createPrintQueue({ send });
        queue.enqueue({ key: "a" });
        queue.enqueue({ key: "b" });

        await queue.drain();
        expect(sent).toEqual([]);
        expect(queue.status).toBe("failed");

        fail = false;
        await queue.drain();
        expect(sent).toEqual(["a", "b"]);
        expect(queue.jobs).toEqual([]);
        expect(queue.status).toBe("idle");
    });

    it("keeps a failed job failed while it fails again, without skipping it", async () => {
        vi.spyOn(console, "warn").mockImplementation(() => {});
        const send = vi.fn().mockRejectedValue(new Error("usb"));
        const queue = createPrintQueue({ send });
        queue.enqueue({ key: "a" });
        queue.enqueue({ key: "b" });

        await queue.drain();
        await queue.drain();

        expect(send.mock.calls.map(([job]) => job.key)).toEqual(["a", "a"]);
        expect(queue.jobs.map((j) => j.key)).toEqual(["a", "b"]);
        expect(queue.status).toBe("failed");
    });

    it("ignores a job whose key is already queued, even while it is being sent", async () => {
        let release;
        const send = vi.fn(() => new Promise((resolve) => (release = resolve)));
        const queue = createPrintQueue({ send });
        queue.enqueue({ key: "a", n: 1 });
        queue.enqueue({ key: "a", n: 2 });
        expect(queue.jobs).toHaveLength(1);

        const done = queue.drain();
        queue.enqueue({ key: "a", n: 3 });
        expect(queue.jobs).toHaveLength(1);

        release();
        await done;
        expect(send).toHaveBeenCalledTimes(1);
        expect(send.mock.calls[0][0].n).toBe(1);
    });

    it("accepts the same key again once its job has been printed", async () => {
        const send = vi.fn().mockResolvedValue(undefined);
        const queue = createPrintQueue({ send });
        queue.enqueue({ key: "a" });
        await queue.drain();
        queue.enqueue({ key: "a" });
        expect(queue.jobs).toHaveLength(1);
    });

    it("a job enqueued during a drain is sent after the earlier ones", async () => {
        const sent = [];
        let queue;
        const send = async (job) => {
            sent.push(job.key);
            if (job.key === "a") queue.enqueue({ key: "late" });
        };
        queue = createPrintQueue({ send });
        queue.enqueue({ key: "a" });
        queue.enqueue({ key: "b" });

        await queue.drain();

        expect(sent).toEqual(["a", "b", "late"]);
    });

    it("exposes a copy of the jobs", () => {
        const queue = createPrintQueue({ send: vi.fn() });
        queue.enqueue({ key: "a" });
        queue.jobs.pop();
        expect(queue.jobs).toHaveLength(1);
    });
    it.each([0, 1, 2, 3, 4])(
        "a drain right after the last job (%i ticks later) sends a new job at once and never two at a time",
        async (ticks) => {
            const sent = [];
            let inFlight = 0;
            let maxInFlight = 0;
            let second;
            let queue;
            const send = async (job) => {
                inFlight += 1;
                maxInFlight = Math.max(maxInFlight, inFlight);
                sent.push(job.key);
                await Promise.resolve();
                inFlight -= 1;
                if (job.key === "a") {
                    // The caller enqueues and drains again a few ticks after the last job resolved.
                    let chain = Promise.resolve();
                    for (let i = 0; i < ticks; i += 1) chain = chain.then(() => undefined);
                    chain.then(() => {
                        queue.enqueue({ key: "b" });
                        second = queue.drain();
                    });
                }
            };
            queue = createPrintQueue({ send });
            queue.enqueue({ key: "a" });

            await queue.drain();
            await new Promise((resolve) => setTimeout(resolve, 0));
            await second;

            expect(sent).toEqual(["a", "b"]);
            expect(maxInFlight).toBe(1);
            expect(queue.jobs).toEqual([]);
            expect(queue.status).toBe("idle");
        }
    );

    it("starts a fresh run for a drain on an empty queue and again after a failed head is retried", async () => {
        const send = vi.fn().mockRejectedValueOnce(new Error("usb")).mockResolvedValue(undefined);
        vi.spyOn(console, "warn").mockImplementation(() => {});
        const queue = createPrintQueue({ send });

        await queue.drain();
        expect(queue.status).toBe("idle");

        queue.enqueue({ key: "a" });
        await queue.drain();
        expect(queue.status).toBe("failed");
        expect(queue.jobs.map((job) => job.key)).toEqual(["a"]);

        await queue.drain();
        expect(queue.status).toBe("idle");
        expect(queue.jobs).toEqual([]);
    });
});

describe("createPrintQueue: remove and replace (reconcile)", () => {
    const gate = () => {
        const releases = [];
        const sent = [];
        const send = vi.fn(async (job) => {
            await new Promise((resolve) => releases.push(resolve));
            sent.push(job.key);
        });
        return { releases, sent, send };
    };

    it("keeps the kind on the job and stays kind-agnostic", async () => {
        const seen = [];
        const queue = createPrintQueue({ send: async (job) => seen.push([job.key, job.kind]) });
        queue.enqueue({ key: "a", kind: "cancel" });
        queue.enqueue({ key: "b" });
        await queue.drain();
        expect(seen).toEqual([["a", "cancel"], ["b", undefined]]);
    });

    it("a removed job never prints and the others keep their order", async () => {
        const sent = [];
        const queue = createPrintQueue({ send: async (job) => sent.push(job.key) });
        queue.enqueue({ key: "a" });
        queue.enqueue({ key: "b" });
        queue.enqueue({ key: "c" });

        expect(queue.remove("b")).toBe(true);
        await queue.drain();

        expect(sent).toEqual(["a", "c"]);
    });

    it("removing an unknown key does nothing", () => {
        const queue = createPrintQueue({ send: vi.fn() });
        queue.enqueue({ key: "a" });
        expect(queue.remove("zzz")).toBe(false);
        expect(queue.jobs.map((j) => j.key)).toEqual(["a"]);
    });

    it("the head that is sending cannot be removed or replaced, but a job behind it can", async () => {
        const { releases, sent, send } = gate();
        const queue = createPrintQueue({ send });
        queue.enqueue({ key: "a" });
        queue.enqueue({ key: "b" });
        const done = queue.drain();
        await Promise.resolve();

        expect(queue.remove("a")).toBe(false);
        expect(queue.replace("a", { key: "a", v: 2 })).toBe(false);
        expect(queue.jobs[0]).toEqual({ key: "a" });
        expect(queue.replace("b", { key: "b", v: 2 })).toBe(true);

        releases[0]();
        await vi.waitFor(() => expect(releases).toHaveLength(2));
        releases[1]();
        await done;
        expect(sent).toEqual(["a", "b"]);
        expect(send.mock.calls[1][0]).toEqual({ key: "b", v: 2 });
    });

    it("a failed head can be removed, and the queue moves on to the next job", async () => {
        vi.spyOn(console, "warn").mockImplementation(() => {});
        const sent = [];
        const send = vi.fn(async (job) => {
            if (job.key === "a") throw new Error("usb");
            sent.push(job.key);
        });
        const queue = createPrintQueue({ send });
        queue.enqueue({ key: "a" });
        queue.enqueue({ key: "b" });
        await queue.drain();
        expect(queue.status).toBe("failed");

        expect(queue.remove("a")).toBe(true);
        await queue.drain();

        expect(sent).toEqual(["b"]);
        expect(queue.jobs).toEqual([]);
    });

    it("a failed head can be replaced, keeps its place and prints the new job", async () => {
        vi.spyOn(console, "warn").mockImplementation(() => {});
        const sent = [];
        let fail = true;
        const queue = createPrintQueue({
            send: async (job) => {
                if (fail) throw new Error("usb");
                sent.push(job);
            },
        });
        queue.enqueue({ key: "a", v: 1 });
        queue.enqueue({ key: "b" });
        await queue.drain();

        expect(queue.replace("a", { key: "a", v: 2 })).toBe(true);
        fail = false;
        await queue.drain();

        expect(sent).toEqual([{ key: "a", v: 2 }, { key: "b" }]);
    });

    it("replacing an unknown key does nothing", () => {
        const queue = createPrintQueue({ send: vi.fn() });
        expect(queue.replace("zzz", { key: "zzz" })).toBe(false);
        expect(queue.jobs).toEqual([]);
    });

    it("a key can be queued again after it was removed", async () => {
        const sent = [];
        const queue = createPrintQueue({ send: async (job) => sent.push(job.v) });
        queue.enqueue({ key: "a", v: 1 });
        queue.remove("a");
        queue.enqueue({ key: "a", v: 2 });
        await queue.drain();
        expect(sent).toEqual([2]);
    });
});
