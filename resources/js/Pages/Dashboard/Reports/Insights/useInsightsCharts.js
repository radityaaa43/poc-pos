import { useEffect, useMemo, useRef } from "react";
import Chart from "chart.js/auto";

export default function useInsightsCharts({ salesByHour, salesByDay }) {
    const salesHourChartRef = useRef(null);
    const salesHourChart = useRef(null);
    const salesDayChartRef = useRef(null);
    const salesDayChart = useRef(null);

    const hourChartData = useMemo(
        () => salesByHour.filter((item) => item.orders_count > 0 || item.revenue_total > 0),
        [salesByHour]
    );
    const dayChartData = useMemo(() => salesByDay, [salesByDay]);

    useEffect(() => {
        if (salesHourChart.current) {
            salesHourChart.current.destroy();
            salesHourChart.current = null;
        }
        if (!salesHourChartRef.current || !hourChartData.length) {
            return;
        }

        salesHourChart.current = new Chart(salesHourChartRef.current, {
            type: "bar",
            data: {
                labels: hourChartData.map((item) => item.label),
                datasets: [
                    {
                        label: "Omzet",
                        data: hourChartData.map((item) => item.revenue_total),
                        backgroundColor: "#3b82f6",
                        borderRadius: 8,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false,
                    },
                },
            },
        });

        return () => salesHourChart.current?.destroy();
    }, [hourChartData]);

    useEffect(() => {
        if (salesDayChart.current) {
            salesDayChart.current.destroy();
            salesDayChart.current = null;
        }
        if (!salesDayChartRef.current || !dayChartData.length) {
            return;
        }

        salesDayChart.current = new Chart(salesDayChartRef.current, {
            type: "line",
            data: {
                labels: dayChartData.map((item) => item.label),
                datasets: [
                    {
                        label: "Omzet",
                        data: dayChartData.map((item) => item.revenue_total),
                        borderColor: "#10b981",
                        backgroundColor: "rgba(16, 185, 129, 0.15)",
                        fill: true,
                        tension: 0.35,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false,
                    },
                },
            },
        });

        return () => salesDayChart.current?.destroy();
    }, [dayChartData]);

    return {
        salesHourChartRef,
        salesDayChartRef,
        hourChartData,
        dayChartData,
    };
}
