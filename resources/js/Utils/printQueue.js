// Serial FIFO print queue. A job leaves the queue only after `send` resolves; a failed job stays at the
// head and stops the queue, so a ticket is never skipped, reordered or printed twice (kitchen BR-7/BR-8).
// Memory only: jobs are rebuilt from the server's pending list after a reload.
// Jobs are opaque here (the caller's `send` reads `kind` and the rest); only `key` matters to the queue.
export function createPrintQueue({ send }) {
    const jobs = [];
    let status = "idle";
    let running = null;

    // `release` clears the in-flight marker right before run() returns, so a drain() called at once after
    // the last job (even in the same tick) starts a new run instead of joining one that is already over.
    async function run(release) {
        while (jobs.length > 0) {
            status = "printing";
            try {
                await send(jobs[0]);
            } catch (error) {
                status = "failed";
                console.warn(`Print job ${jobs[0].key} failed`, error);
                release();
                return;
            }
            jobs.shift();
        }
        status = "idle";
        release();
    }

    // Only the head can be in flight; a failed head is idle, so reconcile may drop or replace it.
    const indexOfIdle = (key) => {
        const at = jobs.findIndex((job) => job.key === key);
        return at === 0 && status === "printing" ? -1 : at;
    };

    return {
        enqueue(job) {
            if (!jobs.some((queued) => queued.key === job.key)) jobs.push(job);
        },
        // Reconcile helpers: false when the key is unknown or its job is the one sending right now.
        remove(key) {
            const at = indexOfIdle(key);
            if (at < 0) return false;
            jobs.splice(at, 1);
            return true;
        },
        replace(key, job) {
            const at = indexOfIdle(key);
            if (at < 0) return false;
            jobs[at] = job;
            return true;
        },
        // A drain already in flight is returned as is: no second sender can start.
        drain() {
            if (running) return running;
            let released = false;
            const current = run(() => {
                released = true;
                running = null;
            });
            // A run that ended before it could be stored (an empty queue) must not leave the marker set.
            running = released ? null : current;
            return current;
        },
        get jobs() {
            return [...jobs];
        },
        get status() {
            return status;
        },
    };
}
