import { Head, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, FileLock2, GitBranch, Landmark, MapPin, ShieldCheck, UserRound } from 'lucide-react';
import { FormEvent, useMemo } from 'react';
import { SharedProps } from '@/types';

type Place = {
    id: number;
    name: string;
    commune?: string;
    dd: string;
    mm: string;
    zz: string;
    pp: string;
    tables_count: number;
};

export default function Invitation({ token, campaign, inviter, places, consent }: {
    token: string;
    campaign: { name: string; candidateName: string; office: string; territory: string; themeColor?: string };
    inviter?: string;
    places: Place[];
    consent: { version: string; text: string };
}) {
    const { flash } = usePage<SharedProps>().props;
    const form = useForm({
        name: '',
        email: '',
        phone: '',
        document_number: '',
        voting_place_id: '',
        voting_table_number: '',
        identity_document: null as File | null,
        consent_version: consent.version,
        consent_accepted: false,
    });
    const selectedPlace = places.find((place) => place.id === Number(form.data.voting_place_id));
    const tables = useMemo(() => Array.from({ length: selectedPlace?.tables_count ?? 0 }, (_, index) => index + 1), [selectedPlace]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/public/v1/invitations/${token}/accept`, { forceFormData: true, preserveScroll: true });
    };

    if (flash.success) {
        return (
            <main className="grid min-h-screen place-items-center bg-[#f4f1e8] p-6">
                <Head title="Registro completado" />
                <section className="panel max-w-lg p-8 text-center md:p-12">
                    <div className="mx-auto grid size-16 place-items-center rounded-2xl bg-emerald-100 text-emerald-700"><CheckCircle2 size={34} /></div>
                    <h1 className="mt-6 text-2xl font-black tracking-tight">Registro completado</h1>
                    <p className="mt-3 text-sm leading-6 text-slate-500">{flash.success}</p>
                    <div className="mt-6 rounded-xl bg-[#d9f0e8]/70 p-4 text-left text-xs leading-5 text-[#0d4d4b]">
                        Tus datos quedaron asociados únicamente a <strong>{campaign.name}</strong> y serán tratados según la autorización aceptada.
                    </div>
                </section>
            </main>
        );
    }

    return (
        <main className="min-h-screen bg-[#f4f1e8]" style={{ '--campaign-accent': campaign.themeColor ?? '#0D4D4B' } as React.CSSProperties}>
            <Head title={`Vinculación · ${campaign.name}`} />
            <header className="bg-[#102f35] text-white">
                <div className="mx-auto flex max-w-5xl items-center justify-between px-5 py-5">
                    <div className="flex items-center gap-3">
                        <div className="grid size-10 place-items-center rounded-xl bg-[#d9f0e8] text-[#0d4d4b]"><GitBranch size={20} /></div>
                        <div><div className="font-black">Territorio</div><div className="text-[10px] uppercase tracking-[.17em] text-white/45">Vinculación segura</div></div>
                    </div>
                    <div className="hidden text-right sm:block"><div className="text-xs font-bold">{campaign.candidateName}</div><div className="text-[11px] text-white/45">{campaign.office} · {campaign.territory}</div></div>
                </div>
            </header>

            <div className="mx-auto grid max-w-5xl gap-6 px-5 py-8 lg:grid-cols-[320px_1fr] lg:py-12">
                <aside>
                    <div className="sticky top-6 space-y-4">
                        <div className="rounded-2xl bg-[#102f35] p-6 text-white">
                            <div className="text-[10px] font-black uppercase tracking-[.17em] text-[#a8d7c8]">Invitación territorial</div>
                            <h1 className="mt-3 text-2xl font-black leading-tight tracking-tight">Haz parte de una red organizada y verificable.</h1>
                            <p className="mt-4 text-sm leading-6 text-white/55">{inviter ? `${inviter} te compartió esta invitación.` : 'Esta invitación fue generada por el equipo territorial.'}</p>
                            <div className="mt-6 space-y-3 border-t border-white/10 pt-5 text-xs text-white/65">
                                <div className="flex gap-2"><ShieldCheck size={16} className="shrink-0 text-[#a8d7c8]" /> Autorización expresa y registrada</div>
                                <div className="flex gap-2"><FileLock2 size={16} className="shrink-0 text-[#a8d7c8]" /> Documento cifrado y no público</div>
                                <div className="flex gap-2"><MapPin size={16} className="shrink-0 text-[#a8d7c8]" /> Cobertura basada en DIVIPOL</div>
                            </div>
                        </div>
                        <p className="px-2 text-[11px] leading-5 text-slate-400">Completa únicamente tus propios datos. Si necesitas corregir un registro existente, comunícate con el equipo de campaña.</p>
                    </div>
                </aside>

                <section className="panel overflow-hidden">
                    <div className="border-b border-slate-100 px-6 py-5 md:px-8">
                        <div className="text-[10px] font-black uppercase tracking-[.16em] text-[#e8754f]">Registro personal</div>
                        <h2 className="mt-1 text-xl font-black">Información de vinculación</h2>
                        <p className="mt-1 text-xs text-slate-400">Los campos son obligatorios para verificar el registro dentro de esta campaña.</p>
                    </div>
                    <form onSubmit={submit} className="grid gap-5 p-6 md:grid-cols-2 md:p-8">
                        <div className="md:col-span-2"><label className="label">Nombre completo</label><div className="relative"><UserRound className="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400" size={17} /><input className="field pl-10" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /></div>{form.errors.name && <Error text={form.errors.name} />}</div>
                        <div><label className="label">Correo electrónico</label><input type="email" className="field" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} required />{form.errors.email && <Error text={form.errors.email} />}</div>
                        <div><label className="label">Teléfono / WhatsApp</label><input className="field" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} required />{form.errors.phone && <Error text={form.errors.phone} />}</div>
                        <div className="md:col-span-2"><label className="label">Número de cédula</label><input inputMode="numeric" className="field" value={form.data.document_number} onChange={(e) => form.setData('document_number', e.target.value)} required />{form.errors.document_number && <Error text={form.errors.document_number} />}</div>

                        <div className="border-t border-slate-100 pt-5 md:col-span-2">
                            <div className="mb-4 flex items-center gap-2 text-sm font-black"><Landmark size={17} className="text-[#0d4d4b]" /> Ubicación electoral</div>
                            <div className="grid gap-5 md:grid-cols-2">
                                <div><label className="label">Puesto de votación</label><select className="field" value={form.data.voting_place_id} onChange={(e) => { form.setData('voting_place_id', e.target.value); form.setData('voting_table_number', ''); }} required><option value="">Selecciona un puesto</option>{places.map((place) => <option key={place.id} value={place.id}>{place.commune ? `${place.commune} · ` : ''}{place.name}</option>)}</select>{form.errors.voting_place_id && <Error text={form.errors.voting_place_id} />}</div>
                                <div><label className="label">Mesa</label><select className="field" value={form.data.voting_table_number} onChange={(e) => form.setData('voting_table_number', e.target.value)} disabled={!selectedPlace} required><option value="">Selecciona la mesa</option>{tables.map((table) => <option key={table} value={table}>Mesa {table}</option>)}</select>{form.errors.voting_table_number && <Error text={form.errors.voting_table_number} />}</div>
                            </div>
                        </div>

                        <div className="md:col-span-2">
                            <label className="label">Documento de identidad en PDF <span className="text-red-500">*</span></label>
                            <label className="flex cursor-pointer items-center gap-4 rounded-xl border-2 border-dashed border-slate-300 bg-slate-50 p-4 transition hover:border-[var(--campaign-accent)] hover:bg-[#d9f0e8]/30">
                                <div className="grid size-10 place-items-center rounded-xl bg-white text-[#0d4d4b] shadow-sm"><FileLock2 size={19} /></div>
                                <div className="min-w-0 flex-1"><div className="truncate text-sm font-bold text-slate-700">{form.data.identity_document?.name ?? 'Seleccionar cédula en PDF'}</div><div className="mt-0.5 text-[11px] text-slate-400">Obligatorio · un solo PDF · máximo 5 MB · almacenamiento cifrado</div></div>
                                <input type="file" accept=".pdf,application/pdf" className="hidden" onChange={(e) => {
                                    const file = e.target.files?.[0] ?? null;
                                    if (file && file.type !== 'application/pdf') {
                                        form.setError('identity_document', 'Debes seleccionar un archivo PDF.');
                                        form.setData('identity_document', null);
                                        e.target.value = '';
                                        return;
                                    }
                                    form.clearErrors('identity_document');
                                    form.setData('identity_document', file);
                                }} required />
                            </label>
                            {form.errors.identity_document && <Error text={form.errors.identity_document} />}
                        </div>

                        <div className="rounded-xl border border-[#0d4d4b]/10 bg-[#d9f0e8]/45 p-4 md:col-span-2">
                            <label className="flex cursor-pointer items-start gap-3">
                                <input type="checkbox" className="mt-1 size-4 shrink-0 accent-[#0d4d4b]" checked={form.data.consent_accepted} onChange={(e) => form.setData('consent_accepted', e.target.checked)} required />
                                <span className="text-xs leading-5 text-slate-600">{consent.text}</span>
                            </label>
                            {form.errors.consent_accepted && <Error text={form.errors.consent_accepted} />}
                        </div>

                        <div className="flex items-center justify-between gap-4 border-t border-slate-100 pt-5 md:col-span-2">
                            <div className="hidden items-center gap-2 text-[11px] text-slate-400 sm:flex"><ShieldCheck size={15} /> Envío protegido</div>
                            <button className="primary-button ml-auto min-w-44" disabled={form.processing}>{form.processing ? 'Protegiendo y guardando…' : 'Completar registro'}</button>
                        </div>
                    </form>
                </section>
            </div>
        </main>
    );
}

function Error({ text }: { text: string }) {
    return <p className="mt-1.5 text-xs font-semibold text-red-600">{text}</p>;
}
