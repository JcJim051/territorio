import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle, CalendarCheck2, CalendarDays, Check, CheckCheck, CheckCircle2, ChevronLeft, ChevronRight,
    Clock3, Cloud, LayoutList, MapPin, Navigation, PackageCheck, Pencil, Plus, Route, Trash2, UserRound, Users, X, XCircle,
} from 'lucide-react';
import { FormEvent, lazy, Suspense, useEffect, useMemo, useRef, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { SharedProps } from '@/types';

const MapLocationPicker = lazy(() => import('@/Components/MapLocationPicker'));

type Conflict = { id: string; title: string; status: string; startsAt: string; endsAt: string; location?: string; blocking: boolean };
type TravelPoint = { id: string; title: string; location?: string; address?: string; startsAt: string; endsAt: string };
type TravelLeg = { from: TravelPoint; to: TravelPoint; gapMinutes: number; distanceKm?: number; estimatedMinutes?: number; risk: boolean; assessment: 'unknown' | 'insufficient' | 'tight' | 'comfortable' };
type ResourceRequirement = {
    id: number; resourceId: number; name: string; kind: string; unit: string; mode: 'consume' | 'reserve';
    required: number; available: number; missing: number; shortage: boolean; status: string; notes?: string;
};
type InventoryResource = { id: number; name: string; kind: string; unit: string; quantity: number };
type Meeting = {
    id: string; title: string; type: string; objective?: string; status: string; startsAt: string; endsAt: string;
    location?: string; address?: string; latitude?: number; longitude?: number; locationNotes?: string;
    expectedAttendees: number; actualAttendees: number; outcome?: string; completedAt?: string;
    leaderId?: number; territoryId?: number; leader?: string; territory?: string;
    conflicts: Conflict[]; hasBlockingConflict: boolean; hasPotentialConflict: boolean;
    requirements: ResourceRequirement[]; hasResourceBlock: boolean;
    mobility: { before?: TravelLeg; after?: TravelLeg };
    pendingChange?: { id: string; changes: Partial<MeetingForm>; createdAt: string };
    googleSync: 'after_approval' | 'not_connected' | 'queued' | 'synced' | 'failed' | 'not_applicable';
    googleHtmlLink?: string;
};
type ExternalEvent = {
    id: string; title: string; startsAt: string; endsAt: string; location?: string; allDay: boolean;
    isBusy: boolean; reviewStatus: string; origin: string; htmlLink?: string; pendingReviewId?: string;
};
type MeetingForm = {
    title: string; type: string; objective: string; location: string; address: string; latitude: string; longitude: string; location_notes: string; starts_at: string; ends_at: string;
    expected_attendees: number; leader_person_id: string; territory_unit_id: string;
    requirements: Array<{ resource_id: string; quantity: number; notes: string }>;
};
type Period = { month: string; label: string; from: string; to: string };

const emptyForm: MeetingForm = { title: '', type: 'reunion', objective: '', location: '', address: '', latitude: '', longitude: '', location_notes: '', starts_at: '', ends_at: '', expected_attendees: 20, leader_person_id: '', territory_unit_id: '', requirements: [] };
const statusLabels: Record<string, string> = { requested: 'Por decidir', approved: 'Confirmada', conditional: 'Condicionada', rejected: 'Rechazada', cancelled: 'Cancelada', completed: 'Finalizada' };
const statusStyles: Record<string, string> = {
    requested: 'border-amber-200 bg-amber-50 text-amber-800',
    approved: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    conditional: 'border-violet-200 bg-violet-50 text-violet-800',
    rejected: 'border-red-100 bg-red-50 text-red-500',
    cancelled: 'border-slate-200 bg-slate-100 text-slate-500',
    completed: 'border-sky-200 bg-sky-50 text-sky-700',
};

export default function Meetings({ meetings, externalEvents, leaders, territories, resources, filters, period, summary, calendarIntegration }: {
    meetings: Meeting[];
    externalEvents: ExternalEvent[];
    leaders: Array<{ id: number; name: string }>;
    territories: Array<{ id: number; name: string; type: string }>;
    resources: InventoryResource[];
    filters: { status?: string; month: string; date?: string };
    period: Period;
    summary: { pending: number; approved: number; withConflicts: number; googlePending: number; resourceAlerts: number };
    calendarIntegration: { connected: boolean; calendarName?: string; accountEmail?: string; status: string };
}) {
    const { errors, currentCampaign } = usePage<SharedProps>().props;
    const pendingFocus = meetings.find((meeting) => meeting.status === filters.status);
    const todayKey = dateKey(new Date());
    const initialSelected = filters.date
        ?? pendingFocus?.startsAt.slice(0, 10)
        ?? (todayKey >= period.from && todayKey <= period.to ? todayKey : `${period.month}-01`);
    const [selectedDate, setSelectedDate] = useState(initialSelected);
    const [reviewing, setReviewing] = useState<Meeting | null>(null);
    const [viewMode, setViewMode] = useState<'month' | 'day'>('month');
    const selectedMobileDay = useRef<HTMLButtonElement | null>(null);
    const mobileDaysScroller = useRef<HTMLDivElement | null>(null);
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Meeting | null>(null);
    const form = useForm<MeetingForm>(emptyForm);
    const can = (permission: string) => currentCampaign?.permissions.includes('*') || currentCampaign?.permissions.includes(permission);
    const currentReviewing = reviewing ? meetings.find((meeting) => meeting.id === reviewing.id) ?? reviewing : null;
    useEffect(() => {
        const day = selectedMobileDay.current;
        const scroller = mobileDaysScroller.current;
        if (day && scroller) {
            scroller.scrollTo({ left: day.offsetLeft - (scroller.clientWidth - day.clientWidth) / 2, behavior: 'smooth' });
        }
    }, [selectedDate, period.month]);
    useEffect(() => {
        if (!meetings.some((meeting) => meeting.googleSync === 'queued')) return;
        const timer = window.setInterval(() => {
            router.reload({ only: ['meetings', 'calendarIntegration'], preserveScroll: true, preserveState: true });
        }, 2500);

        return () => window.clearInterval(timer);
    }, [meetings]);

    const calendarDays = useMemo(() => daysBetween(period.from, period.to), [period.from, period.to]);
    const eventsByDate = useMemo(() => meetings.reduce<Record<string, Meeting[]>>((result, meeting) => {
        const key = meeting.startsAt.slice(0, 10);
        (result[key] ??= []).push(meeting);
        return result;
    }, {}), [meetings]);
    const selectedMeetings = eventsByDate[selectedDate] ?? [];
    const externalByDate = useMemo(() => externalEvents.reduce<Record<string, ExternalEvent[]>>((result, event) => {
        const key = event.startsAt.slice(0, 10);
        (result[key] ??= []).push(event);
        return result;
    }, {}), [externalEvents]);
    const selectedExternal = externalByDate[selectedDate] ?? [];
    const selectedAgendaItems = useMemo(() => [
        ...selectedMeetings.map((meeting) => ({ kind: 'meeting' as const, startsAt: meeting.startsAt, meeting })),
        ...selectedExternal.map((event) => ({ kind: 'google' as const, startsAt: event.startsAt, event })),
    ].sort((left, right) => left.startsAt.localeCompare(right.startsAt)), [selectedMeetings, selectedExternal]);
    const selectedCount = selectedAgendaItems.length;

    const changeMonth = (offset: number) => {
        const [year, month] = period.month.split('-').map(Number);
        const target = new Date(year, month - 1 + offset, 1);
        router.get('/meetings', { month: `${target.getFullYear()}-${String(target.getMonth() + 1).padStart(2, '0')}`, status: filters.status || undefined }, { preserveState: false });
    };
    const goToday = () => {
        const now = new Date();
        const key = dateKey(now);
        router.get('/meetings', { month: key.slice(0, 7), date: key }, { preserveState: false });
    };
    const selectDay = (key: string) => {
        setSelectedDate(key);
        setReviewing(null);
    };
    const goToDate = (key: string) => {
        if (!key) return;
        if (key < period.from || key > period.to) {
            router.get('/meetings', { month: key.slice(0, 7), date: key, status: filters.status || undefined }, { preserveState: false });
            return;
        }
        selectDay(key);
    };
    const shiftDay = (offset: number) => {
        const target = parseDate(selectedDate);
        target.setDate(target.getDate() + offset);
        const key = dateKey(target);
        if (key < period.from || key > period.to) {
            router.get('/meetings', { month: key.slice(0, 7), date: key, status: filters.status || undefined }, { preserveState: false });
            return;
        }
        selectDay(key);
    };
    const approveMeeting = (meeting: Meeting) => router.post(`/meetings/${meeting.id}/approve`, {}, { preserveScroll: true });
    const completeMeeting = (meeting: Meeting, closeDetail = false) => {
        const attendance = window.prompt('Asistentes reales de la reunión', String(meeting.actualAttendees || meeting.expectedAttendees || 0));
        if (attendance === null) return;
        const actualAttendees = Number(attendance);
        if (!Number.isInteger(actualAttendees) || actualAttendees < 0) {
            window.alert('Ingresa un número válido de asistentes.');
            return;
        }
        const outcome = window.prompt('Resultado u observación breve de la reunión (opcional)', meeting.outcome ?? '');
        if (outcome === null) return;
        router.post(`/meetings/${meeting.id}/complete`, {
            actual_attendees: actualAttendees,
            outcome: outcome.trim() || null,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                if (closeDetail) setReviewing(null);
            },
        });
    };
    const approveGoogleEvent = (event: ExternalEvent) => {
        if (event.pendingReviewId) router.post(`/calendar/reviews/${event.pendingReviewId}/approve`, {}, { preserveScroll: true });
    };
    const startCreate = (day = selectedDate) => {
        setEditing(null);
        form.setData({ ...emptyForm, starts_at: `${day}T09:00`, ends_at: `${day}T10:00` });
        form.clearErrors();
        setOpen(true);
    };
    const startEdit = (meeting: Meeting) => {
        setReviewing(null);
        setEditing(meeting);
        form.setData({
            title: meeting.title, type: meeting.type, objective: meeting.objective ?? '', location: meeting.location ?? '',
            address: meeting.address ?? '', latitude: meeting.latitude != null ? String(meeting.latitude) : '',
            longitude: meeting.longitude != null ? String(meeting.longitude) : '', location_notes: meeting.locationNotes ?? '',
            starts_at: meeting.startsAt, ends_at: meeting.endsAt, expected_attendees: meeting.expectedAttendees,
            leader_person_id: meeting.leaderId ? String(meeting.leaderId) : '', territory_unit_id: meeting.territoryId ? String(meeting.territoryId) : '',
            requirements: meeting.requirements.map((requirement) => ({
                resource_id: String(requirement.resourceId),
                quantity: requirement.required,
                notes: requirement.notes ?? '',
            })),
        });
        form.clearErrors();
        setOpen(true);
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { form.reset(); setEditing(null); setOpen(false); } };
        editing ? form.put(`/meetings/${editing.id}`, options) : form.post('/meetings', options);
    };
    const remove = (meeting: Meeting) => {
        if (window.confirm(`¿Eliminar la actividad “${meeting.title}”?`)) {
            router.delete(`/meetings/${meeting.id}`, { preserveScroll: true, onSuccess: () => setReviewing(null) });
        }
    };

    return (
        <AppLayout title="Agenda" eyebrow="Calendario estratégico">
            <Head title="Agenda" />

            <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    <div className="hidden overflow-hidden rounded-xl border border-slate-200 bg-white lg:flex">
                        <button onClick={() => setViewMode('month')} className={`flex items-center gap-1.5 px-3 py-2.5 text-xs font-black ${viewMode === 'month' ? 'bg-[var(--campaign-accent-soft)] text-[var(--campaign-accent)]' : 'text-slate-500'}`}><CalendarDays size={15} /> Mes</button>
                        <button onClick={() => setViewMode('day')} className={`flex items-center gap-1.5 px-3 py-2.5 text-xs font-black ${viewMode === 'day' ? 'bg-[var(--campaign-accent-soft)] text-[var(--campaign-accent)]' : 'text-slate-500'}`}><LayoutList size={15} /> Día</button>
                    </div>
                    <button onClick={goToday} className="secondary-button">Hoy</button>
                    <input type="date" aria-label="Ir a una fecha" value={selectedDate} onChange={(event) => goToDate(event.target.value)} className="field w-auto min-w-36 py-2.5 text-xs font-bold" />
                    <div className="flex overflow-hidden rounded-xl border border-slate-200 bg-white">
                        <button onClick={() => changeMonth(-1)} className="p-2.5 text-slate-500 hover:bg-slate-50" aria-label="Mes anterior"><ChevronLeft size={18} /></button>
                        <div className="min-w-40 border-x border-slate-100 px-4 py-2.5 text-center text-sm font-black capitalize text-slate-700">{formatMonth(period.month)}</div>
                        <button onClick={() => changeMonth(1)} className="p-2.5 text-slate-500 hover:bg-slate-50" aria-label="Mes siguiente"><ChevronRight size={18} /></button>
                    </div>
                </div>
                {can('meetings.create') && <button onClick={() => startCreate()} className="primary-button ml-auto shrink-0"><Plus size={17} /> <span className="hidden sm:inline">Nueva actividad</span><span className="sm:hidden">Nueva</span></button>}
            </div>

            <div className="mb-5 overflow-x-auto pb-1">
                <div className="flex w-max min-w-full flex-nowrap gap-2 text-[10px] font-bold xl:justify-end">
                    <Legend tone="bg-emerald-500" label="Confirmadas" />
                    <Legend tone="bg-amber-400" label="Por decidir" />
                    <Legend tone="bg-violet-500" label="Condicionadas" />
                    <Legend tone="bg-slate-300" label="Finalizadas o canceladas" />
                    <Legend tone="bg-sky-500" label="Evento externo autorizado" />
                    <Legend tone="bg-red-400" label="Cambio de Google pendiente" />
                </div>
            </div>

            {filters.status === 'requested' && (
                <div className="mb-4 flex flex-col justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 sm:flex-row sm:items-center">
                    <div><div className="flex items-center gap-2 text-sm font-bold text-amber-800"><AlertTriangle size={17} /> Enfoque de decisiones activo · {summary.pending} por revisar</div><p className="mt-1 text-[11px] text-amber-700">Este enfoque solo resalta solicitudes; puedes navegar libremente por cualquier día.</p></div>
                    <button onClick={() => router.get('/meetings', { month: period.month, date: selectedDate }, { preserveState: false })} className="text-xs font-black text-amber-800 underline">Quitar enfoque</button>
                </div>
            )}
            {(errors.meeting || errors.resources || errors.requirements) && <div className="mb-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-700">{errors.meeting ?? errors.resources ?? errors.requirements}</div>}

            <section className="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Metric icon={AlertTriangle} value={summary.pending} label="Oportunidades por decidir" tone="text-amber-700 bg-amber-50" />
                <Metric icon={CheckCircle2} value={summary.approved} label="Actividades confirmadas" tone="text-emerald-700 bg-emerald-50" />
                <Metric icon={CalendarDays} value={summary.withConflicts} label="Actividades con cruce confirmado" tone={summary.withConflicts ? 'text-red-700 bg-red-50' : 'text-slate-600 bg-slate-50'} />
                <Metric icon={PackageCheck} value={summary.resourceAlerts} label="Actividades con faltantes" tone={summary.resourceAlerts ? 'text-red-700 bg-red-50' : 'text-slate-600 bg-slate-50'} />
            </section>

            <div className="panel mb-4 overflow-hidden lg:hidden">
                <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                    <div><div className="text-[10px] font-black uppercase tracking-wide text-[var(--campaign-accent)]">Selecciona un día</div><div className="text-sm font-black capitalize text-slate-700">{formatMonth(period.month)}</div></div>
                    <button onClick={goToday} className="text-xs font-black text-[var(--campaign-accent)]">Ir a hoy</button>
                </div>
                <div ref={mobileDaysScroller} className="flex gap-2 overflow-x-auto p-3">
                    {calendarDays.filter((day) => dateKey(day).startsWith(period.month)).map((day) => {
                        const key = dateKey(day);
                        const count = (eventsByDate[key]?.length ?? 0) + (externalByDate[key]?.length ?? 0);
                        const selected = key === selectedDate;
                        return <button ref={selected ? selectedMobileDay : undefined} key={key} onClick={() => selectDay(key)} aria-label={`Ver agenda del ${formatLongDate(key)}`} className={`relative flex min-w-14 flex-col items-center rounded-xl border px-2 py-2.5 ${selected ? 'border-[var(--campaign-accent)] bg-[var(--campaign-accent)] text-[var(--campaign-contrast)] shadow-sm' : 'border-slate-200 bg-white text-slate-600'}`}><span className="text-[9px] font-black uppercase opacity-65">{weekdayShort(key)}</span><span className="mt-0.5 text-lg font-black">{day.getDate()}</span>{count > 0 && <span className={`mt-1 rounded-full px-1.5 py-0.5 text-[8px] font-black ${selected ? 'bg-white/20' : 'bg-slate-100 text-slate-500'}`}>{count}</span>}</button>;
                    })}
                </div>
            </div>

            <section className={`grid gap-5 ${viewMode === 'month' ? 'lg:grid-cols-[minmax(0,1fr)_360px]' : 'lg:grid-cols-1'}`}>
                <article className={`panel overflow-visible ${viewMode === 'day' ? 'hidden' : 'hidden lg:block'}`}>
                    <div className="grid grid-cols-7 border-b border-slate-100 bg-slate-50/80">
                        {['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'].map((day) => <div key={day} className="px-2 py-3 text-center text-[10px] font-black uppercase tracking-wider text-slate-400">{day}</div>)}
                    </div>
                    <div className="grid grid-cols-7">
                        {calendarDays.map((day) => {
                            const key = dateKey(day);
                            const dayEvents = eventsByDate[key] ?? [];
                            const googleEvents = externalByDate[key] ?? [];
                            const currentMonth = key.startsWith(period.month);
                            const selected = key === selectedDate;
                            const today = key === todayKey;
                            return (
                                <div key={key} onClick={() => selectDay(key)} className={`group/day relative min-h-28 cursor-pointer border-b border-r border-slate-100 p-2 text-left transition md:min-h-32 ${!currentMonth ? 'bg-slate-50/60' : 'bg-white hover:bg-[var(--campaign-accent-soft)]'} ${selected ? 'z-[1] ring-2 ring-inset ring-[var(--campaign-accent)]' : ''}`}>
                                    <button onClick={(event) => { event.stopPropagation(); selectDay(key); }} aria-label={`Ver agenda del ${formatLongDate(key)}`} className={`mb-2 grid size-7 place-items-center rounded-full text-xs font-black ${today ? 'bg-[var(--campaign-accent)] text-[var(--campaign-contrast)]' : currentMonth ? 'text-slate-700 group-hover/day:bg-white' : 'text-slate-300'}`}>{day.getDate()}</button>
                                    <div className="space-y-1">
                                        {dayEvents.slice(0, 3).map((meeting) => <CalendarEvent key={meeting.id} meeting={meeting} focused={filters.status === meeting.status} onOpen={(event) => { event.stopPropagation(); selectDay(key); setReviewing(meeting); }} />)}
                                        {googleEvents.slice(0, Math.max(0, 3 - dayEvents.length)).map((event) => <GoogleCalendarEvent key={event.id} event={event} />)}
                                        {dayEvents.length + googleEvents.length > 3 && <button onClick={(event) => { event.stopPropagation(); selectDay(key); setViewMode('day'); }} className="px-1 text-[10px] font-black text-[var(--campaign-accent)]">+ {dayEvents.length + googleEvents.length - 3} más · ver día</button>}
                                    </div>
                                    {dayEvents.length === 0 && currentMonth && can('meetings.create') && <button onClick={(event) => { event.stopPropagation(); selectDay(key); startCreate(key); }} aria-label={`Agendar el ${formatLongDate(key)}`} className="absolute bottom-2 right-2 rounded p-1 text-slate-300 opacity-0 transition hover:bg-white hover:text-[var(--campaign-accent)] group-hover/day:opacity-100"><Plus size={14} /></button>}
                                </div>
                            );
                        })}
                    </div>
                </article>

                <aside className={`panel h-fit overflow-hidden ${viewMode === 'month' ? 'lg:sticky lg:top-24' : 'mx-auto w-full max-w-4xl'}`}>
                    <div className="border-b border-slate-100 bg-[var(--campaign-accent)] p-4 text-[var(--campaign-contrast)] sm:p-5">
                        <div className="flex items-center justify-between gap-3">
                            <button onClick={() => shiftDay(-1)} aria-label="Día anterior" className="rounded-xl bg-white/15 p-2 transition hover:bg-white/25"><ChevronLeft size={19} /></button>
                            <div className="min-w-0 text-center">
                                <div className="text-[10px] font-black uppercase tracking-[.16em] opacity-65">Agenda del día</div>
                                <div className="mt-1 truncate text-lg font-black capitalize sm:text-xl">{formatLongDate(selectedDate)}</div>
                                <div className="mt-1 text-xs opacity-60">{selectedCount} actividades y bloqueos</div>
                            </div>
                            <button onClick={() => shiftDay(1)} aria-label="Día siguiente" className="rounded-xl bg-white/15 p-2 transition hover:bg-white/25"><ChevronRight size={19} /></button>
                        </div>
                    </div>
                    {selectedCount > 8 && <div className="border-b border-slate-100 bg-slate-50 px-4 py-2 text-[10px] font-bold text-slate-500">Jornada de alta actividad · ordenada cronológicamente · desplázate para consultar las {selectedCount} entradas</div>}
                    <div className={`${viewMode === 'day' ? 'max-h-[68vh]' : 'max-h-[610px]'} space-y-3 overflow-auto p-3 sm:p-4`}>
                        {selectedAgendaItems.map((item) => item.kind === 'meeting'
                            ? <DayAgendaCard key={item.meeting.id} meeting={item.meeting} onOpen={() => setReviewing(item.meeting)} onApprove={() => approveMeeting(item.meeting)} onComplete={() => completeMeeting(item.meeting)} canApprove={!!can('meetings.approve')} canComplete={!!can('meetings.manage')} calendarConnected={calendarIntegration.connected} />
                            : <GoogleDayCard key={item.event.id} event={item.event} onApprove={() => approveGoogleEvent(item.event)} canApprove={!!can('calendar.changes.review')} />)}
                        {selectedMeetings.length + selectedExternal.length === 0 && <div className="py-12 text-center"><CalendarDays size={28} className="mx-auto text-slate-200" /><p className="mt-3 text-sm font-bold text-slate-500">Día disponible</p><p className="mt-1 text-xs text-slate-400">No hay actividades programadas.</p></div>}
                    </div>
                    {can('meetings.create') && <div className="border-t border-slate-100 p-4"><button onClick={() => startCreate(selectedDate)} className="secondary-button w-full justify-center"><Plus size={16} /> Agendar en este día</button></div>}
                </aside>
            </section>

            {currentReviewing && <ReviewDrawer meeting={currentReviewing} dayMeetings={eventsByDate[currentReviewing.startsAt.slice(0, 10)] ?? []} onClose={() => setReviewing(null)} onEdit={() => startEdit(currentReviewing)} onDelete={() => remove(currentReviewing)} onComplete={() => completeMeeting(currentReviewing, true)} canApprove={!!can('meetings.approve')} canComplete={!!can('meetings.manage')} canEdit={!!can('meetings.manage')} canDelete={!!can('meetings.delete')} calendarConnected={calendarIntegration.connected} />}

            {open && <Modal title={editing ? 'Editar actividad' : 'Programar actividad'} onClose={() => setOpen(false)}>
                <form onSubmit={submit} className="grid gap-5 p-6 md:grid-cols-2">
                    <Field label="Nombre de la actividad" error={form.errors.title} wide><input className="field" value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} required /></Field>
                    <Field label="Tipo" error={form.errors.type}><select className="field" value={form.data.type} onChange={(event) => form.setData('type', event.target.value)}><option value="reunion">Reunión política</option><option value="visita">Visita territorial</option><option value="desayuno">Desayuno de trabajo</option><option value="almuerzo">Almuerzo</option><option value="viaje">Viaje</option><option value="evento">Evento masivo</option></select></Field>
                    <Field label="Asistentes esperados" error={form.errors.expected_attendees}><input type="number" min={0} className="field" value={form.data.expected_attendees} onChange={(event) => form.setData('expected_attendees', Number(event.target.value))} /></Field>
                    <div className="rounded-2xl border border-slate-200 bg-slate-50/70 p-4 md:col-span-2">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div><h3 className="text-sm font-black text-slate-800">Recursos logísticos requeridos</h3><p className="mt-1 text-[11px] leading-4 text-slate-500">Los consumibles se descontarán al aprobar. Los activos y equipos quedarán ocupados durante el horario de la actividad.</p></div>
                            <Link href="/inventory" className="text-[11px] font-black text-[var(--campaign-accent)]">Administrar inventario</Link>
                        </div>
                        <div className="mt-4 grid gap-2">
                            {resources.map((resource) => {
                                const selected = form.data.requirements.find((requirement) => requirement.resource_id === String(resource.id));
                                const locked = editing?.status === 'approved';
                                return <div key={resource.id} className={`rounded-xl border p-3 transition ${selected ? 'border-[var(--campaign-accent)] bg-white' : 'border-slate-200 bg-white/60'}`}>
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                                        <label className="flex min-w-0 flex-1 cursor-pointer items-center gap-3">
                                            <input
                                                type="checkbox"
                                                checked={!!selected}
                                                disabled={locked}
                                                onChange={(event) => form.setData('requirements', event.target.checked
                                                    ? [...form.data.requirements, { resource_id: String(resource.id), quantity: 1, notes: '' }]
                                                    : form.data.requirements.filter((requirement) => requirement.resource_id !== String(resource.id)))}
                                                className="size-4 accent-[var(--campaign-accent)]"
                                            />
                                            <div className="min-w-0"><div className="truncate text-xs font-black text-slate-700">{resource.name}</div><div className="mt-0.5 text-[10px] text-slate-400">{resourceKindLabel(resource.kind)} · Inventario: {resource.quantity} {resource.unit}</div></div>
                                        </label>
                                        {selected && <div className="flex items-center gap-2 sm:w-52"><span className="text-[10px] font-bold text-slate-400">Cantidad</span><input type="number" min="0.01" step="0.01" disabled={locked} className="field py-2 text-xs" value={selected.quantity} onChange={(event) => form.setData('requirements', form.data.requirements.map((requirement) => requirement.resource_id === String(resource.id) ? { ...requirement, quantity: Number(event.target.value) } : requirement))} /><span className="min-w-12 text-[10px] text-slate-400">{resource.unit}</span></div>}
                                    </div>
                                </div>;
                            })}
                            {resources.length === 0 && <div className="rounded-xl border border-dashed border-slate-200 p-5 text-center text-xs text-slate-400">Primero crea sillas, refrigerios, equipos, publicidad u otros elementos en Inventario.</div>}
                        </div>
                        {editing?.status === 'approved' && <p className="mt-3 text-[10px] font-semibold text-amber-700">Los recursos de una actividad aprobada permanecen bloqueados. Para cambiarlos, primero debe cancelarse o reabrirse la decisión logística.</p>}
                        {form.errors.requirements && <p className="mt-2 text-xs font-semibold text-red-600">{form.errors.requirements}</p>}
                    </div>
                    <Field label="Inicio" error={form.errors.starts_at}><input type="datetime-local" className="field" value={form.data.starts_at} onChange={(event) => form.setData('starts_at', event.target.value)} required /></Field>
                    <Field label="Finalización" error={form.errors.ends_at}><input type="datetime-local" className="field" value={form.data.ends_at} onChange={(event) => form.setData('ends_at', event.target.value)} required /></Field>
                    <Field label="Líder solicitante" error={form.errors.leader_person_id}><select className="field" value={form.data.leader_person_id} onChange={(event) => form.setData('leader_person_id', event.target.value)}><option value="">Sin asignar</option>{leaders.map((leader) => <option key={leader.id} value={leader.id}>{leader.name}</option>)}</select></Field>
                    <Field label="Territorio" error={form.errors.territory_unit_id ?? errors.territory}><select className="field" value={form.data.territory_unit_id} onChange={(event) => form.setData('territory_unit_id', event.target.value)}><option value="">Sin asignar</option>{territories.map((territory) => <option key={territory.id} value={territory.id}>{territory.name}</option>)}</select></Field>
                    <Field label="Nombre del lugar" error={form.errors.location}><input className="field" value={form.data.location} onChange={(event) => form.setData('location', event.target.value)} placeholder="Ej. Salón comunal Chapinerito" required /></Field>
                    <Field label="Dirección" error={form.errors.address}><input className="field" value={form.data.address} onChange={(event) => form.setData('address', event.target.value)} placeholder="Dirección completa o punto de referencia" required /></Field>
                    <div className="md:col-span-2"><label className="label">Ubicación exacta en el mapa</label><p className="mb-2 text-[11px] text-slate-400">Haz clic sobre el lugar o arrastra el marcador. Las coordenadas se guardan internamente.</p><Suspense fallback={<div className="grid h-80 place-items-center rounded-2xl bg-slate-100 text-sm font-bold text-slate-400">Cargando mapa…</div>}><MapLocationPicker latitude={form.data.latitude} longitude={form.data.longitude} onChange={(latitude, longitude) => { form.setData('latitude', latitude); form.setData('longitude', longitude); }} error={form.errors.latitude ?? form.errors.longitude} /></Suspense></div>
                    <Field label="Indicaciones de acceso o desplazamiento" error={form.errors.location_notes} wide><textarea rows={2} className="field resize-none" value={form.data.location_notes} onChange={(event) => form.setData('location_notes', event.target.value)} placeholder="Parqueadero, puerta de ingreso, condiciones de la vía…" /></Field>
                    <Field label="Objetivo" error={form.errors.objective} wide><textarea rows={3} className="field resize-none" value={form.data.objective} onChange={(event) => form.setData('objective', event.target.value)} /></Field>
                    <div className="flex justify-end gap-2 border-t border-slate-100 pt-5 md:col-span-2"><button type="button" onClick={() => setOpen(false)} className="secondary-button">Cancelar</button><button className="primary-button" disabled={form.processing}><CalendarCheck2 size={17} /> {editing ? 'Guardar cambios' : 'Guardar solicitud'}</button></div>
                </form>
            </Modal>}
        </AppLayout>
    );
}

