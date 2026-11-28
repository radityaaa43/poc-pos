export default function SummaryCard({ title, value, description, icon, gradient }) {
    const Icon = icon;

    return (
        <div
            className={`relative overflow-hidden rounded-2xl p-5 bg-gradient-to-br ${gradient} text-white shadow-lg`}
        >
            <div className="relative z-10">
                <div className="mb-2 flex items-center gap-2">
                    <div className="rounded-xl bg-white/20 p-2">
                        <Icon size={18} />
                    </div>
                    <span className="text-sm font-medium opacity-90">
                        {title}
                    </span>
                </div>
                <p className="text-2xl font-bold">{value}</p>
                <p className="mt-1 text-sm opacity-80">{description}</p>
            </div>
        </div>
    );
}
