import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Clock3, KeyRound, MapPinned, Pencil, Plus, Search, Shield, Trash2, UserCheck, UserX, X } from 'lucide-react';
import { FormEvent, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { SharedProps } from '@/types';

type Membership = {
    id: number;
    userId: number;
    name: string;
    email: string;
    roleId?: number;
    role?: string;
    isAdministrator: boolean;
    isSuperAdmin: boolean;
    accountActive: boolean;
    membershipActive: boolean;
    territoryIds: number[];
    lastAccessedAt?: string;
    createdAt: string;
    manageable: boolean;
};
type Role = { id: number; name: string; permissions: string[]; memberships_count: number; assignment_level: number; assignable: boolean };
type Territory = { id: number; name: string; type: string };
type UserForm = {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
    campaign_role_id: string;
    territory_unit_ids: number[];
    is_active: boolean;
    account_active: boolean;
    is_super_admin: boolean;
};

const emptyForm: UserForm = {
    name: '', email: '', password: '', password_confirmation: '', campaign_role_id: '',
    territory_unit_ids: [], is_active: true, account_active: true, is_super_admin: false,
};

export default function Users({ memberships, roles, territories, filters, capabilities }: {
    memberships: { data: Membership[]; links: Array<{ url?: string; label: string; active: boolean }>; total: number };
    roles: Role[];
    territories: Territory[];
    filters: { search?: string; role?: number; status?: string };
    capabilities: { canPromoteSuperAdmin: boolean };
}) {
    const { auth, currentCampaign, errors } = usePage<SharedProps>().props;
    const [search, setSearch] = useState(filters.search ?? '');
    const [role, setRole] = useState(filters.role ? String(filters.role) : '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Membership | null>(null);
    const form = useForm<UserForm>(emptyForm);
    const canManage = currentCampaign?.permissions.includes('*') || currentCampaign?.permissions.includes('users.manage');
    const canDelete = currentCampaign?.permissions.includes('*') || currentCampaign?.permissions.includes('users.delete');

    const applyFilters = (nextRole = role, nextStatus = status) => {
        router.get('/admin/users', { search, role: nextRole, status: nextStatus }, { preserveState: true, replace: true });
    };
    const startCreate = () => {
        setEditing(null);
        const firstAssignable = roles.find((item) => item.assignable);
        form.setData({ ...emptyForm, campaign_role_id: firstAssignable ? String(firstAssignable.id) : '' });
        form.clearErrors();
        setOpen(true);
    };
    const startEdit = (membership: Membership) => {
        setEditing(membership);
        form.setData({
            name: membership.name,
            email: membership.email,
            password: '',
            password_confirmation: '',
            campaign_role_id: membership.roleId ? String(membership.roleId) : '',
            territory_unit_ids: membership.territoryIds,
            is_active: membership.membershipActive,
            account_active: membership.accountActive,
            is_super_admin: membership.isSuperAdmin,
        });
        form.clearErrors();
        setOpen(true);
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { onSuccess: () => { setOpen(false); setEditing(null); form.reset(); } };
        editing ? form.put(`/admin/users/${editing.id}`, options) : form.post('/admin/users', options);
    };
    const remove = (membership: Membership) => {
        if (window.confirm(`¿Retirar el acceso de ${membership.name} a esta campaña? Su participación en otras campañas no será afectada.`)) {
            router.delete(`/admin/users/${membership.id}`, { preserveScroll: true });
        }
    };
    const toggleTerritory = (id: number) => form.setData('territory_unit_ids', form.data.territory_unit_ids.includes(id)
        ? form.data.territory_unit_ids.filter((item) => item !== id)
        : [...form.data.territory_unit_ids, id]);

    return (
        <AppLayout title="Usuarios" eyebrow="Administración y accesos">
            <Head title="Usuarios" />
            <div className="mb-6 flex flex-col justify-between gap-4 xl:flex-row xl:items-end">
                <div>
                    <div className="mb-3 flex gap-1 rounded-xl bg-slate-100 p-1">
                        <Link href="/admin/users" className="rounded-lg bg-white px-4 py-2 text-xs font-black text-[#0d4d4b] shadow-sm">Usuarios</Link>
                        <Link href="/admin/roles" className="rounded-lg px-4 py-2 text-xs font-bold text-slate-500 hover:text-slate-800">Roles y permisos</Link>
                    </div>
                    <p className="text-sm text-slate-500">{memberships.total} accesos configurados para la campaña activa.</p>
                </div>
                <div className="flex flex-col gap-2 sm:flex-row">
                    <form onSubmit={(event) => { event.preventDefault(); applyFilters(); }} className="flex overflow-hidden rounded-xl border border-slate-200 bg-white">
                        <input className="w-52 px-3 py-2 text-sm outline-none" placeholder="Nombre o correo…" value={search} onChange={(event) => setSearch(event.target.value)} />
                        <button className="px-3 text-slate-500" aria-label="Buscar"><Search size={17} /></button>
                    </form>
                    <select className="field min-w-40 py-2" value={role} onChange={(event) => { setRole(event.target.value); applyFilters(event.target.value, status); }}><option value="">Todos los roles</option>{roles.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select>
                    <select className="field min-w-36 py-2" value={status} onChange={(event) => { setStatus(event.target.value); applyFilters(role, event.target.value); }}><option value="">Todos</option><option value="active">Activos</option><option value="inactive">Inactivos</option></select>
                    {canManage && <button onClick={startCreate} className="primary-button"><Plus size={17} /> Crear usuario</button>}
                </div>
            </div>

            {errors.user && <Alert>{errors.user}</Alert>}
            <div className="panel overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[980px] text-left">
                        <thead><tr className="border-b border-slate-100 bg-slate-50 text-[10px] font-black uppercase tracking-[.13em] text-slate-400"><th className="px-5 py-3">Usuario</th><th className="px-5 py-3">Rol</th><th className="px-5 py-3">Alcance territorial</th><th className="px-5 py-3">Último acceso</th><th className="px-5 py-3">Estado</th><th className="px-5 py-3 text-right">Acciones</th></tr></thead>
                        <tbody className="divide-y divide-slate-100">
                            {memberships.data.map((membership) => {
                                const active = membership.accountActive && membership.membershipActive;
                                const territoryNames = territories.filter((item) => membership.territoryIds.includes(item.id)).map((item) => item.name);
                                return <tr key={membership.id} className="hover:bg-slate-50/60">
                                    <td className="px-5 py-4"><div className="flex items-center gap-3"><div className={`grid size-10 place-items-center rounded-xl text-sm font-black ${membership.isAdministrator ? 'bg-[#d9f0e8] text-[#0d4d4b]' : 'bg-slate-100 text-slate-600'}`}>{membership.name.split(' ').map((part) => part[0]).slice(0, 2).join('')}</div><div><div className="flex items-center gap-1.5 text-sm font-black text-slate-800">{membership.name}{membership.isSuperAdmin && <Shield size={13} className="text-[#e8754f]" />}</div><div className="mt-0.5 text-[11px] text-slate-400">{membership.email}</div></div></div></td>
                                    <td className="px-5 py-4"><div className="text-xs font-bold text-slate-700">{membership.role ?? 'Sin rol'}</div>{membership.isAdministrator && <div className="mt-1 text-[10px] font-bold text-[#0d4d4b]">Acceso administrativo</div>}</td>
                                    <td className="px-5 py-4"><div className="flex items-center gap-1.5 text-xs font-semibold text-slate-600"><MapPinned size={13} /> {territoryNames.length ? territoryNames.slice(0, 2).join(', ') : 'Toda la campaña'}</div>{territoryNames.length > 2 && <div className="mt-1 text-[10px] text-slate-400">+{territoryNames.length - 2} territorios</div>}</td>
                                    <td className="px-5 py-4"><div className="flex items-center gap-1.5 text-xs text-slate-500"><Clock3 size={13} /> {membership.lastAccessedAt ? new Date(membership.lastAccessedAt).toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' }) : 'Nunca'}</div></td>
                                    <td className="px-5 py-4"><span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[10px] font-black uppercase ${active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>{active ? <UserCheck size={11} /> : <UserX size={11} />}{active ? 'Activo' : 'Inactivo'}</span></td>
                                    <td className="px-5 py-4"><div className="flex justify-end gap-1">{canManage && membership.manageable && <button onClick={() => startEdit(membership)} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100" title="Editar"><Pencil size={16} /></button>}{canDelete && membership.manageable && membership.userId !== auth.user?.id && <button onClick={() => remove(membership)} className="rounded-lg p-2 text-red-500 hover:bg-red-50" title="Retirar de la campaña"><Trash2 size={16} /></button>}</div></td>
                                </tr>;
                            })}
                            {memberships.data.length === 0 && <tr><td colSpan={6} className="p-14 text-center text-sm text-slate-400">No hay usuarios con estos filtros.</td></tr>}
                        </tbody>
                    </table>
                </div>
                {memberships.links.length > 3 && <div className="flex justify-center gap-1 border-t border-slate-100 p-4">{memberships.links.map((link, index) => <button key={index} disabled={!link.url} onClick={() => link.url && router.visit(link.url)} className={`rounded-lg px-3 py-1.5 text-xs font-bold ${link.active ? 'bg-[#0d4d4b] text-white' : 'text-slate-500 hover:bg-slate-100 disabled:opacity-30'}`} dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>}
            </div>

            {open && <Modal title={editing ? 'Editar usuario y acceso' : 'Crear usuario'} onClose={() => setOpen(false)}>
                <form onSubmit={submit} className="grid gap-4 p-6 md:grid-cols-2">
                    <Field label="Nombre completo" error={form.errors.name} wide><input className="field" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} required /></Field>
                    <Field label="Correo electrónico" error={form.errors.email}><input type="email" className="field" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} required /></Field>
                    <Field label="Rol en esta campaña" error={form.errors.campaign_role_id}><select className="field" value={form.data.campaign_role_id} onChange={(event) => form.setData('campaign_role_id', event.target.value)} required><option value="">Seleccionar rol</option>{roles.filter((item) => item.assignable || item.id === editing?.roleId).map((item) => <option key={item.id} value={item.id} disabled={!item.assignable}>{item.name} · nivel {item.assignment_level}</option>)}</select></Field>
                    <Field label={editing ? 'Nueva contraseña (opcional)' : 'Contraseña temporal'} error={form.errors.password}><div className="relative"><KeyRound size={15} className="absolute left-3 top-3 text-slate-400" /><input type="password" minLength={8} className="field pl-9" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} required={!editing} /></div></Field>
                    <Field label="Confirmar contraseña" error={form.errors.password_confirmation}><input type="password" minLength={8} className="field" value={form.data.password_confirmation} onChange={(event) => form.setData('password_confirmation', event.target.value)} required={!editing || !!form.data.password} /></Field>
                    <div className="md:col-span-2"><label className="label">Alcance territorial</label><p className="mb-2 text-[11px] text-slate-400">Sin selección, el usuario tendrá alcance sobre toda la campaña.</p><div className="grid max-h-40 gap-2 overflow-auto rounded-xl border border-slate-200 p-3 sm:grid-cols-2">{territories.length === 0 && <span className="text-xs text-slate-400">No hay territorios operativos configurados.</span>}{territories.map((territory) => <label key={territory.id} className="flex items-center gap-2 rounded-lg p-2 text-xs font-semibold text-slate-600 hover:bg-slate-50"><input type="checkbox" checked={form.data.territory_unit_ids.includes(territory.id)} onChange={() => toggleTerritory(territory.id)} className="size-4 accent-[#0d4d4b]" />{territory.name}</label>)}</div>{form.errors.territory_unit_ids && <p className="mt-1 text-xs font-semibold text-red-600">{form.errors.territory_unit_ids}</p>}</div>
                    <div className="space-y-2 rounded-xl bg-slate-50 p-4 md:col-span-2">
                        <label className="flex items-center justify-between gap-3 text-sm font-semibold text-slate-700"><span>Acceso activo en esta campaña</span><input type="checkbox" checked={form.data.is_active} disabled={editing?.userId === auth.user?.id} onChange={(event) => form.setData('is_active', event.target.checked)} className="size-4 accent-[#0d4d4b]" /></label>
                        {capabilities.canPromoteSuperAdmin && <><label className="flex items-center justify-between gap-3 text-sm font-semibold text-slate-700"><span>Cuenta activa globalmente</span><input type="checkbox" checked={form.data.account_active} disabled={editing?.userId === auth.user?.id} onChange={(event) => form.setData('account_active', event.target.checked)} className="size-4 accent-[#0d4d4b]" /></label><label className="flex items-center justify-between gap-3 text-sm font-semibold text-slate-700"><span>Superadministrador de la plataforma</span><input type="checkbox" checked={form.data.is_super_admin} disabled={editing?.userId === auth.user?.id} onChange={(event) => form.setData('is_super_admin', event.target.checked)} className="size-4 accent-[#0d4d4b]" /></label></>}
                    </div>
                    <div className="flex justify-end gap-2 border-t border-slate-100 pt-4 md:col-span-2"><button type="button" onClick={() => setOpen(false)} className="secondary-button">Cancelar</button><button className="primary-button" disabled={form.processing}>{editing ? 'Guardar cambios' : 'Crear usuario'}</button></div>
                </form>
            </Modal>}
        </AppLayout>
    );
}

function Alert({ children }: { children: React.ReactNode }) {
    return <div className="mb-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-700">{children}</div>;
}
function Field({ label, error, wide, children }: { label: string; error?: string; wide?: boolean; children: React.ReactNode }) {
    return <div className={wide ? 'md:col-span-2' : ''}><label className="label">{label}</label>{children}{error && <p className="mt-1 text-xs font-semibold text-red-600">{error}</p>}</div>;
}
function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: React.ReactNode }) {
    return <div className="fixed inset-0 z-50 flex items-end justify-center bg-[#102a33]/55 backdrop-blur-sm md:items-center md:p-6"><div className="max-h-[95vh] w-full max-w-2xl overflow-auto rounded-t-3xl bg-white shadow-2xl md:rounded-3xl"><div className="sticky top-0 z-10 flex items-center justify-between border-b border-slate-100 bg-white px-6 py-5"><h2 className="text-lg font-black">{title}</h2><button onClick={onClose} className="rounded-full bg-slate-100 p-2 text-slate-500"><X size={18} /></button></div>{children}</div></div>;
}
