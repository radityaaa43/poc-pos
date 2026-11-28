import React, { useState } from "react";
import Modal from "@/Components/Dashboard/Modal";

const rupiah = (value) => `Rp ${Number(value || 0).toLocaleString("id-ID")}`;

// Exactly one: a radio swaps the choice. Anything else is checkboxes, locked once the maximum is ticked.
const pickOne = (group) => group.min_select === 1 && group.max_select === 1;

// Presentational (REQ-008 AC-06..AC-09), so the QR menu can reuse it: texts come in `labels`.
export default function ModifierPanel({ title, basePrice, groups, selected, labels, onAdd, onClose, processing = false }) {
    const [ids, setIds] = useState(selected);
    const [note, setNote] = useState("");
    const chosen = (group) => group.options.filter((option) => ids.includes(option.id));
    const price = groups.flatMap(chosen).reduce((sum, option) => sum + option.price_delta, Number(basePrice));
    const incomplete = groups.filter((group) => chosen(group).length < group.min_select).map((group) => group.name);

    const toggle = (group, id) =>
        setIds((current) => {
            if (pickOne(group)) {
                return [...current.filter((other) => !group.options.some((option) => option.id === other)), id];
            }

            return current.includes(id) ? current.filter((other) => other !== id) : [...current, id];
        });

    const submit = (event) => {
        event.preventDefault();
        onAdd({ optionIds: ids, note: note.trim() || null });
    };

    return (
        <Modal show title={title} onClose={onClose} maxWidth="md">
            <form onSubmit={submit} className="space-y-3 text-sm text-slate-800 dark:text-slate-200">
                <div className="max-h-[55vh] space-y-4 overflow-y-auto">
                    {groups.map((group) => {
                        const full = chosen(group).length >= group.max_select;

                        return (
                            <fieldset key={group.id}>
                                <legend className="font-semibold">
                                    {group.name}
                                    <span className="ml-2 text-xs font-normal text-slate-500 dark:text-slate-400">
                                        {group.is_required ? labels.required : labels.optional} · {labels.range(group)}
                                    </span>
                                </legend>
                                {group.options.map((option) => {
                                    const checked = ids.includes(option.id);
                                    const disabled = !option.available || (!checked && full && !pickOne(group));

                                    return (
                                        <label
                                            key={option.id}
                                            className={`flex min-h-[44px] items-center gap-3 border-b border-slate-100 dark:border-slate-800 ${disabled ? "cursor-not-allowed text-slate-400 dark:text-slate-500" : "cursor-pointer"}`}
                                        >
                                            <input
                                                type={pickOne(group) ? "radio" : "checkbox"}
                                                name={`modifier-group-${group.id}`}
                                                className="h-5 w-5 text-primary-500 focus:ring-primary-500 disabled:opacity-40"
                                                checked={checked}
                                                disabled={disabled}
                                                onChange={() => toggle(group, option.id)}
                                            />
                                            <span className="flex-1 break-words">{option.name}</span>
                                            {!option.available && <span className="font-semibold text-danger-600">{labels.soldOut}</span>}
                                            {option.price_delta > 0 && <span>+{rupiah(option.price_delta)}</span>}
                                        </label>
                                    );
                                })}
                            </fieldset>
                        );
                    })}
                    <label className="block">
                        <span className="font-semibold">{labels.note}</span>
                        <textarea
                            value={note}
                            maxLength={100}
                            rows={2}
                            onChange={(event) => setNote(event.target.value)}
                            className="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-900"
                        />
                    </label>
                    <p className="text-right text-xs text-slate-500">{labels.noteCount(note.length)}</p>
                </div>
                <p className="flex justify-between font-bold" aria-live="polite">
                    <span>{labels.portionPrice}</span>
                    <span>{rupiah(price)}</span>
                </p>
                {incomplete.length > 0 && (
                    <p role="status" className="text-danger-600">{labels.incomplete(incomplete.join(", "))}</p>
                )}
                <div className="flex gap-2">
                    <button type="button" onClick={onClose} className="min-h-[44px] flex-1 rounded-xl border border-slate-300 font-medium dark:border-slate-700">
                        {labels.cancel}
                    </button>
                    <button
                        type="submit"
                        disabled={processing || incomplete.length > 0}
                        className="min-h-[44px] flex-1 rounded-xl bg-primary-500 font-semibold text-white hover:bg-primary-600 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {labels.add}
                    </button>
                </div>
            </form>
        </Modal>
    );
}