function CalendarEvent({ meeting, focused, onOpen }: { meeting: Meeting; focused: boolean; onOpen: (event: React.MouseEvent) => void }) {
    return <div className="group/event relative"><button onClick={onOpen} className={`flex w-full items-center gap-1 overflow-hidden rounded-md border px-1.5 py-1 text-left text-[9px] font-bold transition hover:shadow-sm ${statusStyles[meeting.status] ?? statusStyles.cancelled} ${focused ? 'ring-2 ring-amber-300' : ''}`}><span className="shrink-0">{time(meeting.startsAt)}</span><span className="truncate">{meeting.title}</span>{(meeting.hasPotentialConflict || meeting.hasResourceBlock) && <AlertTriangle size={10} className={meeting.hasBlockingConflict || meeting.hasResourceBlock ? 'shrink-0 text-red-600' : 'shrink-0 text-amber-600'} />}</button><div className="pointer-events-none absolute left-2 top-full z-20 mt-1 hidden w-64 rounded-xl bg-[#102f35] p-3 text-white shadow-xl group-hover/event:block"><div className="text-xs font-black">{meeting.title}</div><div className="mt-1 text-[10px] text-white/60">{time(meeting.startsAt)}–{time(meeting.endsAt)} · {meeting.location ?? meeting.territory ?? 'Lugar por confirmar'}</div>{meeting.address && <div className="mt-1 text-[10px] text-white/45">{meeting.address}</div>}{meeting.hasResourceBlock && <div className="mt-2 text-[10px] font-bold text-red-300">Tiene faltantes de inventario por resolver.</div>}<div className="mt-2 text-[10px] text-[#a8d7c8]">Haz clic para revisar agenda, logística y desplazamiento.</div></div></div>;
}

