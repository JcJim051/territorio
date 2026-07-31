import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    CalendarDays,
    CheckCircle2,
    GitBranch,
    Mail,
    MapPin,
    Network,
    Phone,
    ShieldCheck,
    UserRound,
    UsersRound,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { SharedProps } from '@/types';

type RelatedPerson = { id: string; name: string; status: string };
type Role = { role: string; title?: string; status: string; territory?: string; approved_at?: string };
type Meeting = {
    id: string;
    title: string;
    type: string;
    status: string;
    isReferralNode: boolean;
    startsAt: string;
    location?: string;
    expectedAttendees: number;
    actualAttendees: number;
};
type Person = {
    id: string;
    name: string;
    email?: string;
    phone?: string;
    document: string;
    status: string;
    verifiedAt?: string;
    createdAt: string;
    place?: string;
    commune?: string;
    placeAddress?: string;
    table?: number;
    parent?: RelatedPerson;
    directReferrals: RelatedPerson[];
    metrics: {
        directReferrals: number;
        descendants: number;
        networkDepth: number;
        meetingsLed: number;
        attendances: number;
    };
    consent?: { version: string; channel: string; acceptedAt: string };
    roles: Role[];
    meetings: Meeting[];
};

const statusLabels: Record<string, string> = {
    pending: 'Pendiente',
    verified: 'Verificada',
    active: 'Activa',
    inactive: 'Inactiva',
    rejected: 'Rechazada',
    withdrawn: 'Retirada',
    requested: 'Solicitada',
    approved: 'Aprobada',
    completed: 'Finalizada',
    cancelled: 'Cancelada',
};

