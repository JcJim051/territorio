import { Head, useForm } from '@inertiajs/react';
import { Car, Save } from 'lucide-react';
import { FormEvent } from 'react';
import AppLayout from '@/Layouts/AppLayout';

export default function OperationalSettings({ driverAgendaDays }: { driverAgendaDays: number }) {
    const form = useForm({ driver_agenda_days: driverAgendaDays });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put('/campaign/settings/operations', { preserveScroll: true });
    };

    return <AppLayout title="Configuración operativa" eyebrow="Campaña activa">
        <Head title="Configuración operativa" />
        <div className="mx-auto max-w-3xl">
            <section className="panel overflow-hidden">
                <div className="flex items-start gap-4 border-b border-slate-100 p-6">
                    <div className="grid size-11 place-items-center rounded-xl bg-[var(--campaign-accent-soft)] text-[var(--campaign-accent)]"><Car size={21} /></div>
                    <div><h2 className="font-black text-slate-800">Agenda del conductor</h2><p className="mt-1 text-sm text-slate-500">Esta configuración solo se aplica a la campaña identificada en esta vista.</p></div>
                </div>
                <form onSubmit={submit} className="space-y-5 p-6">
                    <div>
                        <label className="label">Horizonte visible</label>
                        <div className="mt-2 flex items-center gap-3">
                            <input type="number" min={1} max={30} className="field max-w-32" value={form.data.driver_agenda_days} onChange={(event) => form.setData('driver_agenda_days', Number(event.target.value))} />
                            <span className="text-sm font-semibold text-slate-500">días, desde hoy</span>
                        </div>
                        <p className="mt-2 text-xs text-slate-400">El conductor siempre verá únicamente reuniones aprobadas.</p>
                        {form.errors.driver_agenda_days && <p className="mt-2 text-xs font-bold text-red-600">{form.errors.driver_agenda_days}</p>}
                    </div>
                    <button className="primary-button" disabled={form.processing}><Save size={16} /> Guardar configuración</button>
                </form>
            </section>
        </div>
    </AppLayout>;
}
