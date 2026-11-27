import React, { useState } from "react";
import axios from "axios";
import toast from "react-hot-toast";
import { useTranslation } from "react-i18next";
import { IconCheck } from "@tabler/icons-react";
import { requestPrinter, disconnectPrinter, printerKey as deviceKey } from "@/Utils/escpos";
import { DriverLockedHelp } from "@/Components/Kitchen/PrinterStatusBanner";

const BUTTON =
    "min-h-[44px] rounded-xl border px-4 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-50";
const PRIMARY = `${BUTTON} border-primary-500 text-primary-600 hover:bg-primary-50 dark:hover:bg-primary-950/30`;
const FIELD =
    "min-h-[44px] w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white";
const LABEL = "mb-2 block text-sm font-semibold text-slate-700 dark:text-slate-300";

// The settings of this device's print device. `printDevice` is the useKitchenPrintDevice() result
// from the page, so the panel and the background printing share one state.
export default function PrintDevicePanel({ printDevice }) {
    const { t } = useTranslation();
    const { device, printerKey, paper, autoPrint, otherTab, setDevice, setPrinterKey, setPaper, setAutoPrint, testPrint, status, reportError } =
        printDevice;
    // No WebUSB (iPad, Firefox, an http page): the printer controls are off, the rest of the display works.
    const unsupported = status === "unsupported";
    const [name, setName] = useState(device?.name ?? "");
    const [nameError, setNameError] = useState(null);
    const [busy, setBusy] = useState(false);

    const run = async (action) => {
        setBusy(true);
        try {
            await action();
        } catch (e) {
            toast.error(e.message);
        } finally {
            setBusy(false);
        }
    };

    const saveDevice = (e) => {
        e.preventDefault();
        setNameError(null);
        return run(async () => {
            try {
                const { data } = await axios.post(route("kitchen.print-devices.store"), { name: name.trim() });
                setDevice({ id: data.id, name: data.name });
                setName(data.name);
            } catch (error) {
                if (error.response?.status !== 422) throw error;
                // Empty and too-long names are stopped by the form, so a 422 here is a taken name.
                setNameError(t("kitchen.print.nameTaken"));
            }
        });
    };

    // A printer action tells the hook how it went: a Windows driver lock shows the WinUSB steps until one succeeds.
    const printerAction = (action) =>
        run(async () => {
            try {
                await action();
                reportError?.(null);
            } catch (error) {
                reportError?.(error);
                throw error;
            }
        });

    const connect = () =>
        printerAction(async () => {
            setPrinterKey(deviceKey(await requestPrinter()));
        });

    const disconnect = () =>
        run(async () => {
            await disconnectPrinter();
            setPrinterKey("");
        });

    const ready = Boolean(printerKey) && autoPrint;

    return (
        <section
            id="kitchen-print-panel"
            aria-label={t("kitchen.print.open")}
            className="mb-6 max-w-lg space-y-5 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"
        >
            <form onSubmit={saveDevice} noValidate>
                <label htmlFor="kitchen-device-name" className={LABEL}>
                    {t("kitchen.print.deviceName")}
                </label>
                <div className="flex gap-2">
                    <input
                        id="kitchen-device-name"
                        type="text"
                        maxLength={60}
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        placeholder={t("kitchen.print.devicePlaceholder")}
                        aria-invalid={nameError ? "true" : undefined}
                        aria-describedby={nameError ? "kitchen-device-name-error" : undefined}
                        className={FIELD}
                    />
                    <button type="submit" disabled={busy || name.trim() === ""} className={`${PRIMARY} shrink-0`}>
                        {t("kitchen.print.save")}
                    </button>
                </div>
                {nameError && (
                    <p id="kitchen-device-name-error" role="alert" className="mt-2 text-sm text-danger-700 dark:text-danger-400">
                        {nameError}
                    </p>
                )}
                {device && (
                    <p className="mt-2 inline-flex items-center gap-1 text-sm text-slate-600 dark:text-slate-300">
                        <IconCheck size={16} aria-hidden="true" />
                        {device.name}
                    </p>
                )}
            </form>

            {unsupported && (
                <div id="kitchen-usb-unsupported" className="space-y-1 text-sm text-slate-700 dark:text-slate-300">
                    <p className="font-medium">{t("kitchen.print.unsupported")}</p>
                    {window.isSecureContext === false && <p>{t("kitchen.print.unsupportedInsecure")}</p>}
                </div>
            )}

            {status === "driver_locked" && (
                <div className="flex flex-wrap items-center gap-2 rounded-xl bg-danger-100 p-3 text-sm text-danger-700 dark:bg-danger-900/50 dark:text-danger-400">
                    <p className="basis-full font-medium">{t("kitchen.print.driverLocked")}</p>
                    <DriverLockedHelp />
                </div>
            )}

            <div className="flex flex-wrap gap-2">
                {printerKey ? (
                    <button type="button" onClick={disconnect} disabled={busy} className={`${BUTTON} border-danger-300 text-danger-600`}>
                        {t("kitchen.print.disconnect")}
                    </button>
                ) : (
                    <button
                        type="button"
                        onClick={connect}
                        disabled={busy || !device || unsupported}
                        aria-describedby={unsupported ? "kitchen-usb-unsupported" : undefined}
                        className={PRIMARY}
                    >
                        {t("kitchen.print.connect")}
                    </button>
                )}
            </div>

            <div>
                <label htmlFor="kitchen-paper" className={LABEL}>
                    {t("kitchen.print.paper")}
                </label>
                <div className="flex gap-2">
                    <select id="kitchen-paper" value={paper} onChange={(e) => setPaper(e.target.value)} className={FIELD}>
                        <option value="80mm">80 mm</option>
                        <option value="58mm">58 mm</option>
                    </select>
                    <button
                        type="button"
                        onClick={() => printerAction(testPrint)}
                        disabled={busy || !printerKey}
                        className={`${PRIMARY} shrink-0`}
                    >
                        {t("kitchen.print.test")}
                    </button>
                </div>
            </div>

            <button
                type="button"
                role="switch"
                aria-checked={autoPrint}
                disabled={!device || unsupported}
                onClick={() => setAutoPrint(!autoPrint)}
                className="flex min-h-[44px] items-center gap-3 text-sm font-medium text-slate-700 disabled:cursor-not-allowed disabled:opacity-50 dark:text-slate-300"
            >
                <span
                    aria-hidden="true"
                    className={`relative h-6 w-11 shrink-0 rounded-full transition-colors ${autoPrint ? "bg-primary-500" : "bg-slate-300 dark:bg-slate-600"}`}
                >
                    <span
                        className={`absolute top-0.5 h-5 w-5 rounded-full bg-white transition-all ${autoPrint ? "left-[22px]" : "left-0.5"}`}
                    />
                </span>
                {t("kitchen.print.auto")}
            </button>

            <p role="status" className="text-sm font-medium text-slate-700 dark:text-slate-300">
                {otherTab ? t("kitchen.print.otherTab") : ready ? t("kitchen.print.ready") : t("kitchen.print.notConnected")}
            </p>
        </section>
    );
}
