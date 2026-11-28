import React, { useState } from "react";
import { Head, router, useForm } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import { IconCalendarEvent, IconPlus } from "@tabler/icons-react";
import toast from "react-hot-toast";
import Swal from "sweetalert2";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Input from "@/Components/Dashboard/Input";
import Modal from "@/Components/Dashboard/Modal";
import Select from "@/Components/Dashboard/Select";
import Table from "@/Components/Dashboard/Table";

const MARKS = ["arrived", "no_show", "cancelled"];
const COLUMNS = ["time", "guest", "phone", "partySize", "table", "note", "status", "actionsColumn"];
const rowButton =
    "inline-flex items-center px-3 min-h-11 rounded-xl border border-slate-200 dark:border-slate-700 text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors";

const emptyForm = (date) => ({
    guest_name: "", phone: "", party_size: "", reserved_date: date, reserved_time: "", dine_table_id: "", note: "",
});

const tableLabel = (name, area) => (area ? `${name} (${area})` : name);

// Reachable only with dine-reservations-manage (the route middleware), so nothing here checks it again.
export default function Index({ reservations = [], tables = [], date }) {
    const { t, i18n } = useTranslation();
    const [day, setDay] = useState(date);
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const { data, setData, post, patch, processing, errors, clearErrors } = useForm(emptyForm(date));

    // The server sends Y-m-d; the time part keeps the browser from reading it as UTC.
    const formatDate = (value) =>
        new Date(`${value}T00:00:00`).toLocaleDateString(i18n.language, { day: "numeric", month: "short", year: "numeric" });

    const showDay = (value) => {
        setDay(value);
        if (value) {
            router.get(route("dine-reservations.index"), { date: value }, { preserveState: true, replace: true });
        }
    };

    const openForm = (reservation) => {
        setEditing(reservation);
        clearErrors();
        setData(
            reservation
                ? {
                      guest_name: reservation.guest_name,
                      phone: reservation.phone,
                      party_size: reservation.party_size,
                      reserved_date: reservation.date,
                      reserved_time: reservation.time,
                      dine_table_id: reservation.dine_table_id,
                      note: reservation.note ?? "",
                  }
                : emptyForm(date),
        );
        setModalOpen(true);
    };

    const submit = (e) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(t("dineReservations.saved"));
                setModalOpen(false);
            },
            onError: (errs) => {
                // `status`: someone marked it meanwhile. The form has no field for that.
                toast.error(errs.status ?? t("dineReservations.failed"));
                if (errs.status) setModalOpen(false);
            },
        };

        if (editing) patch(route("dine-reservations.update", editing.id), options);
        else post(route("dine-reservations.store"), options);
    };

    const mark = async (reservation, status) => {
        const { isConfirmed } = await Swal.fire({
            title: t("dineReservations.markTitle", {
                name: reservation.guest_name,
                status: t(`dineReservations.statuses.${status}`),
            }),
            text: t("dineReservations.markConfirm"),
            icon: "question",
            showCancelButton: true,
            cancelButtonColor: "#64748b",
            confirmButtonText: t(`dineReservations.actions.${status}`),
            // Not the common "cancel": in Indonesian that is "Batal", which here is one of the marks.
            cancelButtonText: t("common.buttons.back"),
        });
        if (!isConfirmed) return;

        router.post(route("dine-reservations.mark", reservation.id), { status }, {
            preserveScroll: true,
            onSuccess: () => toast.success(t("dineReservations.marked")),
            onError: (errs) => toast.error(errs.status ?? t("dineReservations.failed")),
        });
    };

    const field = (name, label, props = {}) => (
        <Input
            label={t(`dineReservations.${label}`)}
            value={data[name]}
            onChange={(e) => setData(name, e.target.value)}
            errors={errors[name]}
            {...props}
        />
    );

    return (
        <>
            <Head title={t("dineReservations.title")} />

            <div className="mb-6 flex flex-col sm:flex-row justify-between items-start sm:items-end gap-4">
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <IconCalendarEvent size={28} className="text-primary-500" aria-hidden="true" />
                    {t("dineReservations.title")}
                </h1>
                <div className="flex flex-wrap items-end gap-3">
                    <Input
                        type="date"
                        label={t("dineReservations.date")}
                        value={day}
                        onChange={(e) => showDay(e.target.value)}
                    />
                    <button
                        type="button"
                        onClick={() => openForm(null)}
                        className="inline-flex items-center gap-2 px-4 py-2.5 min-h-11 rounded-xl bg-primary-500 hover:bg-primary-600 text-white text-sm font-medium transition-colors"
                    >
                        <IconPlus size={18} aria-hidden="true" />
                        {t("dineReservations.add")}
                    </button>
                </div>
            </div>

            <Table.Card title={formatDate(date)}>
                {reservations.length > 0 ? (
                    <Table>
                        <Table.Thead>
                            <tr>
                                {COLUMNS.map((column) => (
                                    <Table.Th key={column}>{t(`dineReservations.${column}`)}</Table.Th>
                                ))}
                            </tr>
                        </Table.Thead>
                        <Table.Tbody>
                            {reservations.map((reservation) => (
                                <tr key={reservation.id}>
                                    <Table.Td>
                                        <span className="font-semibold">{reservation.time}</span>
                                        {reservation.date !== date && (
                                            <span className="block text-xs">{formatDate(reservation.date)}</span>
                                        )}
                                    </Table.Td>
                                    <Table.Td className="font-semibold">{reservation.guest_name}</Table.Td>
                                    <Table.Td>
                                        <a
                                            href={`tel:${reservation.phone.replace(/[^0-9+]/g, "")}`}
                                            className="inline-flex items-center min-h-11 underline text-primary-700 dark:text-primary-400"
                                        >
                                            {reservation.phone}
                                        </a>
                                    </Table.Td>
                                    <Table.Td>{reservation.party_size}</Table.Td>
                                    <Table.Td>{tableLabel(reservation.table_name, reservation.area_name)}</Table.Td>
                                    <Table.Td className="!whitespace-normal min-w-40">{reservation.note}</Table.Td>
                                    <Table.Td>{t(`dineReservations.statuses.${reservation.status}`)}</Table.Td>
                                    <Table.Td>
                                        {reservation.status === "booked" && (
                                            <div className="flex gap-2">
                                                {MARKS.map((status) => (
                                                    <button
                                                        key={status}
                                                        type="button"
                                                        onClick={() => mark(reservation, status)}
                                                        aria-label={`${t(`dineReservations.actions.${status}`)}: ${reservation.guest_name}`}
                                                        className={rowButton}
                                                    >
                                                        {t(`dineReservations.actions.${status}`)}
                                                    </button>
                                                ))}
                                                <button
                                                    type="button"
                                                    onClick={() => openForm(reservation)}
                                                    aria-label={`${t("dineReservations.edit")}: ${reservation.guest_name}`}
                                                    className={rowButton}
                                                >
                                                    {t("dineReservations.edit")}
                                                </button>
                                            </div>
                                        )}
                                    </Table.Td>
                                </tr>
                            ))}
                        </Table.Tbody>
                    </Table>
                ) : (
                    <p className="p-8 text-center text-slate-500 dark:text-slate-400">{t("dineReservations.empty")}</p>
                )}
            </Table.Card>

            <Modal
                show={modalOpen}
                onClose={() => setModalOpen(false)}
                title={editing ? t("dineReservations.editTitle") : t("dineReservations.add")}
                maxWidth="lg"
            >
                <form onSubmit={submit} className="space-y-4">
                    {field("guest_name", "guestName", { maxLength: 100, required: true, autoFocus: true })}
                    <div className="grid gap-4 sm:grid-cols-2">
                        {field("phone", "phone", { type: "tel", maxLength: 30, required: true })}
                        {field("party_size", "partySize", { type: "number", min: 1, max: 100, required: true })}
                        {field("reserved_date", "date", { type: "date", required: true })}
                        {field("reserved_time", "time", { type: "time", required: true })}
                    </div>
                    <Select
                        id="dine_table_id"
                        label={t("dineReservations.table")}
                        value={data.dine_table_id}
                        onChange={(e) => setData("dine_table_id", e.target.value)}
                        error={errors.dine_table_id}
                        required
                    >
                        <option value="">{t("dineReservations.chooseTable")}</option>
                        {tables.map((table) => (
                            <option key={table.id} value={table.id}>
                                {tableLabel(table.name, table.area_name)}
                            </option>
                        ))}
                    </Select>
                    {field("note", "note", { maxLength: 500 })}
                    <div className="flex items-center gap-3">
                        <button
                            type="submit"
                            disabled={processing}
                            className="inline-flex items-center px-4 py-2.5 min-h-11 rounded-xl bg-primary-500 hover:bg-primary-600 disabled:opacity-60 text-white text-sm font-semibold transition-colors"
                        >
                            {t("common.buttons.save")}
                        </button>
                        <button type="button" onClick={() => setModalOpen(false)} className={rowButton}>
                            {t("common.buttons.close")}
                        </button>
                    </div>
                </form>
            </Modal>
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
