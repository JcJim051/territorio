import { Head, router } from '@inertiajs/react';
import { Download, Search, ShieldCheck } from 'lucide-react';
import { FormEvent, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';

type AuditEvent = { id: number; createdAt: string; user?: string; event: string; module: string; auditableType?: string; auditableId?: number; oldValues: Record<string, unknown>; newValues: Record<string, unknown> };
type Page<T> = { data: T[]; links: Array<{ url: string | null; label: string; active: boolean }>; total: number };
type Filters = { from?: string; to?: string; user?: number; event?: string; module?: string };

export default function AuditIndex({ events, filters, users, canExport }: { events: Page<AuditEvent>; filters: Filters; users: Array<{ id: number; name: string }>; canExport: boolean }) {
    const [values, setValues] = useState({ from: filters.from ?? '', to: filters.to ?? '', user: filters.user ?? '', event: filters.event ?? '', module: filters.module ?? '' });
    const query = () => new URLSearchParams(Object.entries(values).filter(([, value]) => value !== '').map(([key, value]) => [key, String(value)])).toString();
    const submit = (event: FormEvent) => { event.preventDefault(); router.get('/admin/audit', values, { preserveState: true }); };

    return <AppLayout title="Auditoría" eyebrow="Trazabilidad de campaña">
        <Head title="Auditoría" />
        <form onSubmit={submit} className="panel mb-5 grid gap-3 p-4 md:grid-cols-6">
            <input type="date" className="field" value={values.from} onChange={(event) => setValues({ ...values, from: event.target.value })} />
            <input type="date" className="field" value={values.to} onChange={(event) => setValues({ ...values, to: event.target.value })} />
            <select className="field" value={values.user} onChange={(event) => setValues({ ...values, user: event.target.value })}><option value="">Todos los usuarios</option>{users.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}</select>
            <input className="field" placeholder="Evento" value={values.event} onChange={(event) => setValues({ ...values, event: event.target.value })} />
            <input className="field" placeholder="Módulo (meeting, user…)" value={values.module} onChange={(event) => setValues({ ...values, module: event.target.value })} />
            <div className="flex gap-2"><button className="primary-button flex-1"><Search size={16} /> Filtrar</button>{canExport && <a href={`/admin/audit/export?${query()}`} className="secondary-button" title="Exportar CSV"><Download size={17} /></a>}</div>
        </form>
        <div className="mb-3 flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-slate-400"><ShieldCheck size={15} /> {events.total} registros compartimentados</div>
        <div className="space-y-3">
            {events.data.map((item) => <article key={item.id} className="panel p-4">
                <div className="flex flex-col justify-between gap-2 md:flex-row"><div><span className="rounded-lg bg-[var(--campaign-accent-soft)] px-2 py-1 text-[10px] font-black uppercase text-[var(--campaign-accent)]">{item.module}</span><h2 className="mt-2 font-black text-slate-800">{item.event}</h2><p className="mt-1 text-xs text-slate-400">{item.user || 'Sistema'} · {item.createdAt} · {item.auditableType || 'Sin entidad'} {item.auditableId ? `#${item.auditableId}` : ''}</p></div></div>
                {(Object.keys(item.oldValues).length > 0 || Object.keys(item.newValues).length > 0) && <details className="mt-3 rounded-xl bg-slate-50 p-3"><summary className="cursor-pointer text-xs font-black text-slate-600">Comparar antes y después</summary><div className="mt-3 grid gap-3 md:grid-cols-2"><pre className="overflow-auto rounded-lg bg-white p-3 text-[11px] text-slate-600">{JSON.stringify(item.oldValues, null, 2)}</pre><pre className="overflow-auto rounded-lg bg-white p-3 text-[11px] text-slate-600">{JSON.stringify(item.newValues, null, 2)}</pre></div></details>}
            </article>)}
            {events.data.length === 0 && <div className="panel p-12 text-center text-sm text-slate-400">No hay registros para los filtros aplicados.</div>}
        </div>
        <div className="mt-5 flex flex-wrap gap-1">{events.links.map((link, index) => link.url ? <a key={index} href={link.url} dangerouslySetInnerHTML={{ __html: link.label }} className={`rounded-lg px-3 py-2 text-xs font-bold ${link.active ? 'bg-[var(--campaign-accent)] text-[var(--campaign-contrast)]' : 'bg-white text-slate-500'}`} /> : <span key={index} dangerouslySetInnerHTML={{ __html: link.label }} className="rounded-lg px-3 py-2 text-xs text-slate-300" />)}</div>
    </AppLayout>;
}
