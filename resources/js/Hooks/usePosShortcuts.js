import { useEffect, useLayoutEffect, useRef } from "react";

/**
 * The POS keyboard shortcuts: one window listener for the page's life that always calls the handler of the latest
 * render, so a key acts on what the screen shows (#499: F2 paid with the state of an old registration).
 */
export default function usePosShortcuts(handler) {
    const latest = useRef(handler);

    useLayoutEffect(() => {
        latest.current = handler;
    });

    useEffect(() => {
        const onKeyDown = (event) => latest.current(event);

        window.addEventListener("keydown", onKeyDown);
        return () => window.removeEventListener("keydown", onKeyDown);
    }, []);
}
