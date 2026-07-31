import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, CalendarClock, Check, Clock3, ExternalLink, MapPin, RefreshCw, X } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';

type Snapshot = { title?: string; location?: string; starts_at?: string; ends_at?: string; all_day?: boolean; is_busy?: boolean; google_status?: string; html_link?: string };
type Review = {
    id: string; type: string; status: string; before?: Snapshot; after?: Snapshot; createdAt: string; notes?: string;
    calendar?: string; account?: string;
    event?: { title: string; location?: string; startsAt?: string; endsAt?: string; allDay: boolean; isBusy: boolean; htmlLink?: string; origin: string };
    meeting?: { id: string; title: string; startsAt?: string; endsAt?: string; address?: string; latitude?: number; longitude?: number };
    conflicts: Array<{ kind: string; id: string; title: string; starts_at: string; ends_at: string; location?: string }>;
};
type Paginated<T> = { data: T[]; links: Array<{ url?: string; label: string; active: boolean }>; total: number };

const typeLabel: Record<string, string> = { created: 'Nuevo evento', updated: 'Modificación', deleted: 'Eliminación' };

export default function CalendarReviews({ reviews, filters, summary }: {
    reviews: Paginated<Review>; filters: { status: string; review?: string }; summary: { pending: number; approved: number; rejected: number };
}) {
    const [rejecting, setRejecting] = useState<Review | null>(null);
    const [reason, setReason] = useState('');
    const reject = () => {
        if (!rejecting || !reason.trim()) return;
        router.post(`/calendar/reviews/${rejecting.id}/reject`, { notes: reason.trim() }, { preserveScroll: true, onSuccess: () => { setRejecting(null); setReason(''); } });
    };

    return (
        <AppLayout title="Cambios de Google Calendar" eyebrow="Bandeja de decisiones">
            <Head title="Cambios de Google Calendar" />
            <div className="mb-5 flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                <div><p className="text-sm text-slate-500">Los eventos ocupados bloquean inmediatamente, pero necesitan autorización expresa de Agenda.</p><div className="mt-3 flex gap-2"><Badge label="Pendientes" value={summary.pending} tone="amber" /><Badge label="Aprobados" value={summary.approved} tone="emerald" /><Badge label="Rechazados" value={summary.rejected} tone="slate" /></div></div>
                <div className="flex gap-2"><Link href="/calendar/settings" className="secondary-button"><RefreshCw size={15} /> Estado de conexión</Link><Link href="/meetings" className="primary-button"><CalendarClock size={16} /> Abrir agenda</Link></div>
            </div>
            <div className="mb-5 flex gap-2">
                {['pending', 'approved', 'rejected'].map((status) => <Link key={status} href={`/calendar/reviews?status=${status}`} className={`rounded-full px-3 py-1.5 text-xs font-black ${filters.status === status ? 'bg-[#102f35] text-white' : 'bg-white text-slate-500'}`}>{status === 'pending' ? 'Pendientes' : status === 'approved' ? 'Aprobados' : 'Rechazados'}</Link>)}
            </div>
            {filters.review && <div className="mb-4 flex items-center justify-between rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-xs font-bold text-sky-800"><span>Mostrando el cambio seleccionado desde la agenda.</span><Link href={`/calendar/reviews?status=${filters.status}`} className="underline">Ver toda la bandeja</Link></div>}

            <div className="space-y-4">
                {reviews.data.length === 0 && <div className="panel p-12 text-center"><Check className="mx-auto text-emerald-500" size={30} /><h2 className="mt-3 font-black">No hay cambios en esta bandeja</h2><p className="mt-1 text-sm text-slate-400">La agenda está al día.</p></div>}
                {reviews.data.map((review) => (
                    <article key={review.id} className="panel overflow-hidden">
                        <div className="flex flex-col gap-4 border-b border-slate-100 p-5 lg:flex-row lg:items-start">
                            <div className={`grid size-11 shrink-0 place-items-center rounded-xl ${review.type === 'deleted' ? 'bg-red-50 text-red-700' : review.type === 'updated' ? 'bg-sky-50 text-sky-700' : 'bg-amber-50 text-amber-700'}`}><CalendarClock size={20} /></div>
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2"><span className="text-[10px] font-black uppercase tracking-wide text-[#e8754f]">{typeLabel[review.type] ?? review.type}</span><span className="text-[10px] text-slate-400">{review.calendar} · {review.account}</span></div>
                                <h2 className="mt-1 text-lg font-black text-[#102a33]">{review.event?.title ?? review.after?.title ?? review.before?.title}</h2>
                                <div className="mt-2 flex flex-wrap gap-3 text-xs text-slate-500"><span className="flex items-center gap-1"><Clock3 size={13} /> {eventTime(review)}</span><span className="flex items-center gap-1"><MapPin size={13} /> {review.event?.location ?? 'Sin ubicación'}</span>{review.event?.allDay && <span className="rounded-full bg-violet-50 px-2 py-0.5 font-bold text-violet-700">Todo el día</span>}{!review.event?.isBusy && <span className="rounded-full bg-slate-100 px-2 py-0.5 font-bold">Disponible</span>}</div>
                            </div>
                            {review.event?.htmlLink && <a href={review.event.htmlLink} target="_blank" rel="noreferrer" className="secondary-button px-3 py-2 text-xs">Abrir en Google <ExternalLink size={13} /></a>}
                        </div>

                        <div className="grid gap-5 p-5 lg:grid-cols-[1fr_.8fr]">
                            <div>
                                {review.type === 'updated' && <Comparison before={review.before} after={review.after} />}
                                {review.type !== 'updated' && <SnapshotCard title={review.type === 'deleted' ? 'Última versión conocida' : 'Bloqueo provisional'} value={review.type === 'deleted' ? review.before : review.after} />}
                            </div>
                            <div className="space-y-3">
                                {review.conflicts.length > 0 ? <div className="rounded-xl border border-red-200 bg-red-50 p-4"><div className="flex items-center gap-2 text-xs font-black text-red-800"><AlertTriangle size={15} /> Debes resolver {review.conflicts.length} cruce(s)</div>{review.conflicts.map((conflict) => <div key={`${conflict.kind}-${conflict.id}`} className="mt-2 rounded-lg bg-white/70 p-3 text-xs"><b>{conflict.title}</b><div className="mt-1 text-red-700">{formatRange(conflict.starts_at, conflict.ends_at)} · {conflict.location ?? 'Sin ubicación'}</div></div>)}</div> : <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-xs font-bold text-emerald-800">No se detectaron cruces bloqueantes.</div>}
                                {review.meeting && <div className="rounded-xl bg-sky-50 p-4 text-xs text-sky-800"><b>Reunión vinculada:</b> {review.meeting.title}{review.event?.location !== review.meeting.address && <p className="mt-1">La ubicación textual cambió en Google; verifica el punto del mapa antes de decidir.</p>}</div>}
                                {review.status === 'pending' && <div className="flex flex-wrap justify-end gap-2"><button onClick={() => { setRejecting(review); setReason(''); }} className="secondary-button border-red-200 text-red-700"><X size={15} /> Rechazar</button><button onClick={() => router.post(`/calendar/reviews/${review.id}/approve`)} disabled={review.conflicts.length > 0} className="primary-button disabled:cursor-not-allowed disabled:opacity-40"><Check size={15} /> Autorizar</button></div>}
                            </div>
                        </div>
                    </article>
                ))}
            </div>

            {rejecting && <div className="fixed inset-0 z-50 grid place-items-center bg-[#102a33]/50 p-4 backdrop-blur-sm"><div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl"><h2 className="text-lg font-black">Rechazar cambio de Google</h2><p className="mt-2 text-xs leading-5 text-slate-500">La plataforma eliminará el evento nuevo o restaurará la última versión aprobada en Google Calendar.</p><label className="label mt-5">Motivo obligatorio</label><textarea autoFocus rows={4} className="field resize-none" value={reason} onChange={(event) => setReason(event.target.value)} /><div className="mt-4 flex justify-end gap-2"><button onClick={() => setRejecting(null)} className="secondary-button">Cancelar</button><button onClick={reject} disabled={!reason.trim()} className="primary-button bg-red-700 disabled:opacity-40">Confirmar rechazo</button></div></div></div>}
        </AppLayout>
    );
}

function Comparison({ before, after }: { before?: Snapshot; after?: Snapshot }) {
    return <div className="grid items-center gap-2 md:grid-cols-[1fr_auto_1fr]"><SnapshotCard title="Versión vigente" value={before} /><ArrowRight className="mx-auto text-slate-300" size={18} /><SnapshotCard title="Cambio en Google" value={after} /></div>;
}
function SnapshotCard({ title, value }: { title: string; value?: Snapshot }) {
    return <div className="rounded-xl border border-slate-100 bg-slate-50 p-4"><div className="text-[9px] font-black uppercase tracking-wide text-slate-400">{title}</div><div className="mt-2 text-sm font-black text-slate-700">{value?.title ?? '—'}</div><div className="mt-2 text-xs text-slate-500">{value?.starts_at && value?.ends_at ? formatRange(value.starts_at, value.ends_at) : 'Sin horario'}</div><div className="mt-1 text-xs text-slate-400">{value?.location ?? 'Sin ubicación'}</div></div>;
}
function Badge({ label, value, tone }: { label: string; value: number; tone: string }) {
    const styles: Record<string, string> = { amber: 'bg-amber-50 text-amber-800', emerald: 'bg-emerald-50 text-emerald-800', slate: 'bg-slate-100 text-slate-600' };
    return <span className={`rounded-full px-3 py-1 text-[10px] font-black ${styles[tone]}`}>{value} {label}</span>;
}
function eventTime(review: Review): string {
    const start = review.event?.startsAt ?? review.after?.starts_at ?? review.before?.starts_at;
    const end = review.event?.endsAt ?? review.after?.ends_at ?? review.before?.ends_at;
    return start && end ? formatRange(start, end) : 'Sin horario';
}
function formatRange(start: string, end: string): string {
    const from = new Date(start); const to = new Date(end);
    return `${from.toLocaleDateString('es-CO', { day: 'numeric', month: 'short' })} · ${from.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' })}–${to.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' })}`;
}
