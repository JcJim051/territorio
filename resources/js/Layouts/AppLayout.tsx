import { Link, router, usePage } from '@inertiajs/react';
import {
    Boxes,
    CalendarDays,
    ChevronDown,
    CircleGauge,
    GitBranch,
    LogOut,
    Menu,
    Search,
    Settings2,
    ShieldCheck,
    Shield,
    Flag,
    Cloud,
    UsersRound,
    Car,
    ClipboardList,
    SlidersHorizontal,
    Share2,
    X,
} from 'lucide-react';
import { PropsWithChildren, useState } from 'react';
import { SharedProps } from '@/types';
import { campaignContrast } from '@/lib/campaignColor';

const navigation = [
    { label: 'Centro de mando', href: '/', icon: CircleGauge, permission: 'dashboard.view' },
    { label: 'Red territorial', href: '/territorial/network', icon: GitBranch, permission: 'territorial.view' },
    { label: 'Personas', href: '/people', icon: UsersRound, permission: 'territorial.view' },
    { label: 'Nodos y enlaces', href: '/territorial/nodes', icon: Share2, permission: 'territorial.tokens.manage' },
    { label: 'Agenda', href: '/meetings', icon: CalendarDays, permission: 'meetings.view' },
    { label: 'Inventario', href: '/inventory', icon: Boxes, permission: 'inventory.view' },
    { label: 'Traslados', href: '/driver/routes', icon: Car, permission: 'driver.routes.view' },
];

const administration = [
    { label: 'Campañas', href: '/admin/campaigns', icon: Flag, permission: 'campaigns.manage', superAdminOnly: true },
    { label: 'Usuarios', href: '/admin/users', icon: UsersRound, permission: 'users.view' },
    { label: 'Roles y permisos', href: '/admin/roles', icon: Shield, permission: 'roles.view' },
    { label: 'Google Calendar', href: '/calendar/settings', icon: Cloud, permission: 'calendar.sync.view' },
    { label: 'Cambios de calendario', href: '/calendar/reviews', icon: CalendarDays, permission: 'calendar.changes.review' },
    { label: 'Configuración operativa', href: '/campaign/settings/operations', icon: SlidersHorizontal, permission: 'campaign.settings.manage' },
    { label: 'Auditoría', href: '/admin/audit', icon: ClipboardList, permission: 'audit.view' },
];

