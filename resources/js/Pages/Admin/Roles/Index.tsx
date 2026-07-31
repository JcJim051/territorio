import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Check, Pencil, Plus, Shield, ShieldCheck, Trash2, UsersRound, X } from 'lucide-react';
import { FormEvent, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';

type Role = {
    id: number;
    name: string;
    slug: string;
    permissions: string[];
    isSystem: boolean;
    assignmentLevel: number;
    membershipsCount: number;
    activeMembershipsCount: number;
};
type PermissionGroup = { name: string; permissions: Array<{ key: string; label: string }> };
type RoleForm = { name: string; slug: string; permissions: string[]; assignment_level: number };
const emptyForm: RoleForm = { name: '', slug: '', permissions: [], assignment_level: 10 };

export default function Roles({ roles, permissionGroups, canManageDefinitions }: {
    roles: Role[];
    permissionGroups: PermissionGroup[];
    canManageDefinitions: boolean;
}) {
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Role | null>(null);
    const form = useForm<RoleForm>(emptyForm);
    const canManage = canManageDefinitions;
    const canDelete = canManageDefinitions;
    const allKeys = permissionGroups.flatMap((group) => group.permissions.map((permission) => permission.key));
    const isFullAdministrator = form.data.permissions.includes('*');

    const startCreate = () => {
        setEditing(null);
        form.setData(emptyForm);
        form.clearErrors();
        setOpen(true);
    };
    const startEdit = (role: Role) => {
        setEditing(role);
        form.setData({ name: role.name, slug: role.slug, permissions: role.permissions, assignment_level: role.assignmentLevel });
        form.clearErrors();
        setOpen(true);
    };
    const togglePermission = (key: string) => {
        if (isFullAdministrator) return;
        form.setData('permissions', form.data.permissions.includes(key)
            ? form.data.permissions.filter((permission) => permission !== key)
            : [...form.data.permissions, key]);
    };
    const toggleGroup = (group: PermissionGroup) => {
        if (isFullAdministrator) return;
        const keys = group.permissions.map((permission) => permission.key);
        const groupSelected = keys.every((key) => form.data.permissions.includes(key));
        form.setData('permissions', groupSelected
            ? form.data.permissions.filter((key) => !keys.includes(key))
            : Array.from(new Set([...form.data.permissions, ...keys])));
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { onSuccess: () => { setOpen(false); setEditing(null); form.reset(); } };
        editing ? form.put(`/admin/roles/${editing.id}`, options) : form.post('/admin/roles', options);
    };
    const remove = (role: Role) => {
        if (window.confirm(`¿Eliminar el rol “${role.name}”?`)) router.delete(`/admin/roles/${role.id}`, { preserveScroll: true });
    };

    return (
        <AppLayout title="Roles y permisos" eyebrow="Gobierno de accesos">
            <Head title="Roles y permisos" />
            <div className="mb-6 flex flex-col justify-between gap-4 md:flex-row md:items-end">
                <div>
                    <div className="mb-3 flex gap-1 rounded-xl bg-slate-100 p-1">
                        <Link href="/admin/users" className="rounded-lg px-4 py-2 text-xs font-bold text-slate-500 hover:text-slate-800">Usuarios</Link>
                        <Link href="/admin/roles" className="rounded-lg bg-white px-4 py-2 text-xs font-black text-[#0d4d4b] shadow-sm">Roles y permisos</Link>
                    </div>
                    <p className="text-sm text-slate-500">Define con precisión qué puede consultar y gestionar cada equipo.</p>
                </div>
                {canManage && <button onClick={startCreate} className="primary-button"><Plus size={17} /> Crear rol</button>}
            </div>
            {(errors.role || errors.permissions) && <div className="mb-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-700">{errors.role ?? errors.permissions}</div>}

            <section className="grid gap-4 lg:grid-cols-2 2xl:grid-cols-3">
                {roles.map((role) => {
                    const administrator = role.permissions.includes('*');
                    const permissionCount = administrator ? allKeys.length : role.permissions.length;
                    return <article key={role.id} className="panel flex flex-col p-5">
                        <div className="flex items-start justify-between gap-3">
                            <div className="flex items-center gap-3"><div className={`grid size-11 place-items-center rounded-xl ${administrator ? 'bg-[#d9f0e8] text-[#0d4d4b]' : 'bg-slate-100 text-slate-600'}`}>{administrator ? <ShieldCheck size={20} /> : <Shield size={20} />}</div><div><div className="flex items-center gap-2"><h2 className="font-black text-slate-800">{role.name}</h2>{role.isSystem && <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[9px] font-black uppercase text-slate-500">Oficial</span>}</div><div className="mt-0.5 text-[11px] text-slate-400">{role.slug} · nivel {role.assignmentLevel}</div></div></div>
                            <div className="flex gap-1">{canManage && <button onClick={() => startEdit(role)} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100" title="Editar"><Pencil size={16} /></button>}{canDelete && !role.isSystem && role.membershipsCount === 0 && <button onClick={() => remove(role)} className="rounded-lg p-2 text-red-500 hover:bg-red-50" title="Eliminar"><Trash2 size={16} /></button>}</div>
                        </div>
                        <div className="mt-5 grid grid-cols-2 gap-3">
                            <div className="rounded-xl bg-slate-50 p-3"><div className="text-xl font-black text-slate-800">{permissionCount}</div><div className="text-[10px] font-bold uppercase text-slate-400">Permisos</div></div>
                            <div className="rounded-xl bg-slate-50 p-3"><div className="flex items-center gap-1.5 text-xl font-black text-slate-800"><UsersRound size={17} />{role.activeMembershipsCount}</div><div className="text-[10px] font-bold uppercase text-slate-400">Usuarios activos</div></div>
                        </div>
                        <div className="mt-4 flex-1">
                            {administrator ? <div className="rounded-xl bg-[#d9f0e8]/60 p-3 text-xs font-bold text-[#0d4d4b]">Control completo sobre todos los módulos de la campaña.</div> : <div className="flex flex-wrap gap-1.5">{role.permissions.slice(0, 6).map((permission) => <span key={permission} className="rounded-lg bg-slate-100 px-2 py-1 text-[10px] font-semibold text-slate-500">{permission}</span>)}{role.permissions.length > 6 && <span className="rounded-lg bg-slate-100 px-2 py-1 text-[10px] font-bold text-slate-500">+{role.permissions.length - 6}</span>}{role.permissions.length === 0 && <span className="text-xs text-slate-400">Sin permisos asignados.</span>}</div>}
                        </div>
                    </article>;
                })}
                {roles.length === 0 && <div className="panel col-span-full p-14 text-center text-sm text-slate-400">No hay roles configurados.</div>}
            </section>

            {open && <Modal title={editing ? 'Editar rol' : 'Crear rol'} onClose={() => setOpen(false)}>
                <form onSubmit={submit}>
                    <div className="grid gap-4 border-b border-slate-100 p-6 md:grid-cols-2">
                        <Field label="Nombre del rol" error={form.errors.name}><input className="field" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} required /></Field>
                        <Field label="Identificador" error={form.errors.slug}><input className="field" value={form.data.slug} onChange={(event) => form.setData('slug', event.target.value)} placeholder="Se genera desde el nombre" /></Field>
                        <Field label="Nivel de asignación" error={form.errors.assignment_level}><input type="number" min={1} max={100} className="field" value={form.data.assignment_level} onChange={(event) => form.setData('assignment_level', Number(event.target.value))} required /></Field>
                    </div>
                    <div className="space-y-4 p-6">
                        <div className={isFullAdministrator ? 'pointer-events-none opacity-45' : ''}>
                            <div className="mb-3 flex items-center justify-between"><div><h3 className="text-sm font-black text-slate-800">Permisos granulares</h3><p className="text-[11px] text-slate-400">{form.data.permissions.length} seleccionados</p></div>{!isFullAdministrator && <button type="button" onClick={() => form.setData('permissions', form.data.permissions.length === allKeys.length ? [] : allKeys)} className="text-xs font-bold text-[#0d4d4b]">{form.data.permissions.length === allKeys.length ? 'Quitar todos' : 'Seleccionar todos'}</button>}</div>
                            <div className="space-y-3">{permissionGroups.map((group) => {
                                const keys = group.permissions.map((permission) => permission.key);
                                const selected = keys.every((key) => form.data.permissions.includes(key));
                                return <section key={group.name} className="rounded-xl border border-slate-200 p-4"><button type="button" onClick={() => toggleGroup(group)} className="mb-3 flex w-full items-center justify-between text-left"><span className="text-xs font-black uppercase tracking-wide text-slate-600">{group.name}</span><span className={`grid size-5 place-items-center rounded border ${selected ? 'border-[#0d4d4b] bg-[#0d4d4b] text-white' : 'border-slate-300'}`}>{selected && <Check size={13} />}</span></button><div className="grid gap-2 md:grid-cols-2">{group.permissions.map((permission) => <label key={permission.key} className="flex cursor-pointer items-start gap-2 rounded-lg p-2 hover:bg-slate-50"><input type="checkbox" checked={form.data.permissions.includes(permission.key)} onChange={() => togglePermission(permission.key)} className="mt-0.5 size-4 accent-[#0d4d4b]" /><span><span className="block text-xs font-semibold text-slate-700">{permission.label}</span><span className="block text-[9px] text-slate-400">{permission.key}</span></span></label>)}</div></section>;
                            })}</div>
                        </div>
                        {form.errors.permissions && <p className="text-xs font-semibold text-red-600">{form.errors.permissions}</p>}
                        <div className="flex justify-end gap-2 border-t border-slate-100 pt-5"><button type="button" onClick={() => setOpen(false)} className="secondary-button">Cancelar</button><button className="primary-button" disabled={form.processing}>{editing ? 'Guardar cambios' : 'Crear rol'}</button></div>
                    </div>
                </form>
            </Modal>}
        </AppLayout>
    );
}

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
    return <div><label className="label">{label}</label>{children}{error && <p className="mt-1 text-xs font-semibold text-red-600">{error}</p>}</div>;
}
function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: React.ReactNode }) {
    return <div className="fixed inset-0 z-50 flex items-end justify-center bg-[#102a33]/55 backdrop-blur-sm md:items-center md:p-6"><div className="max-h-[96vh] w-full max-w-4xl overflow-auto rounded-t-3xl bg-white shadow-2xl md:rounded-3xl"><div className="sticky top-0 z-10 flex items-center justify-between border-b border-slate-100 bg-white px-6 py-5"><h2 className="text-lg font-black">{title}</h2><button onClick={onClose} className="rounded-full bg-slate-100 p-2 text-slate-500"><X size={18} /></button></div>{children}</div></div>;
}
