import { IconDatabaseOff } from "@tabler/icons-react";

export default function EmptyState({ message }) {
    return (
        <div className="flex h-40 items-center justify-center">
            <div className="text-center">
                <div className="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800">
                    <IconDatabaseOff size={24} className="text-slate-400" />
                </div>
                <p className="text-sm text-slate-500 dark:text-slate-400">
                    {message}
                </p>
            </div>
        </div>
    );
}
