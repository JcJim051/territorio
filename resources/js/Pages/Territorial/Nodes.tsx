import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Ban,
    Check,
    Copy,
    ExternalLink,
    GitBranch,
    KeyRound,
    Pencil,
    Plus,
    RefreshCw,
    Search,
    Share2,
    ShieldCheck,
    Trash2,
    UsersRound,
    X,
} from 'lucide-react';
import { FormEvent, useMemo, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';

type Token = {
    id: number;
    label?: string;
    active: boolean;
    uses: number;
    maxUses?: number;
    expiresAt?: string;
    revokedAt?: string;
    territoryIds: number[];
    link?: string;
};
type Node = {
    id: string;
    name: string;
    document: string;
    status: string;
    commune?: string;
    place?: string;
    children: number;
    promotedAt?: string;
    token?: Token;
};
type Eligible = { id: string; name: string; document: string; commune?: string; place?: string; territoryId?: number };
type Territory = { id: number; name: string; type: string };
type TokenForm = { person_id: string; label: string; expires_at: string; max_uses: string; territory_unit_ids: number[] };
type Page<T> = { data: T[]; links: Array<{ url?: string; label: string; active: boolean }>; total: number };

const emptyForm = (expiresAt: string): TokenForm => ({
    person_id: '', label: '', expires_at: expiresAt, max_uses: '', territory_unit_ids: [],
});

export default function Nodes({ nodes, eligiblePeople, territories, filters, defaults }: {
    nodes: Page<Node>;
    eligiblePeople: Eligible[];
    territories: Territory[];
    filters: { search?: string; status?: string };
    defaults: { expiresAt: string };
}) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [personSearch, setPersonSearch] = useState('');
    const [modal, setModal] = useState<'promote' | 'edit' | 'rotate' | null>(null);
    const [selectedNode, setSelectedNode] = useState<Node | null>(null);
    const [copied, setCopied] = useState<number | null>(null);
    const form = useForm<TokenForm>(emptyForm(defaults.expiresAt));
    const eligibleFiltered = useMemo(() => {
        const term = personSearch.toLocaleLowerCase();
        return eligiblePeople.filter((person) => `${person.name} ${person.document} ${person.commune ?? ''}`.toLocaleLowerCase().includes(term)).slice(0, 40);
    }, [eligiblePeople, personSearch]);

    const applyFilters = (nextStatus = status) => router.get('/territorial/nodes', {
        search, status: nextStatus,
    }, { preserveState: true, replace: true });

    const startPromote = () => {
        setSelectedNode(null);
        setPersonSearch('');
        form.setData(emptyForm(defaults.expiresAt));
        form.clearErrors();
        setModal('promote');
    };

    const startConfigure = (node: Node, action: 'edit' | 'rotate') => {
        setSelectedNode(node);
        form.setData({
            person_id: node.id,
            label: node.token?.label ?? `Referidos de ${node.name}`,
            expires_at: action === 'rotate' ? defaults.expiresAt : (node.token?.expiresAt ?? ''),
            max_uses: node.token?.maxUses ? String(node.token.maxUses) : '',
            territory_unit_ids: node.token?.territoryIds ?? [],
        });
        form.clearErrors();
        setModal(action);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const payload = {
            ...form.data,
            max_uses: form.data.max_uses ? Number(form.data.max_uses) : null,
        };
        const options = { preserveScroll: true, onSuccess: () => { setModal(null); setSelectedNode(null); form.reset(); } };
        if (modal === 'promote') {
            router.post(`/territorial/nodes/${form.data.person_id}`, payload, options);
        } else if (modal === 'edit' && selectedNode?.token) {
            router.put(`/territorial/nodes/${selectedNode.id}/tokens/${selectedNode.token.id}`, payload, options);
        } else if (modal === 'rotate' && selectedNode) {
            router.post(`/territorial/nodes/${selectedNode.id}/rotate`, payload, options);
        }
    };

    const toggleTerritory = (id: number) => form.setData('territory_unit_ids',
        form.data.territory_unit_ids.includes(id)
            ? form.data.territory_unit_ids.filter((item) => item !== id)
            : [...form.data.territory_unit_ids, id],
    );

    const copyLink = async (token: Token) => {
        if (!token.link) return;
        await navigator.clipboard.writeText(token.link);
        setCopied(token.id);
        window.setTimeout(() => setCopied(null), 1800);
    };

    const shareLink = async (node: Node) => {
        if (!node.token?.link) return;
        if (navigator.share) {
            await navigator.share({ title: `Registro con ${node.name}`, text: `${node.name} te invita a vincularte a su red territorial.`, url: node.token.link });
        } else {
            await copyLink(node.token);
        }
    };

    const revoke = (node: Node) => {
        if (node.token && window.confirm(`¿Revocar el enlace de ${node.name}? Dejará de aceptar registros inmediatamente.`)) {
            router.delete(`/territorial/nodes/${node.id}/tokens/${node.token.id}`, { preserveScroll: true });
        }
    };

    const demote = (node: Node) => {
        if (window.confirm(`¿Retirar a ${node.name} como nodo? Esta acción solo será posible si todavía no tiene referidos.`)) {
            router.delete(`/territorial/nodes/${node.id}`, { preserveScroll: true });
        }
    };

    return <AppLayout title="Nodos y enlaces" eyebrow="Crecimiento territorial">
        <Head title="Nodos y enlaces" />

        <section className="mb-6 overflow-hidden rounded-2xl bg-[#102f35] p-6 text-white md:flex md:items-center md:justify-between md:p-7">
            <div>
                <div className="flex items-center gap-2 text-[10px] font-black uppercase tracking-[.17em] text-[#a8d7c8]"><GitBranch size={15} /> Red verificable por responsables</div>
                <h2 className="mt-2 text-2xl font-black">Convierte personas verificadas en nodos de crecimiento.</h2>
                <p className="mt-2 max-w-2xl text-sm leading-6 text-white/55">Cada enlace identifica al responsable, conserva la cadena de referidos y solo registra personas dentro de esta campaña.</p>
            </div>
            <button onClick={startPromote} className="mt-5 inline-flex items-center gap-2 rounded-xl bg-white px-4 py-3 text-sm font-black text-[#102f35] md:mt-0"><Plus size={17} /> Promover nuevo nodo</button>
        </section>

        <div className="mb-5 flex flex-col justify-between gap-3 lg:flex-row lg:items-center">
            <div><div className="text-sm font-black text-slate-700">{nodes.total} nodos en la campaña</div><div className="mt-1 flex items-center gap-1.5 text-[11px] text-slate-400"><ShieldCheck size={13} /> Los tokens no se guardan en texto plano y todos los cambios quedan auditados.</div></div>
            <div className="flex flex-col gap-2 sm:flex-row">
                <form onSubmit={(event) => { event.preventDefault(); applyFilters(); }} className="flex overflow-hidden rounded-xl border border-slate-200 bg-white"><input className="w-full px-3 py-2 text-sm outline-none sm:w-64" placeholder="Buscar nodo…" value={search} onChange={(event) => setSearch(event.target.value)} /><button className="px-3 text-slate-500"><Search size={17} /></button></form>
                <select className="field min-w-44 py-2" value={status} onChange={(event) => { setStatus(event.target.value); applyFilters(event.target.value); }}><option value="">Todos los enlaces</option><option value="active">Enlace activo</option><option value="inactive">Sin enlace activo</option></select>
            </div>
        </div>

        <div className="grid gap-4 xl:grid-cols-2 2xl:grid-cols-3">
            {nodes.data.map((node) => <article key={node.id} className="panel overflow-hidden">
                <div className="flex items-start gap-3 border-b border-slate-100 p-5">
                    <div className="grid size-11 shrink-0 place-items-center rounded-xl bg-[var(--campaign-accent-soft)] text-sm font-black text-[var(--campaign-accent)]">{initials(node.name)}</div>
                    <div className="min-w-0 flex-1"><Link href={`/people/${node.id}`} className="truncate font-black text-slate-800 hover:text-[var(--campaign-accent)]">{node.name}</Link><div className="mt-1 text-[11px] text-slate-400">C.C. {node.document} · {node.commune ?? node.place ?? 'Sin territorio'}</div></div>
                    <span className={`rounded-full px-2.5 py-1 text-[9px] font-black uppercase ${node.token?.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>{node.token?.active ? 'Activo' : 'Sin enlace'}</span>
                </div>

                <div className="p-5">
                    <div className="grid grid-cols-2 gap-3">
                        <div className="rounded-xl bg-slate-50 p-3"><div className="flex items-center gap-1.5 text-xl font-black text-slate-800"><UsersRound size={17} />{node.children}</div><div className="mt-1 text-[9px] font-black uppercase text-slate-400">Referidos directos</div></div>
                        <div className="rounded-xl bg-slate-50 p-3"><div className="text-xl font-black text-slate-800">{node.token?.uses ?? 0}{node.token?.maxUses ? <span className="text-xs text-slate-400"> / {node.token.maxUses}</span> : ''}</div><div className="mt-1 text-[9px] font-black uppercase text-slate-400">Registros por enlace</div></div>
                    </div>

                    {node.token?.active && node.token.link ? <>
                        <div className="mt-4 rounded-xl border border-emerald-100 bg-emerald-50/60 p-3">
                            <div className="text-[10px] font-black uppercase text-emerald-700">Enlace individual</div>
                            <div className="mt-1 truncate text-xs text-emerald-900/65">{node.token.link}</div>
                            <div className="mt-3 grid grid-cols-2 gap-2">
                                <button onClick={() => copyLink(node.token!)} className="secondary-button justify-center bg-white">{copied === node.token.id ? <Check size={16} /> : <Copy size={16} />}{copied === node.token.id ? 'Copiado' : 'Copiar'}</button>
                                <button onClick={() => shareLink(node)} className="primary-button justify-center"><Share2 size={16} /> Compartir</button>
                            </div>
                        </div>
                        <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-[10px] text-slate-400">
                            <span>{node.token.expiresAt ? `Vence ${formatDate(node.token.expiresAt)}` : 'Sin vencimiento'}</span>
                            <a href={node.token.link} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 font-bold text-[var(--campaign-accent)]">Abrir formulario <ExternalLink size={11} /></a>
                        </div>
                    </> : <div className="mt-4 rounded-xl bg-amber-50 p-3 text-xs leading-5 text-amber-800">{node.token && !node.token.link ? 'Este es un enlace anterior no recuperable. Rótalo para obtener uno compartible.' : 'El nodo no tiene un enlace habilitado para recibir referidos.'}</div>}

                    <div className="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-4">
                        {node.token?.active ? <>
                            <button onClick={() => startConfigure(node, 'edit')} className="secondary-button px-3 py-2 text-xs"><Pencil size={14} /> Configurar</button>
                            <button onClick={() => startConfigure(node, 'rotate')} className="secondary-button px-3 py-2 text-xs"><RefreshCw size={14} /> Rotar</button>
                            <button onClick={() => revoke(node)} className="secondary-button px-3 py-2 text-xs text-red-600"><Ban size={14} /> Revocar</button>
                        </> : <button onClick={() => startConfigure(node, 'rotate')} className="primary-button px-3 py-2 text-xs"><KeyRound size={14} /> Generar enlace</button>}
                        {node.children === 0 && <button onClick={() => demote(node)} className="ml-auto rounded-lg p-2 text-slate-400 hover:bg-red-50 hover:text-red-600" title="Retirar como nodo"><Trash2 size={15} /></button>}
                    </div>
                </div>
            </article>)}
            {nodes.data.length === 0 && <div className="panel col-span-full p-14 text-center"><GitBranch className="mx-auto text-slate-300" size={34} /><h2 className="mt-4 font-black text-slate-700">Aún no hay nodos con estos filtros</h2><button onClick={startPromote} className="primary-button mx-auto mt-4"><Plus size={16} /> Crear primer nodo</button></div>}
        </div>

        {nodes.links.length > 3 && <div className="mt-5 flex justify-center gap-1">{nodes.links.map((link, index) => <button key={index} disabled={!link.url} onClick={() => link.url && router.visit(link.url)} className={`rounded-lg px-3 py-2 text-xs font-bold ${link.active ? 'bg-[var(--campaign-accent)] text-[var(--campaign-contrast)]' : 'bg-white text-slate-500 disabled:opacity-30'}`} dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>}

        {modal && <Modal title={modal === 'promote' ? 'Promover persona a nodo' : modal === 'rotate' ? 'Generar un nuevo enlace' : 'Configurar enlace'} onClose={() => setModal(null)}>
            <form onSubmit={submit} className="space-y-5 p-6">
                {modal === 'promote' && <div>
                    <label className="label">Persona verificada</label>
                    <input className="field mb-2" placeholder="Filtrar por nombre, cédula o comuna…" value={personSearch} onChange={(event) => setPersonSearch(event.target.value)} />
                    <select className="field" value={form.data.person_id} onChange={(event) => {
                        const person = eligiblePeople.find((item) => item.id === event.target.value);
                        form.setData({
                            ...form.data,
                            person_id: event.target.value,
                            territory_unit_ids: person?.territoryId ? [person.territoryId] : [],
                        });
                    }} required><option value="">Selecciona una persona</option>{eligibleFiltered.map((person) => <option key={person.id} value={person.id}>{person.name} · C.C. {person.document}{person.commune ? ` · ${person.commune}` : ''}</option>)}</select>
                    {form.errors.person_id && <Error text={form.errors.person_id} />}
                    {eligiblePeople.length === 0 && <p className="mt-2 text-xs text-amber-700">No hay personas verificadas pendientes de promoción.</p>}
                </div>}
                {selectedNode && <div className="rounded-xl bg-[var(--campaign-accent-soft)] p-3 text-sm font-black text-[var(--campaign-accent)]">{selectedNode.name}</div>}
                <div><label className="label">Nombre interno del enlace</label><input className="field" value={form.data.label} onChange={(event) => form.setData('label', event.target.value)} placeholder="Ej. Referidos del coordinador de Comuna 1" />{form.errors.label && <Error text={form.errors.label} />}</div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <div><label className="label">Vencimiento</label><input type="date" className="field" value={form.data.expires_at} onChange={(event) => form.setData('expires_at', event.target.value)} /><div className="mt-1 text-[10px] text-slate-400">Déjalo vacío para no vencer.</div>{form.errors.expires_at && <Error text={form.errors.expires_at} />}</div>
                    <div><label className="label">Máximo de registros</label><input type="number" min={1} max={1000000} className="field" value={form.data.max_uses} onChange={(event) => form.setData('max_uses', event.target.value)} placeholder="Sin límite" />{form.errors.max_uses && <Error text={form.errors.max_uses} />}</div>
                </div>
                <div>
                    <div className="flex items-end justify-between"><div><label className="label">Alcance territorial</label><p className="text-[10px] text-slate-400">Sin selección permite todos los territorios autorizados.</p></div>{form.data.territory_unit_ids.length > 0 && <button type="button" onClick={() => form.setData('territory_unit_ids', [])} className="text-xs font-bold text-[var(--campaign-accent)]">Limpiar</button>}</div>
                    <div className="mt-3 max-h-48 space-y-1 overflow-auto rounded-xl border border-slate-200 p-2">{territories.map((territory) => <label key={territory.id} className="flex cursor-pointer items-center gap-2 rounded-lg p-2 hover:bg-slate-50"><input type="checkbox" checked={form.data.territory_unit_ids.includes(territory.id)} onChange={() => toggleTerritory(territory.id)} className="size-4 accent-[var(--campaign-accent)]" /><span className="text-xs font-semibold text-slate-600">{territory.name}</span><span className="ml-auto text-[9px] uppercase text-slate-300">{territory.type}</span></label>)}{territories.length === 0 && <p className="p-3 text-xs text-slate-400">No hay territorios operativos parametrizados.</p>}</div>
                    {form.errors.territory_unit_ids && <Error text={form.errors.territory_unit_ids} />}
                </div>
                {modal === 'rotate' && <div className="rounded-xl bg-amber-50 p-3 text-xs leading-5 text-amber-800"><strong>Rotar invalida inmediatamente el enlace anterior.</strong> Las personas ya registradas y la estructura construida se conservan.</div>}
                <div className="flex justify-end gap-2 border-t border-slate-100 pt-5"><button type="button" onClick={() => setModal(null)} className="secondary-button">Cancelar</button><button className="primary-button" disabled={form.processing || (modal === 'promote' && !form.data.person_id)}>{modal === 'edit' ? 'Guardar configuración' : modal === 'rotate' ? 'Rotar y generar' : 'Promover y generar'}</button></div>
            </form>
        </Modal>}
    </AppLayout>;
}

function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: React.ReactNode }) {
    return <div className="fixed inset-0 z-50 flex items-end justify-center bg-[#102a33]/55 backdrop-blur-sm md:items-center md:p-6"><div className="max-h-[96vh] w-full max-w-2xl overflow-auto rounded-t-3xl bg-white shadow-2xl md:rounded-3xl"><div className="sticky top-0 z-10 flex items-center justify-between border-b border-slate-100 bg-white px-6 py-5"><h2 className="text-lg font-black">{title}</h2><button onClick={onClose} className="rounded-full bg-slate-100 p-2 text-slate-500"><X size={18} /></button></div>{children}</div></div>;
}
function Error({ text }: { text: string }) { return <p className="mt-1.5 text-xs font-semibold text-red-600">{text}</p>; }
function initials(name: string) { return name.split(' ').filter(Boolean).map((part) => part[0]).slice(0, 2).join('').toLocaleUpperCase(); }
function formatDate(value: string) { return new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium' }).format(new Date(`${value}T12:00:00`)); }
