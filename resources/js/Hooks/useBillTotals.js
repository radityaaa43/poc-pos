import { useEffect, useState } from "react";

/**
 * The checkout's service charge, tax and total, as the last preview computed them. `reconnects` lets the page
 * preview again when the network is back: a preview asked for while offline never arrived.
 */
export default function useBillTotals(summary) {
    const [reconnects, setReconnects] = useState(0);

    useEffect(() => {
        const count = () => setReconnects((times) => times + 1);
        window.addEventListener("online", count);

        return () => window.removeEventListener("online", count);
    }, []);

    return {
        service_charge: Number(summary?.service_charge ?? 0),
        tax_kind: summary?.tax_kind,
        tax_total: Number(summary?.tax_total ?? 0),
        tax_rate: summary?.tax_rate,
        grand_total: Number(summary?.grand_total ?? 0),
        reconnects,
    };
}
