import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, CalendarCheck2, CheckCircle2, Cloud, Fingerprint, KeyRound, MapPin, RefreshCw, Save, ShieldCheck, Unplug } from 'lucide-react';
import { useEffect } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { SharedProps } from '@/types';

type CalendarOption = { id: string; name: string; timezone?: string; primary: boolean; accessRole: string };
type Connection = {
    status: string; email?: string; calendarId?: string; calendarName?: string; timezone: string;
    lastSyncedAt?: string; lastError?: string; watchEnabled: boolean; watchExpiresAt?: string;
};
type ServiceConfiguration = {
    clientId?: string; secretConfigured: boolean; redirectUri: string; webhookUrl?: string; updatedAt?: string;
};
type SyncRun = {
    id: string; trigger: string; status: 'queued' | 'running' | 'succeeded' | 'failed';
    counts?: { examined?: number; created?: number; updated?: number; unchanged?: number };
    errorCode?: string; message?: string; queuedAt?: string; startedAt?: string; finishedAt?: string;
};

export default function CalendarSettings({ connection, calendars, configured, serviceConfiguration, permissions, syncRuns }: {
    connection: Connection | null;
    calendars: CalendarOption[];
    configured: boolean;
    serviceConfiguration: ServiceConfiguration | null;
    permissions: { manage: boolean; viewSync: boolean; configureServices: boolean };
    syncRuns: SyncRun[];
}) {
    const { currentCampaign } = usePage<SharedProps>().props;
    const form = useForm({ calendar_id: connection?.calendarId ?? '' });
    const serviceForm = useForm({
        client_id: serviceConfiguration?.clientId ?? '',
        client_secret: '',
        redirect_uri: serviceConfiguration?.redirectUri ?? '',
        webhook_url: serviceConfiguration?.webhookUrl ?? '',
    });
    const activeSync = syncRuns.find((run) => ['queued', 'running'].includes(run.status));
    const latestSync = syncRuns[0];
    useEffect(() => {
        form.setData('calendar_id', connection?.calendarId ?? '');
        form.clearErrors();
        serviceForm.setData({
            client_id: serviceConfiguration?.clientId ?? '',
            client_secret: '',
            redirect_uri: serviceConfiguration?.redirectUri ?? '',
            webhook_url: serviceConfiguration?.webhookUrl ?? '',
        });
        serviceForm.clearErrors();
    }, [currentCampaign?.id]);
    useEffect(() => {
        if (!activeSync) return;
        const timer = window.setInterval(() => {
            router.reload({ only: ['connection', 'syncRuns'] });
        }, 2500);

        return () => window.clearInterval(timer);
    }, [activeSync?.id, activeSync?.status]);
    const selectCalendar = (event: React.FormEvent) => {
        event.preventDefault();
        form.post('/calendar/select', { preserveScroll: true });
    };
    const disconnect = () => {
        if (window.confirm('¿Desconectar Google Calendar? Los eventos existentes en Google se conservarán.')) {
            router.delete('/calendar/disconnect', { preserveScroll: true });
        }
    };
    const saveService = (event: React.FormEvent) => {
        event.preventDefault();
        serviceForm.put('/calendar/settings/service', {
            preserveScroll: true,
            onSuccess: () => serviceForm.setData('client_secret', ''),
        });
    };

    return (
        <AppLayout title={`Google Calendar · ${currentCampaign?.candidateName ?? ''}`} eyebrow={`Configuración exclusiva · ${currentCampaign?.name ?? ''}`}>
            <Head title={`Google Calendar · ${currentCampaign?.candidateName ?? ''}`} />
            <section key={currentCampaign?.id} className="mb-6 overflow-hidden rounded-2xl border border-[#17464d] bg-[#102f35] text-white shadow-sm">
                <div className="h-1.5 bg-gradient-to-r from-[#e8754f] via-[#f2b66d] to-[#a8d7c8]" />
                <div className="p-5 md:p-6">
                <div className="flex flex-col justify-between gap-3 md:flex-row md:items-center">
                    <div className="flex items-start gap-4">
                        <div className="grid size-12 shrink-0 place-items-center rounded-xl bg-[#e8754f] text-lg font-black shadow-lg shadow-black/10">{currentCampaign?.candidateName?.charAt(0)}</div>
                        <div>
                            <div className="text-[10px] font-black uppercase tracking-[.16em] text-[#a8d7c8]">Espacio independiente de campaña</div>
                            <h2 className="mt-1 text-2xl font-black">{currentCampaign?.candidateName}</h2>
                            <div className="mt-2 flex flex-wrap gap-3 text-xs text-white/55">
                                <span className="flex items-center gap-1"><Fingerprint size={13} /> {currentCampaign?.office}</span>
                                <span className="flex items-center gap-1"><MapPin size={13} /> {currentCampaign?.territory}</span>
                                {currentCampaign?.electionAt && <span>Elección: {formatElection(currentCampaign.electionAt)}</span>}
                            </div>
                        </div>
                    </div>
                    {currentCampaign?.isSuperAdmin && <div className="rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-xs text-white/60">Usa el selector superior izquierdo para configurar otro candidato.</div>}
                </div>
                <div className="mt-5 rounded-xl border border-[#a8d7c8]/20 bg-[#a8d7c8]/10 px-4 py-3 text-xs font-semibold text-[#d9f0e8]">Todos los datos, credenciales y calendarios mostrados abajo pertenecen exclusivamente a <b>{currentCampaign?.candidateName}</b>.</div>
                </div>
            </section>

            {permissions.configureServices && (
                <section className="panel mb-6 overflow-hidden">
                    <div className="flex items-start gap-3 border-b border-slate-100 p-6">
                        <div className="grid size-10 shrink-0 place-items-center rounded-xl bg-violet-50 text-violet-700"><KeyRound size={18} /></div>
                        <div><h2 className="font-black text-[#102a33]">Credenciales OAuth de {currentCampaign?.candidateName}</h2><p className="mt-1 text-xs leading-5 text-slate-500">Se cifran en la base de datos y pertenecen únicamente a esta campaña. No se utiliza el archivo <code>.env</code>.</p></div>
                    </div>
                    <form onSubmit={saveService} className="grid gap-5 p-6 md:grid-cols-2">
                        <Field label="OAuth Client ID" error={serviceForm.errors.client_id}>
                            <input className="field" value={serviceForm.data.client_id} onChange={(event) => serviceForm.setData('client_id', event.target.value)} placeholder="000000000000-xxxx.apps.googleusercontent.com" required />
                        </Field>
                        <Field label="OAuth Client Secret" error={serviceForm.errors.client_secret}>
                            <input type="password" className="field" value={serviceForm.data.client_secret} onChange={(event) => serviceForm.setData('client_secret', event.target.value)} placeholder={serviceConfiguration?.secretConfigured ? '••••••••  Deja vacío para conservarlo' : 'Digita el secreto de Google'} required={!serviceConfiguration?.secretConfigured} />
                        </Field>
                        <Field label="URI de redirección OAuth" error={serviceForm.errors.redirect_uri}>
                            <input type="url" className="field" value={serviceForm.data.redirect_uri} onChange={(event) => serviceForm.setData('redirect_uri', event.target.value)} required />
                        </Field>
                        <Field label="Webhook público HTTPS" error={serviceForm.errors.webhook_url}>
                            <input type="url" className="field" value={serviceForm.data.webhook_url} onChange={(event) => serviceForm.setData('webhook_url', event.target.value)} placeholder="https://dominio.com/webhooks/google-calendar/v1" />
                        </Field>
                        <div className="rounded-xl bg-amber-50 p-4 text-xs leading-5 text-amber-800 md:col-span-2">
                            Si cambias el Client ID o el secreto, la cuenta actual se desconectará de forma segura y deberás autorizarla nuevamente. Sin webhook HTTPS continuará funcionando el polling cada dos minutos.
                        </div>
                        <div className="flex items-center justify-between gap-3 border-t border-slate-100 pt-5 md:col-span-2">
                            <span className="text-[10px] text-slate-400">{serviceConfiguration?.updatedAt ? `Última actualización: ${formatDate(serviceConfiguration.updatedAt)}` : 'Aún no se han almacenado credenciales.'}</span>
                            <button className="primary-button" disabled={serviceForm.processing}><Save size={16} /> Guardar configuración</button>
                        </div>
                    </form>
                </section>
            )}

            <div className="grid gap-6 xl:grid-cols-[1.2fr_.8fr]">
                <section className="panel overflow-hidden">
                    <div className="flex items-start justify-between gap-4 border-b border-slate-100 p-6">
                        <div>
                            <div className="flex items-center gap-2 text-[10px] font-black uppercase tracking-[.16em] text-[#e8754f]"><Cloud size={15} /> Cuenta Google de {currentCampaign?.candidateName}</div>
                            <h2 className="mt-2 text-xl font-black text-[#102a33]">{connection?.email ?? 'Sin cuenta vinculada'}</h2>
                            <p className="mt-1 text-sm text-slate-500">Cada campaña utiliza un calendario exclusivo y completamente compartimentado.</p>
                        </div>
                        <StatusBadge status={connection?.status ?? 'disconnected'} />
                    </div>

                    <div className="space-y-5 p-6">
                        {!configured && (
                            <div className="flex gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                                <AlertTriangle className="mt-0.5 shrink-0" size={18} />
                                <div><b>Faltan credenciales OAuth para esta campaña.</b><p className="mt-1 text-xs leading-5">{permissions.configureServices ? 'Completa el formulario superior antes de vincular la cuenta.' : 'Un superusuario debe configurarlas desde este módulo.'}</p></div>
                            </div>
                        )}

                        {(!connection?.email || ['reconnect_required', 'disconnected'].includes(connection.status)) && permissions.manage && (
                            <a href="/calendar/oauth/redirect" className={`primary-button w-fit ${!configured ? 'pointer-events-none opacity-40' : ''}`}>
                                <CalendarCheck2 size={17} /> Vincular cuenta de Google
                            </a>
                        )}

                        {connection?.email && !['active', 'reconnect_required', 'disconnected'].includes(connection.status) && permissions.manage && (
                            <>
                                <div className="rounded-xl bg-[#d9f0e8]/60 p-4 text-sm text-[#0d4d4b]">
                                    La cuenta ya fue autorizada. Selecciona el calendario escribible que pertenecerá a esta campaña.
                                </div>
                                {calendars.length === 0 ? (
                                    <div className="flex items-center justify-between rounded-xl border border-slate-200 p-4">
                                        <span className="text-sm text-slate-500">No se cargaron calendarios escribibles.</span>
                                        <Link href="/calendar/settings?refresh=1" className="secondary-button px-3 py-2 text-xs"><RefreshCw size={14} /> Reintentar</Link>
                                    </div>
                                ) : (
                                    <form onSubmit={selectCalendar}>
                                        <label className="label">Calendario del candidato</label>
                                        <div className="flex flex-col gap-2 sm:flex-row">
                                            <select className="field flex-1" value={form.data.calendar_id} onChange={(event) => form.setData('calendar_id', event.target.value)} required>
                                                <option value="">Selecciona un calendario…</option>
                                                {calendars.map((calendar) => <option key={calendar.id} value={calendar.id}>{calendar.name}{calendar.primary ? ' · Principal' : ''} · {calendar.timezone}</option>)}
                                            </select>
                                            <button className="primary-button justify-center" disabled={form.processing}><CheckCircle2 size={16} /> Activar</button>
                                        </div>
                                        {form.errors.calendar_id && <p className="mt-2 text-xs font-bold text-red-600">{form.errors.calendar_id}</p>}
                                    </form>
                                )}
                            </>
                        )}

                        {connection?.status === 'active' && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Info label="Calendario seleccionado" value={connection.calendarName ?? '—'} />
                                <Info label="Zona horaria" value={connection.timezone} />
                                <Info label="Última sincronización" value={connection.lastSyncedAt ? formatDate(connection.lastSyncedAt) : 'Pendiente'} />
                                <Info label="Actualización en tiempo real" value={connection.watchEnabled ? `Activa${connection.watchExpiresAt ? ` hasta ${formatDate(connection.watchExpiresAt)}` : ''}` : 'Polling cada 2 minutos'} />
                            </div>
                        )}

                        {connection?.lastError && <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-xs leading-5 text-red-700"><b>Último error</b><div className="mt-1 break-words">{connection.lastError}</div></div>}

                        {connection?.status === 'active' && permissions.manage && (
                            <div className="flex flex-wrap gap-2 border-t border-slate-100 pt-5">
                                <button onClick={() => router.post('/calendar/sync', {}, { preserveScroll: true })} disabled={!!activeSync} className="primary-button disabled:cursor-wait disabled:opacity-60"><RefreshCw size={16} className={activeSync ? 'animate-spin' : ''} /> {activeSync ? 'Sincronizando…' : 'Sincronizar ahora'}</button>
                                <Link href="/calendar/reviews?status=pending" className="secondary-button"><CalendarCheck2 size={16} /> Revisar cambios</Link>
                                <button onClick={disconnect} className="secondary-button border-red-200 text-red-700"><Unplug size={16} /> Desconectar</button>
                            </div>
                        )}

                        {permissions.viewSync && latestSync && <SyncRunCard run={latestSync} />}
                    </div>
                </section>

                <aside className="space-y-5">
                    <section className="panel p-5">
                        <div className="flex items-center gap-2 text-sm font-black text-[#102a33]"><ShieldCheck size={18} className="text-[#0d4d4b]" /> Reglas activas</div>
                        <ul className="mt-4 space-y-3 text-xs leading-5 text-slate-500">
                            <li>Solo las reuniones aprobadas se publican en Google.</li>
                            <li>Los cambios de Google bloquean provisionalmente hasta ser revisados.</li>
                            <li>Un evento rechazado se elimina o restaura también en Google.</li>
                            <li>No se sincronizan asistentes, cédulas, teléfonos ni documentos.</li>
                        </ul>
                    </section>
                    <section className="rounded-2xl bg-[#102f35] p-5 text-white">
                        <div className="text-xs font-black text-[#a8d7c8]">Google Home y Assistant</div>
                        <p className="mt-2 text-xs leading-5 text-white/60">La consulta por voz depende de Voice Match, resultados personales y la configuración del dispositivo asociado a esta cuenta.</p>
                    </section>
                </aside>
            </div>
        </AppLayout>
    );
}

