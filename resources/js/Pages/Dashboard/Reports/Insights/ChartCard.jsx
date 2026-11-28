import EmptyState from "./EmptyState";

export default function ChartCard({ title, subtitle, chartRef, hasData }) {
    return (
        <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <div className="mb-4">
                <h2 className="text-lg font-semibold text-slate-900 dark:text-white">
                    {title}
                </h2>
                <p className="text-sm text-slate-500 dark:text-slate-400">
                    {subtitle}
                </p>
            </div>
            {hasData ? (
                <div className="h-72">
                    <canvas ref={chartRef} />
                </div>
            ) : (
                <EmptyState message="Belum ada data untuk periode ini." />
            )}
        </div>
    );
}
