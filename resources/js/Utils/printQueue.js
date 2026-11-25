// Serial FIFO print queue. A job leaves the queue only after `send` resolves; a failed job stays at the
// head and stops the queue, so a ticket is never skipped, reordered or printed twice (kitchen BR-7/BR-8).
// Memory only: jobs are rebuilt from the server's pending list after a reload.
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

    return {
        enqueue(job) {
            if (!jobs.some((queued) => queued.key === job.key)) jobs.push(job);
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
