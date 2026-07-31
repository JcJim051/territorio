import { Head, useForm } from '@inertiajs/react';
import { ArrowRight, Eye, GitBranch, LockKeyhole, Mail, ShieldCheck } from 'lucide-react';
import { FormEvent, useState } from 'react';

export default function Login() {
    const [showPassword, setShowPassword] = useState(false);
    const form = useForm({ email: '', password: '', remember: true });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/login');
    };

    return (
        <>
            <Head title="Ingresar" />
            <main className="grid min-h-screen bg-[#f5f6f2] lg:grid-cols-[1.05fr_.95fr]">
                <section className="relative hidden overflow-hidden bg-[#102f35] p-12 text-white lg:flex lg:flex-col">
                    <div className="absolute -right-32 -top-24 size-[430px] rounded-full border border-white/10" />
                    <div className="absolute -right-12 top-10 size-[280px] rounded-full border border-[#d9f0e8]/20" />
                    <div className="absolute bottom-[-180px] left-[-140px] size-[480px] rounded-full bg-[#0d4d4b]" />
                    <div className="relative flex items-center gap-3">
                        <div className="grid size-11 place-items-center rounded-xl bg-[#d9f0e8] text-[#0d4d4b]">
                            <GitBranch size={23} strokeWidth={2.6} />
                        </div>
                        <div>
                            <div className="text-xl font-black tracking-tight">Territorio</div>
                            <div className="text-[10px] font-bold uppercase tracking-[.2em] text-white/45">Inteligencia de campaña</div>
                        </div>
                    </div>
                    <div className="relative my-auto max-w-xl">
                        <div className="mb-6 inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-bold text-[#d9f0e8]">
                            <ShieldCheck size={14} /> Información para decidir, operación para avanzar
                        </div>
                        <h1 className="text-5xl font-black leading-[1.05] tracking-[-0.04em]">
                            Cada relación cuenta.<br />
                            <span className="text-[#a8d7c8]">Cada decisión también.</span>
                        </h1>
                        <p className="mt-6 max-w-lg text-lg leading-8 text-white/55">
                            Gestión territorial, agenda y logística conectadas en un solo centro de mando.
                        </p>
                    </div>
                    <div className="relative text-xs text-white/35">Plataforma privada · Acceso auditado</div>
                </section>

                <section className="flex items-center justify-center p-6 md:p-12">
                    <div className="w-full max-w-md">
                        <div className="mb-10 flex items-center gap-3 lg:hidden">
                            <div className="grid size-10 place-items-center rounded-xl bg-[#0d4d4b] text-white"><GitBranch size={20} /></div>
                            <span className="text-lg font-black">Territorio</span>
                        </div>
                        <div className="mb-8">
                            <div className="text-xs font-black uppercase tracking-[.18em] text-[#e8754f]">Bienvenido</div>
                            <h2 className="mt-2 text-3xl font-black tracking-tight text-[#102a33]">Ingresa al centro de mando</h2>
                            <p className="mt-2 text-sm leading-6 text-slate-500">Usa las credenciales asignadas por la administración de tu campaña.</p>
                        </div>

                        <form onSubmit={submit} className="space-y-5">
                            <div>
                                <label className="label" htmlFor="email">Correo electrónico</label>
                                <div className="relative">
                                    <Mail className="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400" size={17} />
                                    <input id="email" type="email" autoFocus autoComplete="email" className="field pl-10" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                                </div>
                                {form.errors.email && <p className="mt-1.5 text-xs font-semibold text-red-600">{form.errors.email}</p>}
                            </div>
                            <div>
                                <label className="label" htmlFor="password">Contraseña</label>
                                <div className="relative">
                                    <LockKeyhole className="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400" size={17} />
                                    <input id="password" type={showPassword ? 'text' : 'password'} autoComplete="current-password" className="field px-10" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                                    <button type="button" onClick={() => setShowPassword(!showPassword)} className="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400" aria-label="Mostrar contraseña"><Eye size={17} /></button>
                                </div>
                            </div>
                            <label className="flex items-center gap-2 text-sm text-slate-600">
                                <input type="checkbox" checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} className="size-4 rounded border-slate-300 accent-[#0d4d4b]" />
                                Mantener la sesión en este dispositivo
                            </label>
                            <button className="primary-button w-full py-3" disabled={form.processing}>
                                {form.processing ? 'Verificando…' : 'Ingresar'} <ArrowRight size={17} />
                            </button>
                        </form>
                        <p className="mt-8 text-center text-xs leading-5 text-slate-400">
                            El acceso y las acciones realizadas en la plataforma quedan registrados por seguridad.
                        </p>
                    </div>
                </section>
            </main>
        </>
    );
}