function GoogleCalendarEvent({ event }: { event: ExternalEvent }) {
    const pending = event.reviewStatus !== 'approved';
    const href = pending && event.pendingReviewId ? `/calendar/reviews?status=pending&review=${event.pendingReviewId}` : (event.htmlLink ?? '/calendar/reviews?status=approved');
    return <div className="group/event relative"><Link onClick={(click) => click.stopPropagation()} href={href} className={`flex w-full items-center gap-1 overflow-hidden rounded-md border px-1.5 py-1 text-left text-[9px] font-bold ${pending ? 'border-red-200 bg-red-50 text-red-700' : event.isBusy ? 'border-sky-200 bg-sky-50 text-sky-700' : 'border-dashed border-slate-200 bg-slate-50 text-slate-500'}`}><span>{event.allDay ? 'Día' : time(event.startsAt)}</span><span className="truncate">{event.title}</span>{pending && <AlertTriangle size={10} className="shrink-0" />}</Link><div className="pointer-events-none absolute left-2 top-full z-20 mt-1 hidden w-64 rounded-xl bg-[#102f35] p-3 text-white shadow-xl group-hover/event:block"><div className="text-[9px] font-black uppercase tracking-wide text-sky-300">Evento creado en Google · {pending ? 'Requiere decisión' : 'Autorizado'}</div><div className="mt-1 text-xs font-black">{event.title}</div><div className="mt-1 text-[10px] text-white/60">{event.allDay ? 'Todo el día' : `${time(event.startsAt)}–${time(event.endsAt)}`} · {event.location ?? 'Sin ubicación'}</div></div></div>;
}

