import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    Check,
    Flag,
    MapPin,
    Pencil,
    Plus,
    Power,
    Trash2,
    UsersRound,
    X,
} from 'lucide-react';
import { FormEvent, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { SharedProps } from '@/types';
import { campaignContrast } from '@/lib/campaignColor';

type Campaign = {
    id: number;
    organization: string;
    name: string;
    slug: string;
    candidateName: string;
    office: string;
    territory: string;
    startsAt?: string;
    electionAt?: string;
    status: 'active' | 'inactive';
    timezone: string;
    themeColor: string;
    enabledModules: string[];
    membershipsCount: number;
    personsCount: number;
    meetingsCount: number;
};
type ModuleOption = { key: string; label: string };
type CampaignForm = {
    name: string;
    slug: string;
    candidate_name: string;
    office: string;
    territory: string;
    starts_at: string;
    election_at: string;
    status: 'active' | 'inactive';
    timezone: string;
    theme_color: string;
    enabled_modules: string[];
};

const emptyForm: CampaignForm = {
    name: '',
    slug: '',
    candidate_name: '',
    office: '',
    territory: '',
    starts_at: '',
    election_at: '',
    status: 'active',
    timezone: 'America/Bogota',
    theme_color: '#0D4D4B',
    enabled_modules: ['territorial', 'meetings', 'inventory', 'analytics', 'calendar'],
};

export default function CampaignsIndex({ campaignsList, modules, timezones }: {
    campaignsList: Campaign[];
    modules: ModuleOption[];
    timezones: string[];
}) {
    const { currentCampaign, errors } = usePage<SharedProps>().props;
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Campaign | null>(null);
    const form = useForm<CampaignForm>(emptyForm);

    const startCreate = () => {
        setEditing(null);
        form.setData(emptyForm);
        form.clearErrors();
        setOpen(true);
    };
    const startEdit = (campaign: Campaign) => {
        setEditing(campaign);
        form.setData({
            name: campaign.name,
            slug: campaign.slug,
            candidate_name: campaign.candidateName,
            office: campaign.office,
            territory: campaign.territory,
            starts_at: campaign.startsAt ?? '',
            election_at: campaign.electionAt ?? '',
            status: campaign.status,
            timezone: campaign.timezone,
            theme_color: campaign.themeColor,
            enabled_modules: campaign.enabledModules,
        });
        form.clearErrors();
        setOpen(true);
    };
    const toggleModule = (key: string) => form.setData(
        'enabled_modules',
        form.data.enabled_modules.includes(key)
            ? form.data.enabled_modules.filter((module) => module !== key)
            : [...form.data.enabled_modules, key],
    );
    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                setEditing(null);
                form.reset();
            },
        };
        editing ? form.put(`/admin/campaigns/${editing.id}`, options) : form.post('/admin/campaigns', options);
    };
    const remove = (campaign: Campaign) => {
        if (campaign.id === currentCampaign?.id) return;
        const confirmation = window.prompt(
            `Esta acción elimina todos los datos compartimentados de la campaña.\n\nEscribe exactamente “${campaign.candidateName}” para continuar:`,
        );
        if (confirmation === null) return;
        router.delete(`/admin/campaigns/${campaign.id}`, {
            data: { confirmation },
            preserveScroll: true,
        });
    };

    return (
        <AppLayout title="Campañas y candidatos" eyebrow="Administración global">
            <Head title="Campañas y candidatos" />
            <div className="mb-6 flex flex-col justify-between gap-4 md:flex-row md:items-end">
                <div>
                    <p className="max-w-2xl text-sm leading-6 text-slate-500">
                        Cada campaña es un espacio de trabajo independiente. Su color funciona como señal permanente para reconocer de inmediato el candidato que estás gestionando.
                    </p>
                </div>
                <button onClick={startCreate} className="primary-button"><Plus size={17} /> Nueva campaña</button>
            </div>

            {(errors.campaign || errors.status) && (
                <div className="mb-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-700">
                    {errors.campaign ?? errors.status}
                </div>
            )}

            <section className="grid gap-5 lg:grid-cols-2 2xl:grid-cols-3">
                {campaignsList.map((campaign) => {
                    const current = campaign.id === currentCampaign?.id;
                    return (
                        <article
                            key={campaign.id}
                            className={`panel relative overflow-hidden transition ${current ? 'ring-2 ring-[var(--campaign-accent)] ring-offset-2' : ''}`}
                        >
                            <div className="h-2" style={{ backgroundColor: campaign.themeColor }} />
                            <div className="p-5">
                                <div className="flex items-start justify-between gap-4">
                                    <div className="flex min-w-0 items-center gap-3">
                                        <div className="grid size-12 shrink-0 place-items-center rounded-xl text-lg font-black shadow-sm" style={{ backgroundColor: campaign.themeColor, color: campaignContrast(campaign.themeColor) }}>
                                            {campaign.candidateName.charAt(0)}
                                        </div>
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <h2 className="truncate font-black text-slate-800">{campaign.candidateName}</h2>
                                                {current && <span className="rounded-full px-2 py-0.5 text-[9px] font-black uppercase" style={{ backgroundColor: campaign.themeColor, color: campaignContrast(campaign.themeColor) }}>En gestión</span>}
                                            </div>
                                            <p className="mt-0.5 truncate text-xs font-semibold text-slate-500">{campaign.office}</p>
                                        </div>
                                    </div>
                                    <div className="flex shrink-0 gap-1">
                                        <button onClick={() => startEdit(campaign)} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100" title="Editar campaña"><Pencil size={16} /></button>
                                        {!current && <button onClick={() => remove(campaign)} className="rounded-lg p-2 text-red-500 hover:bg-red-50" title="Eliminar campaña"><Trash2 size={16} /></button>}
                                    </div>
                                </div>

                                <div className="mt-5 space-y-2 text-xs text-slate-500">
                                    <div className="flex items-center gap-2"><MapPin size={14} style={{ color: campaign.themeColor }} /> {campaign.territory}</div>
                                    <div className="flex items-center gap-2"><CalendarDays size={14} style={{ color: campaign.themeColor }} /> {campaign.electionAt ? `Elección ${formatDate(campaign.electionAt)}` : 'Elección sin fecha'}</div>
                                    <div className="flex items-center gap-2"><Power size={14} style={{ color: campaign.themeColor }} /> {campaign.status === 'active' ? 'Campaña activa' : 'Campaña inactiva'}</div>
                                </div>

                                <div className="mt-5 grid grid-cols-3 gap-2">
                                    <Metric value={campaign.membershipsCount} label="Usuarios" icon={<UsersRound size={14} />} />
                                    <Metric value={campaign.personsCount} label="Personas" icon={<Flag size={14} />} />
                                    <Metric value={campaign.meetingsCount} label="Reuniones" icon={<CalendarDays size={14} />} />
                                </div>

                                <div className="mt-4 flex flex-wrap gap-1.5">
                                    {campaign.enabledModules.map((module) => (
                                        <span key={module} className="rounded-lg bg-slate-100 px-2 py-1 text-[10px] font-bold text-slate-500">
                                            {modules.find((option) => option.key === module)?.label ?? module}
                                        </span>
                                    ))}
                                </div>
                            </div>
                        </article>
                    );
                })}
            </section>

            {open && (
                <Modal title={editing ? `Editar · ${editing.candidateName}` : 'Crear nueva campaña'} onClose={() => setOpen(false)}>
                    <form onSubmit={submit} className="grid gap-5 p-6 md:grid-cols-2">
                        <div className="md:col-span-2">
                            <div className="flex items-center gap-4 rounded-2xl border border-slate-200 p-4" style={{ background: `linear-gradient(135deg, ${form.data.theme_color}18, white 65%)` }}>
                                <div className="grid size-14 shrink-0 place-items-center rounded-2xl text-xl font-black shadow-sm" style={{ backgroundColor: form.data.theme_color, color: campaignContrast(form.data.theme_color) }}>
                                    {form.data.candidate_name.charAt(0) || 'C'}
                                </div>
                                <div>
                                    <div className="text-xs font-black uppercase tracking-[.14em]" style={{ color: form.data.theme_color }}>Vista previa de identidad</div>
                                    <div className="mt-1 text-lg font-black text-slate-800">{form.data.candidate_name || 'Nombre del candidato'}</div>
                                    <div className="text-xs text-slate-500">{form.data.office || 'Corporación o cargo'}</div>
                                </div>
                            </div>
                        </div>

                        <Field label="Nombre interno de la campaña" error={form.errors.name}>
                            <input className="field" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} placeholder="Villavicencio 2027" required />
                        </Field>
                        <Field label="Identificador" error={form.errors.slug}>
                            <input className="field" value={form.data.slug} onChange={(event) => form.setData('slug', event.target.value)} placeholder="Se genera automáticamente" />
                        </Field>
                        <Field label="Candidato" error={form.errors.candidate_name}>
                            <input className="field" value={form.data.candidate_name} onChange={(event) => form.setData('candidate_name', event.target.value)} required />
                        </Field>
                        <Field label="Corporación o cargo" error={form.errors.office}>
                            <input className="field" value={form.data.office} onChange={(event) => form.setData('office', event.target.value)} placeholder="Concejo de Villavicencio" required />
                        </Field>
                        <Field label="Territorio" error={form.errors.territory}>
                            <input className="field" value={form.data.territory} onChange={(event) => form.setData('territory', event.target.value)} placeholder="Villavicencio, Meta" required />
                        </Field>
                        <Field label="Zona horaria" error={form.errors.timezone}>
                            <select className="field" value={form.data.timezone} onChange={(event) => form.setData('timezone', event.target.value)}>
                                {timezones.map((timezone) => <option key={timezone} value={timezone}>{timezone}</option>)}
                            </select>
                        </Field>
                        <Field label="Inicio de campaña" error={form.errors.starts_at}>
                            <input type="date" className="field" value={form.data.starts_at} onChange={(event) => form.setData('starts_at', event.target.value)} />
                        </Field>
                        <Field label="Fecha de elección" error={form.errors.election_at}>
                            <input type="date" className="field" value={form.data.election_at} onChange={(event) => form.setData('election_at', event.target.value)} />
                        </Field>

                        <Field label="Color de identificación" error={form.errors.theme_color}>
                            <div className="flex gap-2">
                                <input
                                    type="color"
                                    aria-label="Seleccionar color de campaña"
                                    className="h-11 w-14 cursor-pointer rounded-xl border border-slate-200 bg-white p-1"
                                    value={form.data.theme_color}
                                    onChange={(event) => form.setData('theme_color', event.target.value.toUpperCase())}
                                />
                                <input
                                    className="field font-mono uppercase"
                                    value={form.data.theme_color}
                                    onChange={(event) => form.setData('theme_color', event.target.value.toUpperCase())}
                                    pattern="^#[0-9A-Fa-f]{6}$"
                                    placeholder="#0D4D4B"
                                    required
                                />
                            </div>
                        </Field>
                        <Field label="Estado" error={form.errors.status}>
                            <select className="field" value={form.data.status} onChange={(event) => form.setData('status', event.target.value as 'active' | 'inactive')}>
                                <option value="active">Activa</option>
                                <option value="inactive">Inactiva</option>
                            </select>
                        </Field>

                        <div className="md:col-span-2">
                            <label className="label">Módulos habilitados</label>
                            <div className="grid gap-2 rounded-2xl border border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-3">
                                {modules.map((module) => {
                                    const selected = form.data.enabled_modules.includes(module.key);
                                    return (
                                        <button
                                            key={module.key}
                                            type="button"
                                            onClick={() => toggleModule(module.key)}
                                            className={`flex items-center justify-between rounded-xl border px-3 py-3 text-left text-xs font-bold transition ${selected ? '' : 'border-slate-200 bg-white text-slate-500'}`}
                                            style={selected ? { backgroundColor: form.data.theme_color, borderColor: form.data.theme_color, color: campaignContrast(form.data.theme_color) } : undefined}
                                        >
                                            {module.label}
                                            <span className={`grid size-5 place-items-center rounded-full ${selected ? 'bg-white/20' : 'bg-slate-100'}`}>{selected && <Check size={13} />}</span>
                                        </button>
                                    );
                                })}
                            </div>
                            {form.errors.enabled_modules && <p className="mt-1 text-xs font-semibold text-red-600">{form.errors.enabled_modules}</p>}
                        </div>

                        <div className="flex justify-end gap-2 border-t border-slate-100 pt-5 md:col-span-2">
                            <button type="button" onClick={() => setOpen(false)} className="secondary-button">Cancelar</button>
                            <button className="primary-button" disabled={form.processing}>{editing ? 'Guardar campaña' : 'Crear campaña'}</button>
                        </div>
                    </form>
                </Modal>
            )}
        </AppLayout>
    );
}

