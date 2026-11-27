import React from "react";
import { useForm } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import { IconPlus } from "@tabler/icons-react";
import toast from "react-hot-toast";
import Input from "@/Components/Dashboard/Input";
import OptionRow from "./OptionRow";

// `key` keeps a row's inputs with it when another row is removed; the server ignores it.
let lastKey = 0;
const withKey = (option) => ({ ...option, key: ++lastKey });
const blankOption = () =>
    withKey({ id: null, name: "", price_delta: 0, is_default: false, components: [], disabled_outlet_ids: [] });

export default function GroupForm({ group = null, outlets, ingredients, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, put, processing, errors } = useForm(
        group
            ? {
                  name: group.name,
                  is_required: group.is_required,
                  min_select: group.min_select,
                  max_select: group.max_select,
                  options: group.options.map(withKey),
              }
            : { name: "", is_required: false, min_select: 0, max_select: 1, options: [blankOption()] },
    );

    // One default per group: ticking one unticks the others.
    const changeOption = (index, next) =>
        setData(
            "options",
            data.options.map((option, i) => {
                if (i === index) return next;
                return next.is_default ? { ...option, is_default: false } : option;
            }),
        );

    const submit = (e) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(t("modifiers.saved"));
                onClose();
            },
            onError: () => toast.error(t("modifiers.failed")),
        };

        if (group) put(route("settings.modifiers.update", group.id), options);
        else post(route("settings.modifiers.store"), options);
    };

    return (
        // noValidate: the server words every refusal in the app's language; the browser's own bubbles would not.
        <form onSubmit={submit} noValidate className="max-w-4xl space-y-6">
            <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 space-y-4">
                <h2 className="text-lg font-semibold text-slate-800 dark:text-white">
                    {group ? t("modifiers.edit") : t("modifiers.add")}
                </h2>
                <Input
                    label={t("modifiers.name")}
                    value={data.name}
                    onChange={(e) => setData("name", e.target.value)}
                    errors={errors.name}
                    maxLength={100}
                    autoFocus
                />
                <fieldset className="flex flex-wrap items-center gap-x-6">
                    <legend className="text-sm font-medium text-slate-700 dark:text-slate-300">{t("modifiers.type")}</legend>
                    {[true, false].map((required) => (
                        <label key={String(required)} className="inline-flex min-h-11 items-center gap-2 cursor-pointer text-sm text-slate-700 dark:text-slate-300">
                            <input
                                type="radio"
                                name="is_required"
                                checked={data.is_required === required}
                                onChange={() => setData("is_required", required)}
                                className="size-5 border-slate-300 text-primary-600 focus:ring-primary-500"
                            />
                            {t(required ? "modifiers.required" : "modifiers.optional")}
                        </label>
                    ))}
                </fieldset>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <Input
                        type="number"
                        min="0"
                        label={t("modifiers.min")}
                        value={data.min_select}
                        onChange={(e) => setData("min_select", e.target.value)}
                        errors={errors.min_select}
                    />
                    <Input
                        type="number"
                        min="1"
                        label={t("modifiers.max")}
                        value={data.max_select}
                        onChange={(e) => setData("max_select", e.target.value)}
                        errors={errors.max_select}
                    />
                </div>
                <p className="text-xs text-slate-500 dark:text-slate-400">
                    {t(data.is_required ? "modifiers.hintRequired" : "modifiers.hintOptional")}
                </p>
            </div>

            <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 space-y-4">
                <h3 className="text-sm font-semibold text-slate-700 dark:text-slate-300">{t("modifiers.options")}</h3>
                {errors.options && (
                    <p role="alert" className="text-sm text-danger-500 dark:text-danger-400">
                        {errors.options}
                    </p>
                )}
                {data.options.map((option, index) => (
                    <OptionRow
                        key={option.key}
                        prefix={`options.${index}`}
                        number={index + 1}
                        option={option}
                        outlets={outlets}
                        ingredients={ingredients}
                        errors={errors}
                        onChange={(next) => changeOption(index, next)}
                        onRemove={() => setData("options", data.options.filter((_, i) => i !== index))}
                    />
                ))}
                <button
                    type="button"
                    onClick={() => setData("options", [...data.options, blankOption()])}
                    className="inline-flex items-center gap-2 min-h-11 px-4 rounded-xl border border-slate-200 dark:border-slate-700 text-sm font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800"
                >
                    <IconPlus size={16} />
                    {t("modifiers.addOption")}
                </button>
            </div>

            <div className="flex items-center gap-3">
                <button
                    type="submit"
                    disabled={processing}
                    className="inline-flex items-center px-4 py-2.5 min-h-11 rounded-xl bg-primary-500 hover:bg-primary-600 disabled:opacity-60 text-white text-sm font-semibold transition-colors"
                >
                    {t("common.buttons.save")}
                </button>
                <button
                    type="button"
                    onClick={onClose}
                    className="inline-flex items-center px-4 py-2.5 min-h-11 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 text-sm hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                >
                    {t("common.buttons.cancel")}
                </button>
            </div>
        </form>
    );
}