export default function Show({ person }: { person: Person }) {
    const { currentCampaign } = usePage<SharedProps>().props;
    const canManageTokens = currentCampaign?.permissions.includes('*') || currentCampaign?.permissions.includes('territorial.tokens.manage');
    return (
        <AppLayout title="Ficha 360°" eyebrow="Gestión territorial integral">
            <Head title={`Ficha 360° · ${person.name}`} />

            <Link href="/territorial/network" className="mb-4 inline-flex items-center gap-2 text-xs font-bold text-slate-500 hover:text-slate-800">
                <ArrowLeft size={15} /> Volver a la red territorial
            </Link>

            <section className="panel overflow-hidden">
                <div className="bg-[#102f35] p-6 text-white md:p-8">
                    <div className="flex flex-col gap-5 sm:flex-row sm:items-center">
                        <div className="grid size-20 shrink-0 place-items-center rounded-3xl bg-[#d9f0e8] text-2xl font-black text-[#0d4d4b]">
                            {initials(person.name)}
                        </div>
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-2xl font-black md:text-3xl">{person.name}</h1>
                                <Status value={person.status} />
                                {person.isReferralNode && <span className="rounded-full bg-[#d9f0e8] px-2.5 py-1 text-[9px] font-black uppercase text-[#0d4d4b]">Nodo de referidos</span>}
                            </div>
                            <p className="mt-2 text-sm text-white/65">
                                {person.commune ?? 'Sin comuna'} · {person.place ?? 'Sin puesto'}{person.table ? ` · Mesa ${person.table}` : ''}
                            </p>
                        </div>
                        <div className="rounded-2xl bg-white/10 px-4 py-3 text-sm">
                            <div className="text-[10px] font-black uppercase tracking-wider text-white/45">Documento</div>
                            <div className="mt-1 font-bold">C.C. {person.document}</div>
                        </div>
                        {canManageTokens && <Link href={`/territorial/nodes?search=${encodeURIComponent(person.name)}`} className="inline-flex items-center justify-center rounded-xl bg-white px-4 py-3 text-xs font-black text-[#102f35]">{person.isReferralNode ? 'Gestionar enlace' : 'Promover a nodo'}</Link>}
                    </div>
                </div>

                <div className="grid gap-px bg-slate-100 sm:grid-cols-2 lg:grid-cols-5">
                    <Metric icon={<UsersRound size={18} />} label="Referidos directos" value={person.metrics.directReferrals} />
                    <Metric icon={<Network size={18} />} label="Red descendiente" value={person.metrics.descendants} />
                    <Metric icon={<GitBranch size={18} />} label="Niveles bajo el nodo" value={person.metrics.networkDepth} />
                    <Metric icon={<CalendarDays size={18} />} label="Reuniones lideradas" value={person.metrics.meetingsLed} />
                    <Metric icon={<CheckCircle2 size={18} />} label="Asistencias" value={person.metrics.attendances} />
                </div>
            </section>

            <div className="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
                <div className="space-y-5">
                    <section className="panel p-5 md:p-6">
                        <h2 className="text-sm font-black text-slate-800">Estructura territorial</h2>
                        <div className="mt-4 rounded-2xl border border-slate-100 bg-slate-50 p-4">
                            <div className="text-[10px] font-black uppercase tracking-wider text-slate-400">Nodo superior</div>
                            {person.parent ? (
                                <Link href={`/people/${person.parent.id}`} className="mt-2 flex items-center gap-3 rounded-xl bg-white p-3 shadow-sm transition hover:-translate-y-0.5">
                                    <Avatar name={person.parent.name} />
                                    <div><div className="text-sm font-black text-slate-700">{person.parent.name}</div><div className="text-[10px] text-slate-400">{statusLabels[person.parent.status] ?? person.parent.status}</div></div>
                                </Link>
                            ) : <p className="mt-2 text-sm font-semibold text-slate-500">Este es un nodo raíz de la estructura.</p>}
                        </div>

                        <div className="mt-5 flex items-end justify-between">
                            <div><h3 className="text-xs font-black uppercase tracking-wider text-slate-500">Referidos directos</h3><p className="mt-1 text-xs text-slate-400">Personas vinculadas directamente por este nodo.</p></div>
                            <span className="rounded-full bg-[#d9f0e8] px-2.5 py-1 text-xs font-black text-[#0d4d4b]">{person.directReferrals.length}</span>
                        </div>
                        <div className="mt-3 grid gap-2 sm:grid-cols-2">
                            {person.directReferrals.map((referral) => (
                                <Link key={referral.id} href={`/people/${referral.id}`} className="flex items-center gap-3 rounded-xl border border-slate-100 p-3 transition hover:border-[#0d4d4b]/30 hover:bg-[#d9f0e8]/20">
                                    <Avatar name={referral.name} />
                                    <div className="min-w-0"><div className="truncate text-xs font-black text-slate-700">{referral.name}</div><div className="text-[10px] text-slate-400">{statusLabels[referral.status] ?? referral.status}</div></div>
                                </Link>
                            ))}
                            {person.directReferrals.length === 0 && <p className="py-5 text-sm text-slate-400 sm:col-span-2">Este nodo todavía no tiene referidos directos.</p>}
                        </div>
                    </section>

                    <section className="panel p-5 md:p-6">
                        <h2 className="text-sm font-black text-slate-800">Actividad política</h2>
                        <div className="mt-4 space-y-2">
                            {person.meetings.map((meeting) => (
                                <Link key={meeting.id} href={`/meetings?meeting=${meeting.id}`} className="flex flex-col gap-3 rounded-2xl border border-slate-100 p-4 transition hover:bg-slate-50 sm:flex-row sm:items-center">
                                    <div className="grid size-11 shrink-0 place-items-center rounded-xl bg-[#f4f1e8] text-[#e8754f]"><CalendarDays size={19} /></div>
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate text-sm font-black text-slate-700">{meeting.title}</div>
                                        <div className="mt-1 text-[11px] text-slate-400">{formatDate(meeting.startsAt)}{meeting.location ? ` · ${meeting.location}` : ''}</div>
                                    </div>
                                    <Status value={meeting.status} />
                                </Link>
                            ))}
                            {person.meetings.length === 0 && <p className="py-7 text-center text-sm text-slate-400">No hay reuniones lideradas por esta persona.</p>}
                        </div>
                    </section>
                </div>

                <aside className="space-y-5">
                    <section className="panel p-5">
                        <h2 className="text-sm font-black text-slate-800">Información de contacto</h2>
                        <Info icon={<Phone size={16} />} label="Teléfono" value={person.phone ?? 'Sin registrar'} />
                        <Info icon={<Mail size={16} />} label="Correo" value={person.email ?? 'Sin registrar'} />
                        <Info icon={<MapPin size={16} />} label="Ubicación electoral" value={[person.commune, person.place, person.table ? `Mesa ${person.table}` : null].filter(Boolean).join(' · ') || 'Sin registrar'} />
                    </section>

                    <section className="panel p-5">
                        <h2 className="text-sm font-black text-slate-800">Roles territoriales</h2>
                        <div className="mt-3 space-y-2">
                            {person.roles.map((role, index) => (
                                <div key={`${role.role}-${index}`} className="rounded-xl bg-[#d9f0e8]/45 p-3">
                                    <div className="text-xs font-black text-[#0d4d4b]">{role.title ?? role.role}</div>
                                    <div className="mt-1 text-[10px] text-slate-500">{role.territory ?? 'Cobertura general'} · {statusLabels[role.status] ?? role.status}</div>
                                </div>
                            ))}
                            {person.roles.length === 0 && <p className="py-3 text-xs text-slate-400">Sin rol territorial asignado.</p>}
                        </div>
                    </section>

                    <section className="panel p-5">
                        <div className="flex items-center gap-2"><ShieldCheck size={17} className="text-emerald-600" /><h2 className="text-sm font-black text-slate-800">Consentimiento</h2></div>
                        {person.consent ? (
                            <div className="mt-3 rounded-xl bg-emerald-50 p-3 text-xs text-emerald-800">
                                <div className="font-black">Consentimiento vigente</div>
                                <div className="mt-1 text-emerald-700/70">Versión {person.consent.version} · {formatDate(person.consent.acceptedAt)}</div>
                            </div>
                        ) : <p className="mt-3 rounded-xl bg-amber-50 p-3 text-xs font-semibold text-amber-700">No hay consentimiento vigente registrado.</p>}
                    </section>
                </aside>
            </div>
        </AppLayout>
    );
}

