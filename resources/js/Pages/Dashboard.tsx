import { Head, Link } from '@inertiajs/react';
import {
    ArrowUpRight,
    CalendarClock,
    CheckCircle2,
    CircleAlert,
    GitBranch,
    MapPinned,
    PackageSearch,
    Sparkles,
    UserCheck,
    UsersRound,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';

type Props = {
    metrics: {
        people: number;
        verified: number;
        networkLinks: number;
        pendingMeetings: number;
        coverage: number;
        inventoryAlerts: number;
    };
    upcomingMeetings: Array<{
        id: string;
        title: string;
        type: string;
        status: string;
        startsAt: string;
        location?: string;
        expectedAttendees: number;
        leader?: string;
        territory?: string;
    }>;
    territories: Array<{ label: string; total: number }>;
    growth: Array<{ day: string; total: number }>;
    decisionQueue: Array<{ type: string; count: number; label: string; href: string; action: string }>;
    capabilities: { territorial: boolean; meetings: boolean; inventory: boolean };
};

const formatNumber = new Intl.NumberFormat('es-CO').format;

export default function Dashboard({ metrics, upcomingMeetings, territories, decisionQueue, capabilities }: Props) {
    const maxTerritory = Math.max(...territories.map((item) => item.total), 1);
    const metricCards = [
        { label: 'Personas en la red', value: metrics.people, note: `${metrics.verified} verificadas`, icon: UsersRound, tone: 'bg-[#d9f0e8] text-[#0d4d4b]' },
        { label: 'Conexiones activas', value: metrics.networkLinks, note: 'estructura territorial', icon: GitBranch, tone: 'bg-orange-50 text-[#d65f3a]' },
        { label: 'Comunas cubiertas', value: metrics.coverage, note: 'con presencia registrada', icon: MapPinned, tone: 'bg-sky-50 text-sky-700' },
        { label: 'Agenda por decidir', value: metrics.pendingMeetings, note: `${metrics.inventoryAlerts} alertas de inventario`, icon: CalendarClock, tone: 'bg-violet-50 text-violet-700' },
    ];

    return (
        <AppLayout title="Centro de mando" eyebrow="Panorama de hoy">
            <Head title="Centro de mando" />

            <section className="mb-7 flex flex-col justify-between gap-4 rounded-2xl bg-[#102f35] p-6 text-white md:flex-row md:items-center md:p-7">
                <div>
                    <div className="mb-2 flex items-center gap-2 text-xs font-bold uppercase tracking-[.16em] text-[#a8d7c8]"><Sparkles size={15} /> Foco gerencial</div>
                    <h2 className="text-2xl font-black tracking-tight">La operación territorial está lista para crecer.</h2>
                    <p className="mt-2 max-w-2xl text-sm leading-6 text-white/55">Revisa primero las decisiones pendientes y los territorios con oportunidad de expansión.</p>
                </div>
                {capabilities.territorial && <Link href="/territorial/network" className="secondary-button shrink-0 border-0 bg-white text-[#102f35] hover:bg-[#d9f0e8]">
                    Explorar la red <ArrowUpRight size={17} />
                </Link>}
            </section>

            <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {metricCards.map(({ label, value, note, icon: Icon, tone }) => (
                    <article key={label} className="panel p-5">
                        <div className="flex items-start justify-between">
                            <div className={`grid size-10 place-items-center rounded-xl ${tone}`}><Icon size={19} /></div>
                            <span className="rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-bold text-emerald-700">Actualizado</span>
                        </div>
                        <div className="mt-5 text-3xl font-black tracking-tight">{formatNumber(value)}</div>
                        <div className="mt-1 text-sm font-bold text-slate-700">{label}</div>
                        <div className="mt-1 text-xs text-slate-400">{note}</div>
                    </article>
                ))}
            </section>

            <section className="mt-6 grid gap-6 xl:grid-cols-[1.45fr_.85fr]">
                <article className="panel overflow-hidden">
                    <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                        <div>
                            <h3 className="font-black">Próximas actividades</h3>
                            <p className="mt-0.5 text-xs text-slate-400">Agenda operativa del candidato y delegados</p>
                        </div>
                        {capabilities.meetings && <Link href="/meetings" className="text-xs font-bold text-[#0d4d4b] hover:underline">Ver agenda completa</Link>}
                    </div>
                    <div className="divide-y divide-slate-100">
                        {upcomingMeetings.length === 0 && <div className="p-10 text-center text-sm text-slate-400">No hay actividades próximas.</div>}
                        {upcomingMeetings.map((meeting) => {
                            const date = new Date(meeting.startsAt);
                            return (
                                <div key={meeting.id} className="flex items-center gap-4 px-5 py-4">
                                    <div className="w-12 shrink-0 rounded-xl bg-[#f4f1e8] py-2 text-center">
                                        <div className="text-[10px] font-black uppercase text-[#e8754f]">{date.toLocaleDateString('es-CO', { month: 'short' })}</div>
                                        <div className="text-xl font-black leading-5">{date.getDate()}</div>
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate text-sm font-black text-slate-800">{meeting.title}</div>
                                        <div className="mt-1 truncate text-xs text-slate-400">
                                            {date.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' })} · {meeting.location ?? meeting.territory ?? 'Lugar por confirmar'}
                                        </div>
                                    </div>
                                    <div className="hidden text-right sm:block">
                                        <span className={`rounded-full px-2.5 py-1 text-[10px] font-black uppercase ${
                                            meeting.status === 'approved' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'
                                        }`}>{meeting.status === 'approved' ? 'Aprobada' : 'Pendiente'}</span>
                                        <div className="mt-1 text-[11px] text-slate-400">{meeting.expectedAttendees} personas</div>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </article>

                <article className="panel p-5">
                    <div className="flex items-center justify-between">
                        <div>
                            <h3 className="font-black">Bandeja de decisiones</h3>
                            <p className="mt-0.5 text-xs text-slate-400">Asuntos que requieren atención</p>
                        </div>
                        <CircleAlert size={20} className="text-[#e8754f]" />
                    </div>
                    <div className="mt-5 space-y-3">
                        {decisionQueue.map((item) => {
                            const Icon = item.type === 'meeting' || item.type === 'calendar' ? CalendarClock : item.type === 'people' ? UserCheck : PackageSearch;
                            return (
                                <Link key={item.type} href={item.href} className="group flex items-center gap-3 rounded-xl border border-slate-100 p-3.5 transition hover:border-[#a8d7c8] hover:bg-[#f7fbf9]">
                                    <div className="grid size-9 place-items-center rounded-lg bg-slate-50 text-slate-600"><Icon size={17} /></div>
                                    <div className="flex-1">
                                        <div className="text-sm font-semibold text-slate-600">{item.label}</div>
                                        <div className="mt-0.5 text-[10px] font-bold text-[#0d4d4b] opacity-0 transition group-hover:opacity-100">{item.action}</div>
                                    </div>
                                    <div className="grid min-w-7 place-items-center rounded-full bg-[#102f35] px-2 py-1 text-xs font-black text-white">{item.count}</div>
                                    <ArrowUpRight size={15} className="text-slate-300 transition group-hover:text-[#0d4d4b]" />
                                </Link>
                            );
                        })}
                    </div>
                    <div className="mt-5 flex items-center gap-2 rounded-xl bg-[#d9f0e8]/70 p-3 text-xs leading-5 text-[#0d4d4b]">
                        <CheckCircle2 size={17} className="shrink-0" />
                        Las decisiones quedan auditadas y asociadas a la campaña activa.
                    </div>
                </article>
            </section>

            <section className="mt-6 grid gap-6 lg:grid-cols-2">
                <article className="panel p-5">
                    <div className="flex items-center justify-between">
                        <div>
                            <h3 className="font-black">Presencia por territorio</h3>
                            <p className="mt-0.5 text-xs text-slate-400">Personas registradas por comuna</p>
                        </div>
                        <MapPinned size={19} className="text-[#0d4d4b]" />
                    </div>
                    <div className="mt-5 space-y-4">
                        {territories.map((territory) => (
                            <div key={territory.label}>
                                <div className="mb-1.5 flex items-center justify-between text-xs">
                                    <span className="font-bold text-slate-600">{territory.label}</span>
                                    <span className="font-black text-slate-800">{territory.total}</span>
                                </div>
                                <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                                    <div className="h-full rounded-full bg-[#0d4d4b]" style={{ width: `${Math.max(7, territory.total / maxTerritory * 100)}%` }} />
                                </div>
                            </div>
                        ))}
                    </div>
                </article>

                <article className="panel flex min-h-64 flex-col justify-between overflow-hidden bg-[#f4f1e8] p-6">
                    <div>
                        <div className="text-[10px] font-black uppercase tracking-[.17em] text-[#e8754f]">Lectura estratégica</div>
                        <h3 className="mt-3 max-w-md text-2xl font-black leading-tight tracking-tight">La red no solo mide volumen: revela capacidad real de movilización.</h3>
                        <p className="mt-3 max-w-xl text-sm leading-6 text-slate-500">Cruza profundidad, actividad territorial, reuniones y recursos para priorizar líderes y sectores.</p>
                    </div>
                    <div className="mt-8 flex gap-2">
                        {capabilities.territorial && <Link href="/territorial/network" className="primary-button">Abrir análisis territorial</Link>}
                    </div>
                </article>
            </section>
        </AppLayout>
    );
}
