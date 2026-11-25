// Serial FIFO print queue. A job leaves the queue only after `send` resolves; a failed job stays at the
// head and stops the queue, so a ticket is never skipped, reordered or printed twice (kitchen BR-7/BR-8).
// Memory only: jobs are rebuilt from the server's pending list after a reload.
export function createPrintQueue({ send }) {
    const jobs = [];
    let status = "idle";
    let running = null;

    async function run() {
        while (jobs.length > 0) {
            status = "printing";
            try {
                await send(jobs[0]);
            } catch (error) {
                status = "failed";
                console.warn(`Print job ${jobs[0].key} failed`, error);
                return;
            }
            jobs.shift();
        }
        status = "idle";
    }

    return {
        enqueue(job) {
            if (!jobs.some((queued) => queued.key === job.key)) jobs.push(job);
        },
        // A drain already in flight is returned as is: no second sender can start.
        drain() {
            running ??= run().finally(() => {
                running = null;
            });
            return running;
        },
        get jobs() {
            return [...jobs];
        },
        get status() {
            return status;
        },
    };
}