function GoogleDayCard({ event, onApprove, canApprove }: { event: ExternalEvent; onApprove: () => void; canApprove: boolean }) {
    const pending = event.reviewStatus !== 'approved';
    const reviewHref = event.pendingReviewId ? `/calendar/reviews?status=pending&review=${event.pendingReviewId}` : '/calendar/reviews?status=pending';
    return <div className={`rounded-xl border p-3 ${pending ? 'border-red-200 bg-red-50 text-red-700' : 'border-sky-200 bg-sky-50 text-sky-700'}`}><div className="flex gap-3"><div className="min-w-12 text-xs font-black">{event.allDay ? 'Día' : time(event.startsAt)}</div><div className="min-w-0 flex-1"><div className="truncate text-xs font-black">{event.title}</div><div className="mt-1 truncate text-[10px] opacity-70">{event.location ?? 'Sin ubicación'}</div><div className="mt-2 text-[9px] font-black uppercase">{pending ? 'Creado en Google · requiere autorización' : 'Evento externo autorizado'}</div></div></div>{pending && <div className="mt-3 flex flex-wrap justify-end gap-2 border-t border-red-200/60 pt-3"><Link href={reviewHref} className="rounded-lg bg-white/70 px-3 py-2 text-[10px] font-black">Revisar detalle</Link>{canApprove && event.pendingReviewId && <button onClick={onApprove} className="rounded-lg bg-red-700 px-3 py-2 text-[10px] font-black text-white"><Check size={12} className="mr-1 inline" /> Autorizar bloqueo</button>}</div>}</div>;
}