export default function AppLayout({ children, title, eyebrow }: PropsWithChildren<{ title: string; eyebrow?: string }>) {
    const { auth, currentCampaign, campaigns, flash } = usePage<SharedProps>().props;
    const [mobileOpen, setMobileOpen] = useState(false);
    const path = window.location.pathname;
    const can = (permission: string) => currentCampaign?.permissions.includes('*') || currentCampaign?.permissions.includes(permission);
    const campaignColor = currentCampaign?.themeColor ?? '#0D4D4B';
    const campaignTextColor = campaignContrast(campaignColor);
    const themeStyle = {
        '--campaign-accent': campaignColor,
        '--campaign-accent-soft': `color-mix(in srgb, ${campaignColor} 14%, white)`,
        '--campaign-accent-dark': `color-mix(in srgb, ${campaignColor} 72%, #102a33)`,
        '--campaign-contrast': campaignTextColor,
    } as React.CSSProperties;

    const switchCampaign = (campaignId: number) => {
        router.post('/campaign/switch', { campaign_id: campaignId }, {
            preserveState: false,
            preserveScroll: false,
        });
    };

    const Sidebar = () => (
        <aside className="flex h-full w-[272px] flex-col text-white" style={{ background: `linear-gradient(180deg, color-mix(in srgb, ${campaignColor} 30%, #102f35), #102f35 48%)` }}>
            <div className="flex h-20 items-center gap-3 border-b border-white/10 px-6">
                <div className="grid size-10 place-items-center rounded-xl bg-white/90" style={{ color: campaignColor }}>
                    <GitBranch size={21} strokeWidth={2.6} />
                </div>
                <div>
                    <div className="text-[17px] font-black tracking-tight">Territorio</div>
                    <div className="text-[10px] font-bold uppercase tracking-[0.19em] text-teal-100/60">Inteligencia de campaña</div>
                </div>
            </div>

            <div className="px-4 py-5">
                <div className="relative flex w-full items-center gap-3 rounded-xl border border-white/10 bg-white/5 p-3 text-left transition hover:bg-white/10">
                    <div className="grid size-9 shrink-0 place-items-center rounded-lg text-sm font-black shadow-sm" style={{ backgroundColor: campaignColor, color: campaignTextColor }}>
                        {currentCampaign?.candidateName?.charAt(0) ?? 'C'}
                    </div>
                    <div className="min-w-0 flex-1">
                        {currentCampaign?.isSuperAdmin && campaigns.length > 1 ? (
                            <select
                                aria-label="Cambiar candidato o elección"
                                value={currentCampaign.id}
                                onChange={(event) => switchCampaign(Number(event.target.value))}
                                className="w-full appearance-none truncate bg-transparent pr-4 text-xs font-bold text-white outline-none"
                            >
                                {campaigns.map((campaign) => <option key={campaign.id} value={campaign.id} className="text-slate-900">{campaign.candidateName} · {campaign.office}</option>)}
                            </select>
                        ) : <div className="truncate text-xs font-bold text-white">{currentCampaign?.candidateName}</div>}
                        <div className="truncate text-[11px] text-teal-100/55">{currentCampaign?.office}</div>
                    </div>
                    {currentCampaign?.isSuperAdmin && campaigns.length > 1 && <ChevronDown size={15} className="pointer-events-none absolute right-3 text-white/45" />}
                </div>
                {currentCampaign?.isSuperAdmin && <div className="mt-2 px-1 text-[9px] font-bold uppercase tracking-[.13em] text-teal-100/35">Vista global · cambia de campaña aquí</div>}
            </div>

            <nav className="flex-1 space-y-1 px-3">
                <div className="mb-2 px-3 text-[10px] font-bold uppercase tracking-[0.18em] text-teal-100/40">Operación</div>
                {navigation.filter((item) => can(item.permission)).map((item) => {
                    const active = item.href === '/' ? path === '/' : path.startsWith(item.href);
                    const Icon = item.icon;
                    return (
                        <Link
                            key={item.label}
                            href={item.href}
                            onClick={() => setMobileOpen(false)}
                            className={`flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition ${
                                active ? 'bg-white font-black' : 'text-white/65 hover:bg-white/5 hover:text-white'
                            }`}
                            style={active ? { color: campaignColor } : undefined}
                        >
                            <Icon size={18} />
                            {item.label}
                        </Link>
                    );
                })}
                {administration.some((item) => can(item.permission) && (!item.superAdminOnly || currentCampaign?.isSuperAdmin)) && <div className="mb-2 mt-6 px-3 text-[10px] font-bold uppercase tracking-[0.18em] text-teal-100/40">Administración</div>}
                {administration.filter((item) => can(item.permission) && (!item.superAdminOnly || currentCampaign?.isSuperAdmin)).map((item) => {
                    const active = path.startsWith(item.href);
                    const Icon = item.icon;
                    return <Link key={item.label} href={item.href} onClick={() => setMobileOpen(false)} style={active ? { color: campaignColor } : undefined} className={`flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition ${active ? 'bg-white font-black' : 'text-white/65 hover:bg-white/5 hover:text-white'}`}><Icon size={18} />{item.label}</Link>;
                })}
            </nav>

            <div className="border-t border-white/10 p-4">
                <div className="flex items-center gap-3">
                    <div className="grid size-9 place-items-center rounded-full bg-white/10 text-xs font-bold">
                        {auth.user?.name?.split(' ').map((part) => part[0]).slice(0, 2).join('')}
                    </div>
                    <div className="min-w-0 flex-1">
                        <div className="truncate text-xs font-bold">{auth.user?.name}</div>
                        <div className="truncate text-[11px] text-white/45">{currentCampaign?.role}</div>
                    </div>
                    <button onClick={() => router.post('/logout')} aria-label="Cerrar sesión" className="rounded-lg p-2 text-white/45 hover:bg-white/10 hover:text-white">
                        <LogOut size={17} />
                    </button>
                </div>
            </div>
        </aside>
    );

    return (
        <div className="min-h-screen bg-[#f5f6f2]" style={themeStyle}>
            <div className="fixed inset-y-0 left-0 z-30 hidden lg:block"><Sidebar /></div>
            {mobileOpen && (
                <div className="fixed inset-0 z-50 lg:hidden">
                    <button className="absolute inset-0 bg-black/45" onClick={() => setMobileOpen(false)} aria-label="Cerrar menú" />
                    <div className="relative h-full w-[272px]"><Sidebar /></div>
                    <button className="absolute left-[284px] top-4 rounded-full bg-white p-2 text-slate-700" onClick={() => setMobileOpen(false)}><X size={19} /></button>
                </div>
            )}

            <main className="lg:pl-[272px]">
                <header className="sticky top-0 z-20 flex h-20 items-center gap-4 border-b border-black/5 bg-[#f5f6f2]/90 px-5 backdrop-blur-xl md:px-8">
                    <button onClick={() => setMobileOpen(true)} className="rounded-lg p-2 lg:hidden"><Menu size={21} /></button>
                    <div className="min-w-0 flex-1">
                        <div className="text-[10px] font-bold uppercase tracking-[0.16em]" style={{ color: campaignColor }}>{eyebrow ?? currentCampaign?.territory}</div>
                        <h1 className="truncate text-xl font-black tracking-tight text-[#102a33] md:text-2xl">{title}</h1>
                    </div>
                    <div className="hidden items-center gap-2 rounded-xl border border-black/5 bg-white px-3 py-2 text-sm text-slate-400 shadow-sm md:flex">
                        <Search size={16} />
                        <span className="pr-16">Buscar en la campaña</span>
                        <kbd className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px]">⌘ K</kbd>
                    </div>
                    {can('users.view') && <Link href="/admin/users" className="rounded-xl border border-black/5 bg-white p-2.5 text-slate-500 shadow-sm" aria-label="Administración"><Settings2 size={18} /></Link>}
                </header>
                <div className="sticky top-20 z-10 h-1 shadow-sm" style={{ backgroundColor: campaignColor }} />

                {(flash.success || flash.error) && (
                    <div className="mx-5 mt-5 md:mx-8">
                        <div className={`flex items-center gap-2 rounded-xl border px-4 py-3 text-sm font-semibold ${
                            flash.success ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-800'
                        }`}>
                            <ShieldCheck size={17} /> {flash.success ?? flash.error}
                        </div>
                    </div>
                )}
                <div className="p-5 md:p-8">{children}</div>
            </main>
        </div>
    );
}