function StatusBadge({ status }: { status: string }) {
    const active = status === 'active';
    return <span className={`rounded-full px-3 py-1 text-[10px] font-black uppercase ${active ? 'bg-emerald-50 text-emerald-700' : status === 'reconnect_required' ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-500'}`}>{active ? 'Conectado' : status === 'pending_selection' ? 'Falta calendario' : status === 'reconnect_required' ? 'Requiere reconexión' : 'Desconectado'}</span>;
}
function Info({ label, value }: { label: string; value: string }) {
    return <div className="rounded-xl border border-slate-100 bg-slate-50 p-4"><div className="text-[9px] font-black uppercase tracking-wide text-slate-400">{label}</div><div className="mt-1 text-sm font-black text-slate-700">{value}</div></div>;
}
function formatDate(value: string): string {
    return new Date(value).toLocaleString('es-CO', { dateStyle: 'medium', timeStyle: 'short' });
}
function formatElection(value: string): string {
    return new Date(`${value}T12:00:00`).toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' });
}
function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
    return <div><label className="label">{label}</label>{children}{error && <p className="mt-1 text-xs font-bold text-red-600">{error}</p>}</div>;
}
function SyncRunCard({ run }: { run: SyncRun }) {
    const succeeded = run.status === 'succeeded';
    const failed = run.status === 'failed';
    const tone = succeeded
        ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
        : failed
            ? 'border-red-200 bg-red-50 text-red-800'
            : 'border-sky-200 bg-sky-50 text-sky-800';
    const status = succeeded ? 'Completada' : failed ? 'Fallida' : run.status === 'running' ? 'Procesando' : 'En cola';

    return <div className={`rounded-xl border p-4 ${tone}`}>
        <div className="flex items-center gap-2 text-xs font-black"><RefreshCw size={14} className={!succeeded && !failed ? 'animate-spin' : ''} /> Última sincronización · {status}</div>
        <p className="mt-1 text-xs leading-5 opacity-80">{run.message}</p>
        {run.counts && <div className="mt-2 flex flex-wrap gap-2 text-[10px] font-bold"><span>{run.counts.examined ?? 0} revisados</span><span>{run.counts.created ?? 0} nuevos</span><span>{run.counts.updated ?? 0} actualizados</span><span>{run.counts.unchanged ?? 0} sin cambios</span></div>}
        {run.finishedAt && <div className="mt-2 text-[9px] font-bold uppercase opacity-55">Finalizó {formatDate(run.finishedAt)}</div>}
        {run.errorCode === 'reconnect_required' && <a href="/calendar/oauth/redirect" className="mt-3 inline-flex rounded-lg bg-red-700 px-3 py-2 text-xs font-black text-white">Volver a vincular Google</a>}
    </div>;
}