function DayAgendaCard({ meeting, onOpen, onApprove, onComplete, canApprove, canComplete, calendarConnected }: { meeting: Meeting; onOpen: () => void; onApprove: () => void; onComplete: () => void; canApprove: boolean; canComplete: boolean; calendarConnected: boolean }) {
    const travelRisk = meeting.mobility.before?.risk || meeting.mobility.after?.risk;
    return <div className={`rounded-xl border p-3 transition hover:shadow-sm ${statusStyles[meeting.status] ?? statusStyles.cancelled}`}><button onClick={onOpen} className="w-full text-left"><div className="flex items-start gap-3"><div className="min-w-12 text-xs font-black">{time(meeting.startsAt)}</div><div className="min-w-0 flex-1"><div className="truncate text-xs font-black">{meeting.title}</div><div className="mt-1 truncate text-[10px] opacity-70">{meeting.location ?? meeting.territory ?? 'Lugar por confirmar'}</div>{meeting.status === 'completed' && <div className="mt-2 flex flex-wrap gap-1 text-[10px] font-black opacity-75"><span>{meeting.actualAttendees} asistentes reales</span>{meeting.completedAt && <span>· {formatShortDate(meeting.completedAt)}</span>}</div>}<GoogleSyncLabel state={meeting.googleSync} />{meeting.hasPotentialConflict && <div className={`mt-2 flex items-center gap-1 text-[10px] font-black ${meeting.hasBlockingConflict ? 'text-red-700' : 'text-amber-700'}`}><AlertTriangle size={11} /> {meeting.hasBlockingConflict ? 'Cruce confirmado' : 'Posible cruce'}</div>}{meeting.hasResourceBlock && <div className="mt-2 flex items-center gap-1 text-[10px] font-black text-red-700"><PackageCheck size={11} /> Faltantes de inventario</div>}{travelRisk && <div className="mt-2 flex items-center gap-1 text-[10px] font-black text-red-700"><Route size={11} /> Traslado insuficiente</div>}</div></div></button>{meeting.status === 'requested' && canApprove && <div className="mt-3 flex flex-wrap justify-end gap-2 border-t border-current/10 pt-3"><button onClick={onOpen} className="rounded-lg bg-white/60 px-3 py-2 text-[10px] font-black">Revisar contexto</button><button onClick={onApprove} disabled={meeting.hasBlockingConflict || meeting.hasResourceBlock} className="rounded-lg bg-[var(--campaign-accent)] px-3 py-2 text-[10px] font-black text-[var(--campaign-contrast)] disabled:cursor-not-allowed disabled:opacity-40"><Check size={12} className="mr-1 inline" /> {meeting.hasResourceBlock ? 'Resolver faltantes' : calendarConnected ? 'Aprobar y enviar a Google' : 'Aprobar'}</button></div>}{meeting.status === 'approved' && canComplete && <div className="mt-3 flex justify-end border-t border-current/10 pt-3"><button onClick={onComplete} className="rounded-lg bg-white/70 px-3 py-2 text-[10px] font-black text-[var(--campaign-accent)] shadow-sm"><CheckCheck size={12} className="mr-1 inline" /> Marcar realizada</button></div>}</div>;
}

