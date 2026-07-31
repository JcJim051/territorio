import { Head } from '@inertiajs/react';
import { CalendarDays, Clock3, MapPin, Navigation, Route } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';

type Trip = {
    id: string;
    date: string;
    startsAt: string;
    endsAt: string;
    place?: string;
    address?: string;
    directions?: string;
    googleMapsUrl: string;
    wazeUrl: string;
};

export default function DriverRoutes({ days, dates, nextMeetingId }: {
    days: number;
    dates: Record<string, Trip[]>;
    nextMeetingId?: string;
}) {
    const formatDate = (date: string) => new Intl.DateTimeFormat('es-CO', { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date(`${date}T12:00:00`));
    const formatTime = (date: string) => new Intl.DateTimeFormat('es-CO', { hour: 'numeric', minute: '2-digit' }).format(new Date(date));

    return <AppLayout title="Traslados" eyebrow={`Próximos ${days} días`}>
        <Head title="Traslados" />
        <div className="mx-auto max-w-3xl space-y-7">
            {Object.entries(dates).map(([date, trips]) => <section key={date}>
                <h2 className="mb-3 flex items-center gap-2 text-sm font-black capitalize text-slate-700"><CalendarDays size={17} className="text-[var(--campaign-accent)]" />{formatDate(date)}</h2>
                <div className="space-y-3">
                    {trips.map((trip) => {
                        const next = trip.id === nextMeetingId;
                        return <article key={trip.id} className={`panel overflow-hidden border-2 ${next ? 'border-[var(--campaign-accent)]' : 'border-transparent'}`}>
                            {next && <div className="bg-[var(--campaign-accent)] px-4 py-2 text-[10px] font-black uppercase tracking-widest text-[var(--campaign-contrast)]">Próximo traslado</div>}
                            <div className="p-5">
                                <div className="flex items-center gap-2 text-lg font-black text-slate-800"><Clock3 size={19} />{formatTime(trip.startsAt)} – {formatTime(trip.endsAt)}</div>
                                <div className="mt-4 flex items-start gap-3"><MapPin size={19} className="mt-0.5 shrink-0 text-[var(--campaign-accent)]" /><div><div className="font-black text-slate-800">{trip.place || 'Ubicación indicada'}</div><div className="mt-1 text-sm text-slate-500">{trip.address || 'Consulta el destino en el mapa'}</div>{trip.directions && <p className="mt-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-900">{trip.directions}</p>}</div></div>
                                <div className="mt-5 grid grid-cols-2 gap-3">
                                    <a href={trip.wazeUrl} target="_blank" rel="noreferrer" className="flex items-center justify-center gap-2 rounded-xl bg-[#33ccff] px-4 py-3 text-sm font-black text-slate-900"><Navigation size={18} /> Waze</a>
                                    <a href={trip.googleMapsUrl} target="_blank" rel="noreferrer" className="flex items-center justify-center gap-2 rounded-xl bg-slate-900 px-4 py-3 text-sm font-black text-white"><Route size={18} /> Google Maps</a>
                                </div>
                            </div>
                        </article>;
                    })}
                </div>
            </section>)}
            {Object.keys(dates).length === 0 && <div className="panel p-12 text-center"><Route className="mx-auto text-slate-300" size={34} /><h2 className="mt-4 font-black text-slate-700">No hay traslados programados</h2><p className="mt-1 text-sm text-slate-400">Solo aparecen actividades aprobadas dentro del horizonte configurado.</p></div>}
        </div>
    </AppLayout>;
}