function Metric({ value, label, icon }: { value: number; label: string; icon: React.ReactNode }) {
    return <div className="rounded-xl bg-slate-50 p-3"><div className="flex items-center gap-1.5 text-lg font-black text-slate-800">{icon}{value}</div><div className="mt-0.5 text-[9px] font-bold uppercase tracking-wide text-slate-400">{label}</div></div>;
}
function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
    return <div><label className="label">{label}</label>{children}{error && <p className="mt-1 text-xs font-semibold text-red-600">{error}</p>}</div>;
}
function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: React.ReactNode }) {
    return <div className="fixed inset-0 z-50 flex items-end justify-center bg-[#102a33]/55 backdrop-blur-sm md:items-center md:p-6"><div className="max-h-[96vh] w-full max-w-5xl overflow-auto rounded-t-3xl bg-white shadow-2xl md:rounded-3xl"><div className="sticky top-0 z-10 flex items-center justify-between border-b border-slate-100 bg-white px-6 py-5"><h2 className="text-lg font-black">{title}</h2><button type="button" onClick={onClose} className="rounded-full bg-slate-100 p-2 text-slate-500"><X size={18} /></button></div>{children}</div></div>;
}
function formatDate(value: string) {
    return new Intl.DateTimeFormat('es-CO', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${value}T00:00:00Z`));
}
