import { Head, router, useForm } from '@inertiajs/react';
import { GitBranch, MapPin, Pencil, Plus, Search, ShieldCheck, Trash2, UserCheck, X } from 'lucide-react';
import { FormEvent, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';

type Person = {
    id: string;
    name: string;
    email?: string;
    phone?: string;
    document: string;
    status: string;
    isReferralNode: boolean;
    placeId?: number;
    place?: string;
    commune?: string;
    tablesCount: number;
    table?: number;
    children: number;
    createdAt: string;
};

type Place = { id: number; name: string; commune?: string; tables_count: number };
type PersonForm = {
    name: string;
    email: string;
    phone: string;
    document_number: string;
    status: string;
    voting_place_id: string;
    voting_table_number: string;
};

const statusLabels: Record<string, string> = {
    pending: 'Pendiente',
    verified: 'Verificada',
    active: 'Activa',
    inactive: 'Inactiva',
    rejected: 'Rechazada',
    withdrawn: 'Retirada',
};

const emptyForm: PersonForm = {
    name: '',
    email: '',
    phone: '',
    document_number: '',
    status: 'pending',
    voting_place_id: '',
    voting_table_number: '',
};

export default function People({ people, places, statuses, filters }: {
    people: { data: Person[]; links: Array<{ url?: string; label: string; active: boolean }>; total: number };
    places: Place[];
    statuses: string[];
    filters: { search?: string; status?: string };
}) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [editing, setEditing] = useState<Person | null>(null);
    const [open, setOpen] = useState(false);
    const form = useForm<PersonForm>(emptyForm);
    const selectedPlace = places.find((place) => String(place.id) === form.data.voting_place_id);

    const applyFilters = (nextStatus = status) => {
        router.get('/people', { search, status: nextStatus }, { preserveState: true, replace: true });
    };

    const startCreate = () => {
        setEditing(null);
        form.setData(emptyForm);
        form.clearErrors();
        setOpen(true);
    };

    const startEdit = (person: Person) => {
        setEditing(person);
        form.setData({
            name: person.name,
            email: person.email ?? '',
            phone: person.phone ?? '',
            document_number: person.document === 'Sin registrar' ? '' : person.document,
            status: person.status,
            voting_place_id: person.placeId ? String(person.placeId) : '',
            voting_table_number: person.table ? String(person.table) : '',
        });
        form.clearErrors();
        setOpen(true);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { onSuccess: () => { setOpen(false); setEditing(null); form.reset(); } };
        editing ? form.put(`/people/${editing.id}`, options) : form.post('/people', options);
    };

    const verify = (person: Person) => {
        if (window.confirm(`Confirma que ${person.name} otorgó autorización expresa para el tratamiento de sus datos?`)) {
            router.post(`/people/${person.id}/verify`, { consent_confirmed: true }, { preserveScroll: true });
        }
    };

    const remove = (person: Person) => {
        if (window.confirm(`¿Retirar a ${person.name} de esta campaña? La acción quedará auditada.`)) {
            router.delete(`/people/${person.id}`, { preserveScroll: true });
        }
    };

    return (
        <AppLayout title="Personas" eyebrow="Base territorial protegida">
            <Head title="Personas" />
            <div className="mb-5 flex flex-col justify-between gap-3 xl:flex-row xl:items-end">
                <div>
                    <p className="text-sm text-slate-500">{people.total} personas en la campaña activa.</p>
                    <p className="mt-1 flex items-center gap-1.5 text-[11px] text-slate-400"><ShieldCheck size={13} /> Identidad, consentimiento y cambios quedan protegidos y auditados.</p>
                </div>
                <div className="flex flex-col gap-2 sm:flex-row">
                    <form onSubmit={(event) => { event.preventDefault(); applyFilters(); }} className="flex overflow-hidden rounded-xl border border-slate-200 bg-white">
                        <input className="w-56 px-3 py-2 text-sm outline-none" placeholder="Buscar por nombre…" value={search} onChange={(event) => setSearch(event.target.value)} />
                        <button className="px-3 text-slate-500 hover:bg-slate-50" aria-label="Buscar"><Search size={17} /></button>
                    </form>
                    <select className="field min-w-44 py-2" value={status} onChange={(event) => { setStatus(event.target.value); applyFilters(event.target.value); }}>
                        <option value="">Todos los estados</option>
                        {statuses.map((item) => <option key={item} value={item}>{statusLabels[item] ?? item}</option>)}
                    </select>
                    <button onClick={startCreate} className="primary-button"><Plus size={17} /> Crear persona</button>
                </div>
            </div>

            <div className="panel overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[980px] border-collapse text-left">
                        <thead><tr className="border-b border-slate-100 bg-slate-50 text-[10px] font-black uppercase tracking-[.13em] text-slate-400"><th className="px-5 py-3">Persona</th><th className="px-5 py-3">Contacto</th><th className="px-5 py-3">Ubicación electoral</th><th className="px-5 py-3">Red</th><th className="px-5 py-3">Estado</th><th className="px-5 py-3 text-right">Acciones</th></tr></thead>
                        <tbody className="divide-y divide-slate-100">
                            {people.data.map((person) => (
                                <tr key={person.id} className="transition hover:bg-slate-50/60">
                                    <td className="px-5 py-4"><div className="flex items-center gap-3"><div className="grid size-10 place-items-center rounded-xl bg-[#d9f0e8] text-sm font-black text-[#0d4d4b]">{person.name.split(' ').map((part) => part[0]).slice(0, 2).join('')}</div><div><div className="text-sm font-black text-slate-800">{person.name}</div><div className="mt-0.5 text-[11px] text-slate-400">C.C. {person.document}</div></div></div></td>
                                    <td className="px-5 py-4"><div className="text-xs font-semibold text-slate-600">{person.phone ?? 'Sin teléfono'}</div><div className="mt-1 text-[11px] text-slate-400">{person.email ?? 'Sin correo'}</div></td>
                                    <td className="px-5 py-4"><div className="flex items-center gap-1.5 text-xs font-semibold text-slate-600"><MapPin size={13} className="text-[#e8754f]" /> {person.commune ?? 'Sin comuna'}</div><div className="mt-1 text-[11px] text-slate-400">{person.place ?? 'Sin puesto'}{person.table ? ` · Mesa ${person.table}` : ''}</div></td>
                                    <td className="px-5 py-4"><div className="flex items-center gap-1.5 text-xs font-black"><GitBranch size={14} className="text-[#0d4d4b]" /> {person.children}</div><div className="mt-1 text-[10px] text-slate-400">{person.isReferralNode ? 'Nodo habilitado' : 'Persona referida'}</div></td>
                                    <td className="px-5 py-4"><span className={`rounded-full px-2.5 py-1 text-[10px] font-black uppercase ${['verified', 'active'].includes(person.status) ? 'bg-emerald-50 text-emerald-700' : person.status === 'pending' ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600'}`}>{statusLabels[person.status] ?? person.status}</span></td>
                                    <td className="px-5 py-4"><div className="flex justify-end gap-1">{person.status === 'pending' && <button onClick={() => verify(person)} className="rounded-lg p-2 text-emerald-700 hover:bg-emerald-50" title="Verificar"><UserCheck size={16} /></button>}<button onClick={() => startEdit(person)} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100" title="Editar"><Pencil size={16} /></button><button onClick={() => remove(person)} className="rounded-lg p-2 text-red-500 hover:bg-red-50" title="Retirar"><Trash2 size={16} /></button></div></td>
                                </tr>
                            ))}
                            {people.data.length === 0 && <tr><td colSpan={6} className="p-14 text-center text-sm text-slate-400">No se encontraron personas con estos filtros.</td></tr>}
                        </tbody>
                    </table>
                </div>
                {people.links.length > 3 && <div className="flex justify-center gap-1 border-t border-slate-100 p-4">{people.links.map((link, index) => <button key={index} disabled={!link.url} onClick={() => link.url && router.visit(link.url)} className={`rounded-lg px-3 py-1.5 text-xs font-bold ${link.active ? 'bg-[#0d4d4b] text-white' : 'text-slate-500 hover:bg-slate-100 disabled:opacity-30'}`} dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>}
            </div>

            {open && (
                <Modal title={editing ? 'Editar persona' : 'Crear persona'} onClose={() => setOpen(false)}>
                    <form onSubmit={submit} className="grid gap-4 p-6 md:grid-cols-2">
                        <Field label="Nombre completo" error={form.errors.name} wide><input className="field" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} required /></Field>
                        <Field label="Correo" error={form.errors.email}><input type="email" className="field" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} /></Field>
                        <Field label="Teléfono" error={form.errors.phone}><input className="field" value={form.data.phone} onChange={(event) => form.setData('phone', event.target.value)} /></Field>
                        <Field label="Cédula" error={form.errors.document_number}><input inputMode="numeric" className="field" value={form.data.document_number} onChange={(event) => form.setData('document_number', event.target.value)} /></Field>
                        <Field label="Estado" error={form.errors.status}><select className="field" value={form.data.status} onChange={(event) => form.setData('status', event.target.value)}>{statuses.map((item) => <option key={item} value={item}>{statusLabels[item] ?? item}</option>)}</select></Field>
                        <Field label="Puesto de votación" error={form.errors.voting_place_id}><select className="field" value={form.data.voting_place_id} onChange={(event) => { form.setData('voting_place_id', event.target.value); form.setData('voting_table_number', ''); }}><option value="">Sin asignar</option>{places.map((place) => <option key={place.id} value={place.id}>{place.commune ? `${place.commune} · ` : ''}{place.name}</option>)}</select></Field>
                        <Field label="Mesa" error={form.errors.voting_table_number}><input type="number" min={1} max={selectedPlace?.tables_count || undefined} disabled={!selectedPlace} className="field disabled:bg-slate-50" value={form.data.voting_table_number} onChange={(event) => form.setData('voting_table_number', event.target.value)} placeholder={selectedPlace ? `1 a ${selectedPlace.tables_count}` : 'Selecciona un puesto'} /></Field>
                        <div className="flex justify-end gap-2 border-t border-slate-100 pt-4 md:col-span-2"><button type="button" onClick={() => setOpen(false)} className="secondary-button">Cancelar</button><button className="primary-button" disabled={form.processing}>{editing ? 'Guardar cambios' : 'Crear persona'}</button></div>
                    </form>
                </Modal>
            )}
        </AppLayout>
    );
}

function Field({ label, error, wide, children }: { label: string; error?: string; wide?: boolean; children: React.ReactNode }) {
    return <div className={wide ? 'md:col-span-2' : ''}><label className="label">{label}</label>{children}{error && <p className="mt-1 text-xs font-semibold text-red-600">{error}</p>}</div>;
}

function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: React.ReactNode }) {
    return <div className="fixed inset-0 z-50 flex items-end justify-center bg-[#102a33]/55 backdrop-blur-sm md:items-center md:p-6"><div className="max-h-[95vh] w-full max-w-2xl overflow-auto rounded-t-3xl bg-white shadow-2xl md:rounded-3xl"><div className="sticky top-0 z-10 flex items-center justify-between border-b border-slate-100 bg-white px-6 py-5"><h2 className="text-lg font-black">{title}</h2><button onClick={onClose} className="rounded-full bg-slate-100 p-2 text-slate-500"><X size={18} /></button></div>{children}</div></div>;
}