function Metric({ icon, label, value }: { icon: React.ReactNode; label: string; value: number }) {
    return <div className="bg-white p-5"><div className="text-[#0d4d4b]">{icon}</div><div className="mt-3 text-2xl font-black text-slate-800">{value}</div><div className="mt-1 text-[10px] font-black uppercase tracking-wider text-slate-400">{label}</div></div>;
}

function Info({ icon, label, value }: { icon: React.ReactNode; label: string; value: string }) {
    return <div className="mt-4 flex gap-3"><div className="mt-0.5 text-[#e8754f]">{icon}</div><div className="min-w-0"><div className="text-[10px] font-black uppercase tracking-wider text-slate-400">{label}</div><div className="mt-1 break-words text-xs font-semibold text-slate-600">{value}</div></div></div>;
}

function Avatar({ name }: { name: string }) {
    return <div className="grid size-9 shrink-0 place-items-center rounded-xl bg-[#d9f0e8] text-[11px] font-black text-[#0d4d4b]">{initials(name)}</div>;
}

function Status({ value }: { value: string }) {
    return <span className={`inline-flex w-fit rounded-full px-2.5 py-1 text-[9px] font-black uppercase tracking-wide ${['verified', 'active', 'approved', 'completed'].includes(value) ? 'bg-emerald-100 text-emerald-700' : value === 'pending' || value === 'requested' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600'}`}>{statusLabels[value] ?? value}</span>;
}

function initials(name: string): string {
    return name.split(' ').filter(Boolean).map((part) => part[0]).slice(0, 2).join('').toLocaleUpperCase();
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}