function GoogleSyncLabel({ state }: { state: Meeting['googleSync'] }) {
    const labels: Record<Meeting['googleSync'], string> = {
        after_approval: 'Se enviará a Google al aprobar',
        not_connected: 'Confirmada · Google no conectado',
        queued: 'Confirmada · envío a Google en curso',
        synced: 'Confirmada · sincronizada con Google',
        failed: 'Confirmada · error al sincronizar',
        not_applicable: '',
    };
    if (!labels[state]) return null;
    return <div className={`mt-2 flex items-center gap-1 text-[9px] font-black uppercase ${state === 'failed' ? 'text-red-700' : 'opacity-65'}`}><Cloud size={10} /> {labels[state]}</div>;
}

function ReviewDrawer({ meeting, dayMeetings, onClose, onEdit, onDelete, onComplete, canApprove, canComplete, canEdit, canDelete, calendarConnected }: {
    meeting: Meeting; dayMeetings: Meeting[]; onClose: () => void; onEdit: () => void; onDelete: () => void; onComplete: () => void; canApprove: boolean; canComplete: boolean; canEdit: boolean; canDelete: boolean; calendarConnected: boolean;
}) {
    const [rejecting, setRejecting] = useState(false);
    const [reason, setReason] = useState('');
    const sameDay = dayMeetings.filter((item) => item.id !== meeting.id);
    const approve = () => router.post(`/meetings/${meeting.id}/approve`, {}, { preserveScroll: true, onSuccess: onClose });
    const reject = () => {
        if (reason.trim()) router.post(`/meetings/${meeting.id}/reject`, { approval_notes: reason.trim() }, { preserveScroll: true, onSuccess: onClose });
    };

    return <div className="fixed inset-0 z-50 bg-[#102a33]/45 backdrop-blur-sm"><button className="absolute inset-0" onClick={onClose} aria-label="Cerrar detalle" /><aside className="absolute inset-y-0 right-0 w-full max-w-xl overflow-auto bg-[#f8f9f6] shadow-2xl">
        <div className="sticky top-0 z-10 flex items-start justify-between border-b border-slate-200 bg-white/95 p-5 backdrop-blur"><div><span className={`inline-flex rounded-full border px-2.5 py-1 text-[10px] font-black uppercase ${statusStyles[meeting.status]}`}>{statusLabels[meeting.status] ?? meeting.status}</span><h2 className="mt-3 text-xl font-black leading-tight text-[#102a33]">{meeting.title}</h2></div><button onClick={onClose} className="rounded-full bg-slate-100 p-2 text-slate-500"><X size={18} /></button></div>
        <div className="space-y-5 p-5">
            <section className="panel grid gap-4 p-5 sm:grid-cols-2">
                <Info icon={Clock3} label="Horario" value={`${formatShortDate(meeting.startsAt)} · ${time(meeting.startsAt)}–${time(meeting.endsAt)}`} />
                <Info icon={MapPin} label="Lugar" value={meeting.location ?? 'Por confirmar'} />
                <Info icon={Navigation} label="Dirección" value={meeting.address ?? 'Sin dirección registrada'} />
                <Info icon={UserRound} label="Líder solicitante" value={meeting.leader ?? 'Sin asignar'} />
                <Info icon={Users} label="Asistencia prevista" value={`${meeting.expectedAttendees} personas`} />
                {meeting.status === 'completed' && <Info icon={CheckCheck} label="Asistencia real" value={`${meeting.actualAttendees} personas`} />}
                <Info icon={MapPin} label="Territorio" value={meeting.territory ?? 'Sin asignar'} />
                <Info icon={CalendarDays} label="Tipo" value={meeting.type} />
            </section>
            {meeting.status === 'completed' && <section className="rounded-2xl border border-sky-200 bg-sky-50 p-5"><div className="flex items-start gap-3"><CheckCheck size={20} className="text-sky-700" /><div><h3 className="text-sm font-black text-sky-900">Reunión realizada</h3><p className="mt-1 text-xs leading-5 text-sky-700">Quedó registrada para mediciones de cumplimiento, asistencia y gestión territorial.</p>{meeting.outcome && <p className="mt-3 rounded-xl bg-white/70 p-3 text-xs font-semibold leading-5 text-sky-900">{meeting.outcome}</p>}</div></div></section>}
            {(meeting.address || meeting.latitude != null) && <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-sky-100 bg-sky-50 px-4 py-3"><div><div className="text-xs font-black text-sky-800">Ubicación para desplazamiento</div>{meeting.locationNotes && <div className="mt-1 text-[11px] text-sky-700">{meeting.locationNotes}</div>}</div><a href={mapHref(meeting)} target="_blank" rel="noreferrer" className="secondary-button border-sky-200 bg-white px-3 py-2 text-xs text-sky-800"><Navigation size={14} /> Abrir en mapa</a></div>}
            <section className={`rounded-2xl border p-5 ${meeting.hasResourceBlock ? 'border-red-200 bg-red-50' : 'border-slate-200 bg-white'}`}>
                <div className="flex items-start justify-between gap-3">
                    <div><h3 className={`text-sm font-black ${meeting.hasResourceBlock ? 'text-red-800' : 'text-slate-800'}`}>Recursos logísticos</h3><p className="mt-1 text-[11px] text-slate-500">{meeting.hasResourceBlock ? 'No puede aprobarse hasta resolver los faltantes.' : 'Disponibilidad comprobada para esta actividad y horario.'}</p></div>
                    <PackageCheck size={19} className={meeting.hasResourceBlock ? 'text-red-700' : 'text-[var(--campaign-accent)]'} />
                </div>
                <div className="mt-4 space-y-2">
                    {meeting.requirements.map((requirement) => (
                        <div key={requirement.id} className={`rounded-xl border p-3 ${requirement.shortage ? 'border-red-200 bg-white/70' : 'border-slate-100 bg-slate-50'}`}>
                            <div className="flex items-center justify-between gap-3"><div className="text-xs font-black text-slate-700">{requirement.name}</div><span className={`rounded-full px-2 py-1 text-[9px] font-black uppercase ${requirement.shortage ? 'bg-red-100 text-red-700' : ['consumed', 'reserved'].includes(requirement.status) ? 'bg-emerald-100 text-emerald-700' : requirement.mode === 'consume' ? 'bg-violet-100 text-violet-700' : 'bg-sky-100 text-sky-700'}`}>{requirement.shortage ? `Faltan ${requirement.missing}` : requirement.status === 'consumed' ? 'Descontado del inventario' : requirement.status === 'reserved' ? 'Reservado en esta franja' : requirement.mode === 'consume' ? 'Se consume al aprobar' : 'Se reserva al aprobar'}</span></div>
                            <div className="mt-1 text-[10px] text-slate-500">Solicita <strong>{requirement.required} {requirement.unit}</strong> · Disponible para esta decisión: <strong>{requirement.available} {requirement.unit}</strong></div>
                        </div>
                    ))}
                    {meeting.requirements.length === 0 && <p className="rounded-xl bg-slate-50 p-3 text-xs text-slate-400">Esta actividad no solicitó recursos del inventario.</p>}
                </div>
                {meeting.hasResourceBlock && <Link href="/inventory?alert=low" className="secondary-button mt-4 w-full justify-center border-red-200 bg-white text-red-700"><PackageCheck size={14} /> Ir al inventario para resolver</Link>}
            </section>
            <section className="panel flex items-start gap-3 p-4"><div className="grid size-9 shrink-0 place-items-center rounded-xl bg-sky-50 text-sky-700"><Cloud size={16} /></div><div className="min-w-0 flex-1"><h3 className="text-xs font-black text-slate-800">Publicación en Google Calendar</h3><GoogleSyncLabel state={meeting.googleSync} /><p className="mt-1 text-[10px] leading-4 text-slate-400">{meeting.status === 'requested' ? (calendarConnected ? 'No necesitas editarla ni hacer otra vinculación: al aprobarla se enviará automáticamente al calendario conectado.' : 'La aprobación es válida localmente; la publicación ocurrirá al conectar un calendario escribible.') : meeting.googleSync === 'queued' ? 'La publicación está siendo procesada. Este estado se actualizará automáticamente.' : 'Este estado corresponde a la reunión creada en Territorio, no a un evento externo pendiente de autorización.'}</p>{meeting.googleSync === 'synced' && meeting.googleHtmlLink && <a href={meeting.googleHtmlLink} target="_blank" rel="noreferrer" className="secondary-button mt-3 w-fit px-3 py-2 text-xs"><Cloud size={14} /> Abrir en Google Calendar</a>}</div></section>
            {meeting.objective && <section className="panel p-5"><h3 className="text-xs font-black uppercase tracking-wide text-slate-400">Objetivo</h3><p className="mt-2 text-sm leading-6 text-slate-600">{meeting.objective}</p></section>}

            {meeting.pendingChange && canApprove && <section className="rounded-2xl border border-violet-200 bg-violet-50 p-5"><div className="text-sm font-black text-violet-900">Cambio de horario pendiente</div><p className="mt-1 text-xs leading-5 text-violet-700">La agenda vigente y Google Calendar conservan el horario anterior hasta tomar esta decisión.</p><div className="mt-3 rounded-xl bg-white/70 p-3 text-xs font-bold text-violet-900">{meeting.pendingChange.changes.starts_at ? `${formatShortDate(meeting.pendingChange.changes.starts_at)} · ${time(meeting.pendingChange.changes.starts_at)}–${time(meeting.pendingChange.changes.ends_at ?? meeting.pendingChange.changes.starts_at)}` : 'Nuevo horario propuesto'}</div><div className="mt-3 flex justify-end gap-2"><button onClick={() => { const notes = window.prompt('Motivo del rechazo'); if (notes) router.post(`/meeting-changes/${meeting.pendingChange?.id}/reject`, { notes }); }} className="secondary-button border-red-200 text-red-700"><X size={14} /> Rechazar cambio</button><button onClick={() => router.post(`/meeting-changes/${meeting.pendingChange?.id}/approve`)} className="primary-button"><Check size={14} /> Aprobar cambio</button></div></section>}

            <section className={`rounded-2xl border p-5 ${meeting.hasBlockingConflict ? 'border-red-200 bg-red-50' : meeting.hasPotentialConflict ? 'border-amber-200 bg-amber-50' : 'border-emerald-200 bg-emerald-50'}`}>
                <div className="flex items-start gap-3">{meeting.hasPotentialConflict ? <AlertTriangle size={20} className={meeting.hasBlockingConflict ? 'text-red-700' : 'text-amber-700'} /> : <CheckCircle2 size={20} className="text-emerald-700" />}<div><h3 className={`text-sm font-black ${meeting.hasBlockingConflict ? 'text-red-800' : meeting.hasPotentialConflict ? 'text-amber-800' : 'text-emerald-800'}`}>{meeting.hasBlockingConflict ? 'Hay un cruce con agenda confirmada' : meeting.hasPotentialConflict ? 'Hay otra solicitud en este horario' : 'Horario disponible'}</h3><p className="mt-1 text-xs leading-5 opacity-70">{meeting.hasBlockingConflict ? 'Debes modificar el horario o rechazar la oportunidad antes de aprobarla.' : meeting.hasPotentialConflict ? 'Puedes aprobar, pero conviene revisar ambas oportunidades juntas.' : 'No se detectaron actividades simultáneas en la agenda visible.'}</p></div></div>
                {meeting.conflicts.length > 0 && <div className="mt-4 space-y-2">{meeting.conflicts.map((conflict) => <div key={conflict.id} className="flex w-full items-center justify-between rounded-xl bg-white/70 p-3 text-left"><div><div className="text-xs font-black text-slate-700">{conflict.title}</div><div className="mt-1 text-[10px] text-slate-500">{time(conflict.startsAt)}–{time(conflict.endsAt)} · {conflict.location ?? 'Lugar por confirmar'}</div></div><span className={`rounded-full px-2 py-1 text-[9px] font-black uppercase ${conflict.blocking ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700'}`}>{conflict.blocking ? 'Confirmada' : 'Solicitud'}</span></div>)}</div>}
            </section>

            <section className="panel p-5"><div className="flex items-center justify-between"><div><h3 className="text-sm font-black text-slate-800">Análisis de desplazamiento</h3><p className="mt-0.5 text-[11px] text-slate-400">Ventana disponible frente a la agenda confirmada.</p></div><Route size={18} className="text-[#0d4d4b]" /></div><div className="mt-4 space-y-3">{!meeting.mobility.before && !meeting.mobility.after && <p className="rounded-xl bg-slate-50 p-3 text-xs text-slate-400">No hay compromisos confirmados cercanos para comparar este día.</p>}{meeting.mobility.before && <TravelLegCard leg={meeting.mobility.before} direction="before" />}{meeting.mobility.after && <TravelLegCard leg={meeting.mobility.after} direction="after" />}</div><p className="mt-3 text-[10px] leading-4 text-slate-400">La estimación usa distancia en línea recta, velocidad operativa de 25 km/h y 10 minutos de margen. Debe confirmarse con una ruta real.</p></section>

            <section className="panel p-5"><div className="flex items-center justify-between"><div><h3 className="text-sm font-black text-slate-800">Contexto del día</h3><p className="mt-0.5 text-[11px] text-slate-400">Otras actividades para comparar desplazamientos y tiempos.</p></div><CalendarDays size={18} className="text-[#0d4d4b]" /></div><div className="mt-4 space-y-2">{sameDay.length === 0 && <p className="rounded-xl bg-slate-50 p-3 text-xs text-slate-400">No hay más actividades ese día.</p>}{sameDay.map((item) => <div key={item.id} className="flex gap-3 rounded-xl bg-slate-50 p-3"><div className="text-xs font-black text-slate-700">{time(item.startsAt)}</div><div className="min-w-0"><div className="truncate text-xs font-bold text-slate-600">{item.title}</div><div className="mt-1 text-[10px] text-slate-400">{item.location ?? 'Lugar por confirmar'}</div></div></div>)}</div></section>

            {meeting.status === 'requested' && canApprove && <section className="panel p-5"><h3 className="text-sm font-black text-slate-800">Tomar decisión</h3>{!rejecting ? <div className="mt-4 grid gap-2 sm:grid-cols-2"><button onClick={approve} disabled={meeting.hasBlockingConflict || meeting.hasResourceBlock} className="primary-button justify-center disabled:cursor-not-allowed disabled:opacity-40"><Check size={17} /> {meeting.hasResourceBlock ? 'Resolver faltantes antes de aprobar' : calendarConnected ? 'Aprobar y enviar a Google' : 'Aprobar oportunidad'}</button><button onClick={() => setRejecting(true)} className="secondary-button justify-center border-red-200 text-red-700 hover:bg-red-50"><XCircle size={17} /> Rechazar</button></div> : <div className="mt-4"><label className="label">Motivo del rechazo</label><textarea autoFocus rows={3} className="field resize-none" value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Explica brevemente por qué no se incorpora a la agenda…" /><div className="mt-3 flex justify-end gap-2"><button onClick={() => setRejecting(false)} className="secondary-button">Cancelar</button><button onClick={reject} disabled={!reason.trim()} className="primary-button bg-red-700 disabled:opacity-40">Confirmar rechazo</button></div></div>}</section>}
            <div className="flex flex-wrap justify-end gap-2 pb-4">{meeting.status === 'approved' && canComplete && <button onClick={onComplete} className="primary-button"><CheckCheck size={15} /> Marcar realizada</button>}{canEdit && <button onClick={onEdit} className="secondary-button"><Pencil size={15} /> Editar</button>}{canDelete && <button onClick={onDelete} className="secondary-button border-red-200 text-red-600 hover:bg-red-50"><Trash2 size={15} /> Eliminar</button>}</div>
        </div>
    </aside></div>;
}

function TravelLegCard({ leg, direction }: { leg: TravelLeg; direction: 'before' | 'after' }) {
    const related = direction === 'before' ? leg.from : leg.to;
    const tone = leg.assessment === 'insufficient'
        ? 'border-red-200 bg-red-50 text-red-800'
        : leg.assessment === 'tight'
            ? 'border-amber-200 bg-amber-50 text-amber-800'
            : leg.assessment === 'comfortable'
                ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                : 'border-slate-200 bg-slate-50 text-slate-600';
    return <div className={`rounded-xl border p-4 ${tone}`}><div className="flex items-start justify-between gap-3"><div><div className="text-[9px] font-black uppercase tracking-wide opacity-60">{direction === 'before' ? 'Actividad anterior' : 'Actividad siguiente'}</div><div className="mt-1 text-xs font-black">{related.title}</div><div className="mt-1 text-[10px] opacity-70">{related.location ?? 'Lugar por confirmar'}{related.address ? ` · ${related.address}` : ''}</div></div>{leg.risk && <AlertTriangle size={17} className="shrink-0" />}</div><div className="mt-3 flex flex-wrap gap-2 text-[10px] font-black"><span className="rounded-full bg-white/70 px-2 py-1">{leg.gapMinutes} min disponibles</span>{leg.distanceKm != null && <span className="rounded-full bg-white/70 px-2 py-1">≈ {leg.distanceKm} km</span>}{leg.estimatedMinutes != null && <span className="rounded-full bg-white/70 px-2 py-1">Traslado estimado: {leg.estimatedMinutes} min</span>}{leg.estimatedMinutes == null && <span className="rounded-full bg-white/70 px-2 py-1">Faltan coordenadas para estimar</span>}</div>{leg.assessment === 'insufficient' && <p className="mt-2 text-[10px] font-bold">El margen disponible es menor al traslado preliminar estimado.</p>}</div>;
}

function Metric({ icon: Icon, value, label, tone }: { icon: React.ElementType; value: number; label: string; tone: string }) {
    return <div className="panel flex items-center gap-3 p-4"><div className={`grid size-10 place-items-center rounded-xl ${tone}`}><Icon size={18} /></div><div><div className="text-xl font-black text-slate-800">{value}</div><div className="text-[11px] font-bold text-slate-500">{label}</div></div></div>;
}
function Info({ icon: Icon, label, value }: { icon: React.ElementType; label: string; value: string }) {
    return <div className="flex gap-3"><div className="grid size-8 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-500"><Icon size={15} /></div><div><div className="text-[9px] font-black uppercase tracking-wide text-slate-400">{label}</div><div className="mt-0.5 text-xs font-bold capitalize text-slate-700">{value}</div></div></div>;
}
function Legend({ tone, label }: { tone: string; label: string }) {
    return <span className="flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 text-slate-500 shadow-sm"><span className={`size-2 rounded-full ${tone}`} />{label}</span>;
}
function resourceKindLabel(kind: string): string {
    return {
        consumable: 'Consumible',
        asset: 'Activo retornable',
        equipment: 'Equipo audiovisual o técnico',
        service: 'Servicio con capacidad',
    }[kind] ?? kind;
}
function Field({ label, error, wide, children }: { label: string; error?: string; wide?: boolean; children: React.ReactNode }) {
    return <div className={wide ? 'md:col-span-2' : ''}><label className="label">{label}</label>{children}{error && <p className="mt-1 text-xs font-semibold text-red-600">{error}</p>}</div>;
}
function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: React.ReactNode }) {
    return <div className="fixed inset-0 z-50 flex items-end justify-center bg-[#102a33]/55 backdrop-blur-sm md:items-center md:p-6"><div className="max-h-[95vh] w-full max-w-2xl overflow-auto rounded-t-3xl bg-white shadow-2xl md:rounded-3xl"><div className="sticky top-0 z-10 flex items-center justify-between border-b border-slate-100 bg-white px-6 py-5"><h2 className="text-lg font-black">{title}</h2><button onClick={onClose} className="rounded-full bg-slate-100 p-2 text-slate-500"><X size={18} /></button></div>{children}</div></div>;
}

function parseDate(value: string): Date {
    const [year, month, day] = value.slice(0, 10).split('-').map(Number);
    return new Date(year, month - 1, day);
}
function dateKey(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}
function daysBetween(from: string, to: string): Date[] {
    const days: Date[] = [];
    const cursor = parseDate(from);
    const end = parseDate(to);
    while (cursor <= end) {
        days.push(new Date(cursor));
        cursor.setDate(cursor.getDate() + 1);
    }
    return days;
}
function time(value: string): string {
    return value.slice(11, 16);
}
function formatMonth(value: string): string {
    return parseDate(`${value}-01`).toLocaleDateString('es-CO', { month: 'long', year: 'numeric' });
}
function formatLongDate(value: string): string {
    return parseDate(value).toLocaleDateString('es-CO', { weekday: 'long', day: 'numeric', month: 'long' });
}
function formatShortDate(value: string): string {
    return parseDate(value).toLocaleDateString('es-CO', { weekday: 'short', day: 'numeric', month: 'short' });
}
function weekdayShort(value: string): string {
    return parseDate(value).toLocaleDateString('es-CO', { weekday: 'short' }).replace('.', '');
}
function mapHref(meeting: Meeting): string {
    if (meeting.latitude != null && meeting.longitude != null) {
        return `https://www.openstreetmap.org/?mlat=${meeting.latitude}&mlon=${meeting.longitude}#map=16/${meeting.latitude}/${meeting.longitude}`;
    }
    return `https://www.openstreetmap.org/search?query=${encodeURIComponent(`${meeting.address ?? ''} ${meeting.location ?? ''}`.trim())}`;
}
