import { Head, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Boxes, PackageCheck, Pencil, Plus, RefreshCw, Share2, Trash2, X, type LucideIcon } from 'lucide-react';
import { FormEvent, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { SharedProps } from '@/types';

type Resource = {
    id: number;
    name: string;
    sku?: string;
    kind: string;
    unit: string;
    quantity: number;
    minimumQuantity: number;
    isShared: boolean;
    status: string;
    movementsCount: number;
    occupiedNow: number;
    upcomingReservations: number;
    nextReservation?: { title: string; quantity: number; startsAt: string; endsAt: string };
};

type ResourceForm = { name: string; sku: string; kind: string; unit: string; quantity: number; minimum_quantity: number; is_shared: boolean; status: string };
const emptyResource: ResourceForm = { name: '', sku: '', kind: 'consumable', unit: 'unidad', quantity: 0, minimum_quantity: 0, is_shared: false, status: 'available' };
const kindLabels: Record<string, string> = { consumable: 'Consumible', asset: 'Activo retornable', equipment: 'Equipo', service: 'Servicio controlable' };
const statusLabels: Record<string, string> = { available: 'Disponible', maintenance: 'Mantenimiento', inactive: 'Inactivo', archived: 'Archivado' };

export default function Inventory({ resources, summary, filters }: {
    resources: Resource[];
    summary: { total: number; alerts: number; assets: number; consumables: number };
    filters: { alert?: string };
}) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Resource | null>(null);
    const [adjusting, setAdjusting] = useState<Resource | null>(null);
    const { errors } = usePage<SharedProps>().props;
    const form = useForm<ResourceForm>(emptyResource);
    const adjustment = useForm({ quantity: 0, notes: '' });

    const startCreate = () => {
        setEditing(null);
        form.setData(emptyResource);
        form.clearErrors();
        setOpen(true);
    };
    const startEdit = (resource: Resource) => {
        setEditing(resource);
        form.setData({ name: resource.name, sku: resource.sku ?? '', kind: resource.kind, unit: resource.unit, quantity: resource.quantity, minimum_quantity: resource.minimumQuantity, is_shared: resource.isShared, status: resource.status });
        form.clearErrors();
        setOpen(true);
    };
    const saveResource = (event: FormEvent) => {
        event.preventDefault();
        const options = { onSuccess: () => { form.reset(); setEditing(null); setOpen(false); } };
        editing ? form.put(`/inventory/${editing.id}`, options) : form.post('/inventory', options);
    };
    const adjust = (event: FormEvent) => {
        event.preventDefault();
        if (!adjusting) return;
        adjustment.post(`/inventory/${adjusting.id}/adjust`, { onSuccess: () => { adjustment.reset(); setAdjusting(null); } });
    };
    const remove = (resource: Resource) => {
        if (window.confirm(`¿Eliminar “${resource.name}”? Esta opción solo procede si no tiene movimientos ni reservas.`)) {
            router.delete(`/inventory/${resource.id}`, { preserveScroll: true });
        }
    };
    const metricCards: Array<{ label: string; value: number; Icon: LucideIcon; tone: string }> = [
        { label: 'Recursos', value: summary.total, Icon: Boxes, tone: 'bg-[#d9f0e8] text-[#0d4d4b]' },
        { label: 'Alertas de mínimo', value: summary.alerts, Icon: AlertTriangle, tone: 'bg-amber-50 text-amber-700' },
        { label: 'Activos', value: summary.assets, Icon: PackageCheck, tone: 'bg-sky-50 text-sky-700' },
        { label: 'Consumibles', value: summary.consumables, Icon: RefreshCw, tone: 'bg-violet-50 text-violet-700' },
    ];

    return (
        <AppLayout title="Inventario" eyebrow="Disponibilidad y control">
            <Head title="Inventario" />
            <div className="mb-5 flex flex-col justify-between gap-3 md:flex-row md:items-center">
                <p className="text-sm text-slate-500">Consumibles, activos, mínimos, movimientos y alcance por campaña.</p>
                <div className="flex gap-2">
                    <select className="field min-w-44 py-2" value={filters.alert ?? ''} onChange={(event) => router.get('/inventory', { alert: event.target.value }, { preserveState: true, replace: true })}><option value="">Todos los recursos</option><option value="low">Requieren atención</option></select>
                    <button onClick={startCreate} className="primary-button"><Plus size={17} /> Crear recurso</button>
                </div>
            </div>
            {errors.resource && <div className="mb-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-700">{errors.resource}</div>}

            <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">{metricCards.map(({ label, value, Icon, tone }) => <div key={label} className="panel flex items-center gap-4 p-5"><div className={`grid size-11 place-items-center rounded-xl ${tone}`}><Icon size={20} /></div><div><div className="text-2xl font-black">{value}</div><div className="text-xs font-bold text-slate-500">{label}</div></div></div>)}</div>

            <div className="panel overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[900px] text-left">
                        <thead><tr className="border-b border-slate-100 bg-slate-50 text-[10px] font-black uppercase tracking-[.13em] text-slate-400"><th className="px-5 py-3">Recurso</th><th className="px-5 py-3">Tipo</th><th className="px-5 py-3">Disponibilidad</th><th className="px-5 py-3">Alcance</th><th className="px-5 py-3">Estado</th><th className="px-5 py-3 text-right">Acciones</th></tr></thead>
                        <tbody className="divide-y divide-slate-100">
                            {resources.map((resource) => {
                                const low = resource.quantity <= resource.minimumQuantity;
                                const reservable = ['asset', 'equipment', 'service'].includes(resource.kind);
                                return <tr key={resource.id}><td className="px-5 py-4"><div className="text-sm font-black text-slate-800">{resource.name}</div><div className="mt-1 text-[11px] text-slate-400">{resource.sku ?? 'Sin SKU'}</div></td><td className="px-5 py-4"><span className="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-black uppercase text-slate-600">{kindLabels[resource.kind] ?? resource.kind}</span></td><td className="px-5 py-4"><div className={`text-sm font-black ${low ? 'text-amber-700' : 'text-slate-800'}`}>{resource.quantity} {resource.unit}</div>{reservable ? <><div className={`mt-1 text-[11px] font-semibold ${resource.occupiedNow ? 'text-sky-700' : 'text-slate-400'}`}>{resource.occupiedNow} ocupadas ahora · {resource.quantity - resource.occupiedNow} libres</div>{resource.nextReservation && <div className="mt-1 max-w-56 truncate text-[10px] text-slate-400">Próxima: {resource.nextReservation.title} · {formatReservation(resource.nextReservation.startsAt)}</div>}</> : <div className="mt-1 text-[11px] text-slate-400">Mínimo: {resource.minimumQuantity}</div>}{low && !reservable && <div className="mt-1 flex items-center gap-1 text-[10px] font-bold text-amber-700"><AlertTriangle size={11} /> Requiere atención</div>}</td><td className="px-5 py-4"><div className="flex items-center gap-1.5 text-xs font-semibold text-slate-500">{resource.isShared && <Share2 size={13} />} {resource.isShared ? 'Compartido' : 'Campaña actual'}</div>{reservable && resource.upcomingReservations > 0 && <div className="mt-1 text-[10px] font-bold text-sky-700">{resource.upcomingReservations} reservas próximas</div>}</td><td className="px-5 py-4"><span className="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-black uppercase text-slate-600">{statusLabels[resource.status] ?? resource.status}</span></td><td className="px-5 py-4"><div className="flex justify-end gap-1"><button onClick={() => setAdjusting(resource)} className="secondary-button px-3 py-2 text-xs">Movimiento</button><button onClick={() => startEdit(resource)} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100" title="Editar"><Pencil size={16} /></button>{resource.movementsCount === 0 && resource.upcomingReservations === 0 && <button onClick={() => remove(resource)} className="rounded-lg p-2 text-red-500 hover:bg-red-50" title="Eliminar"><Trash2 size={16} /></button>}</div></td></tr>;
                            })}
                            {resources.length === 0 && <tr><td colSpan={6} className="p-14 text-center text-sm text-slate-400">No hay recursos con este filtro.</td></tr>}
                        </tbody>
                    </table>
                </div>
            </div>

            {open && <Modal title={editing ? 'Editar recurso' : 'Crear recurso'} onClose={() => setOpen(false)}><form onSubmit={saveResource} className="grid gap-4 p-6 md:grid-cols-2"><Field label="Nombre" error={form.errors.name} wide><input className="field" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} required /></Field><Field label="SKU" error={form.errors.sku}><input className="field" value={form.data.sku} onChange={(event) => form.setData('sku', event.target.value)} /></Field><Field label="Tipo" error={form.errors.kind}><select className="field" value={form.data.kind} onChange={(event) => form.setData('kind', event.target.value)}>{Object.entries(kindLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></Field>{!editing && <Field label="Cantidad inicial" error={form.errors.quantity}><input type="number" min={0} step="0.01" className="field" value={form.data.quantity} onChange={(event) => form.setData('quantity', Number(event.target.value))} /></Field>}<Field label="Cantidad mínima" error={form.errors.minimum_quantity}><input type="number" min={0} step="0.01" className="field" value={form.data.minimum_quantity} onChange={(event) => form.setData('minimum_quantity', Number(event.target.value))} /></Field><Field label="Unidad" error={form.errors.unit}><input className="field" value={form.data.unit} onChange={(event) => form.setData('unit', event.target.value)} required /></Field>{editing && <Field label="Estado" error={form.errors.status}><select className="field" value={form.data.status} onChange={(event) => form.setData('status', event.target.value)}>{Object.entries(statusLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></Field>}<label className="flex items-center gap-2 self-end pb-3 text-sm font-semibold text-slate-600"><input type="checkbox" checked={form.data.is_shared} onChange={(event) => form.setData('is_shared', event.target.checked)} className="size-4 accent-[#0d4d4b]" /> Compartir entre campañas</label><div className="flex justify-end gap-2 border-t border-slate-100 pt-4 md:col-span-2"><button type="button" onClick={() => setOpen(false)} className="secondary-button">Cancelar</button><button className="primary-button" disabled={form.processing}>{editing ? 'Guardar cambios' : 'Crear recurso'}</button></div></form></Modal>}

            {adjusting && <Modal title={`Movimiento · ${adjusting.name}`} onClose={() => setAdjusting(null)}><form onSubmit={adjust} className="space-y-4 p-6"><div className="rounded-xl bg-slate-50 p-3 text-sm text-slate-600">Existencia actual: <strong>{adjusting.quantity} {adjusting.unit}</strong></div><Field label="Cantidad del movimiento" error={adjustment.errors.quantity}><input type="number" step="0.01" className="field" value={adjustment.data.quantity} onChange={(event) => adjustment.setData('quantity', Number(event.target.value))} required /><p className="mt-1 text-[11px] text-slate-400">Positivo para entrada y negativo para salida.</p></Field><Field label="Justificación" error={adjustment.errors.notes}><textarea rows={3} className="field resize-none" value={adjustment.data.notes} onChange={(event) => adjustment.setData('notes', event.target.value)} required /></Field><div className="flex justify-end gap-2 border-t border-slate-100 pt-4"><button type="button" onClick={() => setAdjusting(null)} className="secondary-button">Cancelar</button><button className="primary-button" disabled={adjustment.processing}>Registrar movimiento</button></div></form></Modal>}
        </AppLayout>
    );
}

function Field({ label, error, wide, children }: { label: string; error?: string; wide?: boolean; children: React.ReactNode }) {
    return <div className={wide ? 'md:col-span-2' : ''}><label className="label">{label}</label>{children}{error && <p className="mt-1 text-xs font-semibold text-red-600">{error}</p>}</div>;
}

function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: React.ReactNode }) {
    return <div className="fixed inset-0 z-50 flex items-end justify-center bg-[#102a33]/55 backdrop-blur-sm md:items-center md:p-6"><div className="max-h-[95vh] w-full max-w-2xl overflow-auto rounded-t-3xl bg-white shadow-2xl md:rounded-3xl"><div className="sticky top-0 z-10 flex items-center justify-between border-b border-slate-100 bg-white px-6 py-5"><h2 className="text-lg font-black">{title}</h2><button onClick={onClose} className="rounded-full bg-slate-100 p-2 text-slate-500"><X size={18} /></button></div>{children}</div></div>;
}

function formatReservation(value: string): string {
    return new Intl.DateTimeFormat('es-CO', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value));
}
