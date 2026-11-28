import React from "react";
import { Link } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import { IconAdjustments, IconArrowDown, IconArrowUp, IconX } from "@tabler/icons-react";
import Select from "@/Components/Dashboard/Select";
import hasAnyPermission from "@/Utils/Permission";

const iconButton =
    "size-11 inline-flex items-center justify-center rounded-xl text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 disabled:opacity-30";

// The field leaves the form only for a menu, with modifiers-manage: otherwise the server answers 403, and a raw
// material loses its groups on save anyway. The form keeps the choice, so unticking raw material restores it.
export function useModifierGroupsPayload() {
    const allowed = hasAnyPermission(["modifiers-manage"]);
    return ({ modifier_group_ids, ...data }) => (allowed && !data.is_raw_material ? { ...data, modifier_group_ids } : data);
}

export default function ModifierGroupsField({ groups = [], value, onChange, errors = {} }) {
    const { t } = useTranslation();
    if (!hasAnyPermission(["modifiers-manage"])) return null;

    const selected = value || [];
    // "" rather than []: a form sent as FormData (a new image) drops an empty array, and the groups would stay.
    const change = (ids) => onChange(ids.length ? ids : "");
    const move = (index, step) => {
        const ids = [...selected];
        [ids[index], ids[index + step]] = [ids[index + step], ids[index]];
        change(ids);
    };
    const error = Object.entries(errors).find(([key]) => key.startsWith("modifier_group_ids"))?.[1];

    return (
        <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 space-y-3">
            <h3 className="text-sm font-semibold text-slate-700 dark:text-slate-300 flex items-center gap-2">
                <IconAdjustments size={18} />
                {t("modifiers.title")}
            </h3>

            {groups.length === 0 ? (
                <p className="text-sm text-slate-500 dark:text-slate-400">
                    {t("modifiers.empty")}.{" "}
                    <Link href={route("settings.modifiers.index")} className="font-medium text-primary-600 underline">
                        {t("modifiers.add")}
                    </Link>
                </p>
            ) : (
                <>
                    <ol className="space-y-2">
                        {selected.map((id, index) => {
                            const group = groups.find((candidate) => candidate.id === id);
                            const name = group?.name ?? `#${id}`;

                            return (
                                <li key={id} className="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                                    <span className="min-w-0 truncate">
                                        {index + 1}. {name} ({t(group?.is_required ? "modifiers.required" : "modifiers.optional")})
                                    </span>
                                    <span className="ml-auto flex shrink-0">
                                        <button type="button" className={iconButton} disabled={index === 0} onClick={() => move(index, -1)} aria-label={t("modifiers.moveUp", { name })}>
                                            <IconArrowUp size={18} />
                                        </button>
                                        <button type="button" className={iconButton} disabled={index === selected.length - 1} onClick={() => move(index, 1)} aria-label={t("modifiers.moveDown", { name })}>
                                            <IconArrowDown size={18} />
                                        </button>
                                        <button type="button" className={iconButton} onClick={() => change(selected.filter((other) => other !== id))} aria-label={t("modifiers.detach", { name })}>
                                            <IconX size={18} />
                                        </button>
                                    </span>
                                </li>
                            );
                        })}
                    </ol>
                    <Select
                        id="modifier_group_add"
                        label={t("modifiers.attach")}
                        value=""
                        onChange={(e) => change([...selected, Number(e.target.value)])}
                    >
                        <option value="">{t("modifiers.chooseGroup")}</option>
                        {groups
                            .filter((group) => !selected.includes(group.id))
                            .map((group) => (
                                <option key={group.id} value={group.id}>
                                    {group.name}
                                </option>
                            ))}
                    </Select>
                </>
            )}

            {error && (
                <p role="alert" className="text-xs text-danger-500 dark:text-danger-400">
                    {error}
                </p>
            )}
        </div>
    );
}
