import { useEffect, useState } from "react";
import { useOnlineStatus } from "@/Context/OnlineStatusContext";
import { computeBill } from "@/Utils/billTotals";

const cacheKey = (outletId) => `pos.billSettings.${outletId}`;

function cachedSettings(outletId) {
    try {
        return JSON.parse(localStorage.getItem(cacheKey(outletId)));
    } catch {
        return null; // blocked or unreadable storage: the page's own settings still apply
    }
}

/**
 * The checkout's service charge, tax and total. Online they are the preview's. Offline the preview cannot
 * refresh, so they are recomputed from its last basis and the outlet's cached bill settings; the server computes
 * the stored total again at sync (REQ-010 BR-3). `reconnects` lets the page preview again when the network is back.
 */
export default function useBillTotals(summary, { orderType, shipping, outlet }) {
    const online = useOnlineStatus();
    const [reconnects, setReconnects] = useState(0);
    const outletId = outlet?.id;
    const settingsJson = outlet?.bill_settings ? JSON.stringify(outlet.bill_settings) : null;

    // Written only while online: a page reloaded offline may be an older cached one.
    useEffect(() => {
        if (!online || !settingsJson) return;
        try {
            localStorage.setItem(cacheKey(outletId), settingsJson);
        } catch {
            // no storage: a reload while offline uses the cached page's settings
        }
    }, [online, outletId, settingsJson]);

    useEffect(() => {
        const count = () => setReconnects((times) => times + 1);
        window.addEventListener("online", count);

        return () => window.removeEventListener("online", count);
    }, []);

    const basis = summary?.bill_basis;
    const settings = !online && basis && outletId ? (cachedSettings(outletId) ?? outlet.bill_settings) : null;

    if (settings) {
        // The shipping is the one on screen: it can be typed, or cleared with the order type, while offline.
        const current = { ...basis, shipping, shipping_tax: Math.round((shipping * basis.effective_rate) / 100) };

        return { ...computeBill(current, settings, orderType === "in_store"), reconnects };
    }

    return {
        service_charge: Number(summary?.service_charge ?? 0),
        tax_kind: summary?.tax_kind,
        tax_total: Number(summary?.tax_total ?? 0),
        tax_rate: summary?.tax_rate,
        grand_total: Number(summary?.grand_total ?? 0),
        reconnects,
    };
}
