import { Head, Link, router, usePage } from '@inertiajs/react';
import { Bell, CheckCheck, Clock3, ExternalLink } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { SharedProps } from '@/types';

type NotificationItem = {
    id: string;
    title: string;
    message: string;
    href: string;
    category: string;
    readAt?: string;
    createdAt?: string;
};

type Paginated<T> = {
    data: T[];
    links: Array<{ url?: string; label: string; active: boolean }>;
};

export default function NotificationsIndex({ notifications, filters }: { notifications: Paginated<NotificationItem>; filters: { status: string } }) {
    const { currentCampaign } = usePage<SharedProps>().props;

    const openNotification = (notification: NotificationItem) => {
        router.post(`/notifications/${notification.id}/read`, {}, {
            preserveScroll: true,
            onSuccess: () => router.visit(notification.href),
        });
    };

    return (
        <AppLayout title="Notificaciones" eyebrow={`Bandeja de ${currentCampaign?.candidateName ?? 'campaña'}`}>
            <Head title="Notificaciones" />

            <div className="mb-5 flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                <div className="flex flex-wrap gap-2">
                    <Link href="/notifications" className={`rounded-xl border px-4 py-2 text-xs font-black ${filters.status !== 'unread' ? 'border-[var(--campaign-accent)] bg-[var(--campaign-accent)] text-[var(--campaign-contrast)]' : 'border-slate-200 bg-white text-slate-500'}`}>Todas</Link>
                    <Link href="/notifications?status=unread" className={`rounded-xl border px-4 py-2 text-xs font-black ${filters.status === 'unread' ? 'border-[var(--campaign-accent)] bg-[var(--campaign-accent)] text-[var(--campaign-contrast)]' : 'border-slate-200 bg-white text-slate-500'}`}>No leídas</Link>
                </div>
                <button onClick={() => router.post('/notifications/read-all', {}, { preserveScroll: true })} className="secondary-button w-full justify-center sm:w-auto"><CheckCheck size={16} /> Marcar todo leído</button>
            </div>

            <section className="panel overflow-hidden">
                <div className="border-b border-slate-100 px-5 py-4">
                    <div className="flex items-center gap-2 text-sm font-black text-slate-800"><Bell size={17} /> Actividad de campaña</div>
                    <p className="mt-1 text-xs text-slate-400">Solo ves notificaciones de la campaña seleccionada.</p>
                </div>
                <div className="divide-y divide-slate-100">
                    {notifications.data.map((notification) => (
                        <button key={notification.id} onClick={() => openNotification(notification)} className={`flex w-full items-start gap-4 px-5 py-4 text-left transition hover:bg-slate-50 ${notification.readAt ? 'bg-white' : 'bg-[var(--campaign-accent-soft)]'}`}>
                            <div className={`mt-1 grid size-9 shrink-0 place-items-center rounded-xl ${notification.readAt ? 'bg-slate-100 text-slate-400' : 'bg-[var(--campaign-accent)] text-[var(--campaign-contrast)]'}`}><Bell size={16} /></div>
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h2 className="text-sm font-black text-slate-800">{notification.title}</h2>
                                    {!notification.readAt && <span className="rounded-full bg-white px-2 py-0.5 text-[9px] font-black uppercase text-[var(--campaign-accent)]">Nueva</span>}
                                </div>
                                <p className="mt-1 text-xs leading-5 text-slate-500">{notification.message}</p>
                                <div className="mt-2 flex flex-wrap items-center gap-2 text-[10px] font-bold uppercase tracking-wide text-slate-400">
                                    <span>{categoryLabel(notification.category)}</span>
                                    {notification.createdAt && <span className="flex items-center gap-1"><Clock3 size={11} /> {formatDate(notification.createdAt)}</span>}
                                </div>
                            </div>
                            <ExternalLink size={15} className="mt-1 shrink-0 text-slate-300" />
                        </button>
                    ))}
                    {notifications.data.length === 0 && <div className="py-16 text-center"><Bell size={28} className="mx-auto text-slate-200" /><p className="mt-3 text-sm font-bold text-slate-500">Sin notificaciones</p><p className="mt-1 text-xs text-slate-400">Cuando algo requiera atención aparecerá aquí.</p></div>}
                </div>
            </section>

            {notifications.links.length > 3 && <div className="mt-5 flex flex-wrap justify-center gap-2">{notifications.links.map((link, index) => <Link key={`${link.label}-${index}`} href={link.url ?? '#'} preserveScroll className={`rounded-lg border px-3 py-2 text-xs font-black ${link.active ? 'border-[var(--campaign-accent)] bg-[var(--campaign-accent)] text-[var(--campaign-contrast)]' : link.url ? 'border-slate-200 bg-white text-slate-500' : 'pointer-events-none border-slate-100 bg-slate-50 text-slate-300'}`} dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>}
        </AppLayout>
    );
}

function categoryLabel(category: string): string {
    return {
        meeting: 'Agenda',
        calendar: 'Google Calendar',
        inventory: 'Inventario',
        metrics: 'Medición',
    }[category] ?? 'General';
}

function formatDate(value: string): string {
    return new Date(value).toLocaleString('es-CO', { dateStyle: 'medium', timeStyle: 'short' });
}
