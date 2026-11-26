import { useEffect, useState } from "react";

// Keeps the screen on while `enabled` (kitchen auto-print on). The browser drops the lock whenever the tab is
// hidden, so it is requested again when the page turns visible. Without it a sleeping tablet stops printing.
// State: pending (not answered yet) | held | unsupported | refused | released.
export function useWakeLock(enabled) {
    const [state, setState] = useState(enabled ? "pending" : "released");

    useEffect(() => {
        if (!enabled) {
            setState("released");
            return undefined;
        }
        if (!navigator.wakeLock) {
            setState("unsupported");
            return undefined;
        }
        setState("pending");
        let cancelled = false;
        let requesting = false;
        let sentinel = null;

        const acquire = async () => {
            if (requesting || document.visibilityState !== "visible" || sentinel?.released === false) return;
            requesting = true;
            try {
                const lock = await navigator.wakeLock.request("screen");
                if (cancelled) {
                    lock.release();
                    return;
                }
                sentinel = lock;
                // A hidden page loses the lock by design: stay "held" until it is visible and asks again.
                lock.addEventListener("release", () => {
                    if (!cancelled && document.visibilityState === "visible") setState("released");
                });
                setState("held");
            } catch {
                if (!cancelled) setState("refused");
            } finally {
                requesting = false;
            }
        };
        const onVisibility = () => acquire();

        document.addEventListener("visibilitychange", onVisibility);
        acquire();
        return () => {
            cancelled = true;
            document.removeEventListener("visibilitychange", onVisibility);
            sentinel?.release();
        };
    }, [enabled]);

    return state;
}
