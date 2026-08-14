import { Head } from '@inertiajs/react';
import { ArrowRight, Mail, MapPin, Phone, Share2, Sparkles } from 'lucide-react';
import { campaignContrast } from '@/lib/campaignColor';

type Pair = { title?: string; description?: string };
type GalleryItem = { type?: 'image' | 'video'; url?: string; alt?: string };
type SocialAccount = { provider?: 'instagram' | 'facebook'; handle?: string; profile_url?: string; status?: string };
type SocialPost = { provider?: 'instagram' | 'facebook'; url?: string; title?: string; summary?: string; media_url?: string; published_on?: string };
type Content = {
    hero?: { title?: string; subtitle?: string; media_url?: string; cta_label?: string; cta_url?: string };
    biography?: string;
    trajectory?: Pair[];
    proposals?: Pair[];
    gallery?: GalleryItem[];
    social_accounts?: SocialAccount[];
    social_posts?: SocialPost[];
    contact?: { email?: string; phone?: string; whatsapp_url?: string };
    legal_footer?: string;
};

export default function PublicSiteShow({ campaign, content }: {
    campaign: { candidateName: string; office: string; territory: string; themeColor: string };
    content: Content;
}) {
    const accent = campaign.themeColor ?? '#0D4D4B';
    const contrast = campaignContrast(accent);
    const hero = content.hero ?? {};
    const socials = (content.social_accounts ?? []).filter((item) => item.profile_url);
    const posts = (content.social_posts ?? []).filter((item) => item.url || item.title);
    const gallery = (content.gallery ?? []).filter((item) => item.url);

    return <main className="min-h-screen bg-[#f5f6f2] text-[#102a33]" style={{ '--campaign-accent': accent, '--campaign-contrast': contrast } as React.CSSProperties}>
        <Head title={`${hero.title || campaign.candidateName} · ${campaign.office}`} />
        <header className="fixed inset-x-0 top-0 z-20 border-b border-white/10 bg-[#102f35]/88 px-5 py-4 text-white backdrop-blur-xl">
            <div className="mx-auto flex max-w-6xl items-center justify-between gap-4">
                <div className="flex items-center gap-3"><div className="grid size-10 place-items-center rounded-xl font-black" style={{ backgroundColor: accent, color: contrast }}>{campaign.candidateName.charAt(0)}</div><div><div className="text-sm font-black">{campaign.candidateName}</div><div className="text-[10px] font-bold uppercase tracking-[.18em] text-white/45">{campaign.office}</div></div></div>
                <nav className="hidden items-center gap-5 text-xs font-bold text-white/65 md:flex"><a href="#biografia">Hoja de vida</a><a href="#propuestas">Propuestas</a><a href="#redes">Redes</a></nav>
            </div>
        </header>

        <section className="relative overflow-hidden bg-[#102f35] pt-28 text-white">
            {hero.media_url && <img src={hero.media_url} alt={hero.title || campaign.candidateName} className="absolute inset-0 h-full w-full object-cover opacity-75" />}
            <div className="absolute inset-0 bg-gradient-to-r from-[#102f35]/88 via-[#102f35]/45 to-[#102f35]/10" />
            <div className="absolute inset-x-0 bottom-0 h-40 bg-gradient-to-t from-[#102f35]/85 to-transparent" />
            <div className="relative mx-auto grid min-h-[680px] max-w-6xl content-center gap-10 px-5 py-20 lg:grid-cols-[1fr_420px] lg:items-center">
                <div>
                    <div className="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1.5 text-[10px] font-black uppercase tracking-[.18em] text-[#a8d7c8]"><Sparkles size={14} /> {campaign.territory}</div>
                    <h1 className="mt-6 max-w-3xl text-5xl font-black leading-[.95] tracking-tight md:text-7xl">{hero.title || campaign.candidateName}</h1>
                    {hero.subtitle && <p className="mt-6 max-w-2xl text-lg leading-8 text-white/68">{hero.subtitle}</p>}
                    {hero.cta_url && <a href={hero.cta_url} className="mt-8 inline-flex items-center gap-2 rounded-xl px-5 py-3 text-sm font-black shadow-xl" style={{ backgroundColor: accent, color: contrast }}>{hero.cta_label || 'Conoce más'} <ArrowRight size={17} /></a>}
                </div>
                <div className="rounded-3xl border border-white/10 bg-white/10 p-6 backdrop-blur-lg">
                    <div className="text-[10px] font-black uppercase tracking-[.18em] text-white/45">Candidato a</div>
                    <div className="mt-2 text-3xl font-black">{campaign.office}</div>
                    <div className="mt-4 flex items-center gap-2 text-sm text-white/60"><MapPin size={16} /> {campaign.territory}</div>
                    <div className="mt-6 flex flex-wrap gap-2">{socials.map((social) => <a key={social.provider} href={social.profile_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-2 rounded-xl bg-white/10 px-3 py-2 text-xs font-black text-white"><Share2 size={15} /> {social.handle || social.provider}</a>)}</div>
                </div>
            </div>
        </section>

        <section id="biografia" className="mx-auto grid max-w-6xl gap-8 px-5 py-20 lg:grid-cols-[360px_1fr]">
            <div><div className="text-[10px] font-black uppercase tracking-[.18em]" style={{ color: accent }}>Hoja de vida</div><h2 className="mt-3 text-4xl font-black">Trayectoria y servicio</h2></div>
            <div className="text-lg leading-9 text-slate-600 whitespace-pre-line">{content.biography || 'La hoja de vida del candidato estará disponible próximamente.'}</div>
        </section>

        <Cards title="Trayectoria" items={content.trajectory ?? []} accent={accent} />
        <Cards id="propuestas" title="Propuestas" items={content.proposals ?? []} accent={accent} dark />

        {gallery.length > 0 && <section className="mx-auto max-w-6xl px-5 py-20"><div className="mb-8 flex items-end justify-between gap-4"><div><div className="text-[10px] font-black uppercase tracking-[.18em]" style={{ color: accent }}>Galería</div><h2 className="mt-3 text-4xl font-black">En territorio</h2></div></div><div className="grid gap-4 md:grid-cols-3">{gallery.map((item, index) => <figure key={index} className="overflow-hidden rounded-2xl bg-slate-200 shadow-sm">{item.type === 'video' ? <video src={item.url} controls className="aspect-[4/3] w-full object-cover" /> : <img src={item.url} alt={item.alt ?? ''} className="aspect-[4/3] w-full object-cover" />}{item.alt && <figcaption className="bg-white p-3 text-xs font-bold text-slate-500">{item.alt}</figcaption>}</figure>)}</div></section>}

        <section id="redes" className="bg-white px-5 py-20">
            <div className="mx-auto max-w-6xl">
                <div className="mb-8 flex flex-col justify-between gap-4 md:flex-row md:items-end"><div><div className="text-[10px] font-black uppercase tracking-[.18em]" style={{ color: accent }}>Redes</div><h2 className="mt-3 text-4xl font-black">Últimas publicaciones destacadas</h2></div><div className="flex flex-wrap gap-2">{socials.map((social) => <a key={social.provider} href={social.profile_url} target="_blank" rel="noreferrer" className="rounded-xl border border-slate-200 px-4 py-2 text-xs font-black text-slate-600">{social.handle || social.provider}</a>)}</div></div>
                <div className="grid gap-4 md:grid-cols-3">{posts.map((post, index) => <a key={index} href={post.url} target="_blank" rel="noreferrer" className="group overflow-hidden rounded-2xl border border-slate-100 bg-[#f8f9f6] transition hover:-translate-y-1 hover:shadow-xl">{post.media_url && <img src={post.media_url} alt={post.title ?? ''} className="aspect-video w-full object-cover" />}<div className="p-5"><div className="text-[10px] font-black uppercase tracking-wide" style={{ color: accent }}>{post.provider}</div><h3 className="mt-2 text-lg font-black text-slate-800">{post.title || 'Publicación destacada'}</h3>{post.summary && <p className="mt-2 text-sm leading-6 text-slate-500">{post.summary}</p>}<div className="mt-4 inline-flex items-center gap-1 text-xs font-black" style={{ color: accent }}>Abrir publicación <ArrowRight size={14} /></div></div></a>)}</div>
                {posts.length === 0 && <div className="rounded-2xl border border-dashed border-slate-200 p-10 text-center text-sm font-bold text-slate-400">Próximamente encontrarás aquí publicaciones destacadas.</div>}
            </div>
        </section>

        <footer className="bg-[#102f35] px-5 py-10 text-white">
            <div className="mx-auto flex max-w-6xl flex-col justify-between gap-5 md:flex-row md:items-center">
                <div><div className="font-black">{campaign.candidateName}</div><div className="mt-1 text-xs text-white/45">{content.legal_footer || `${campaign.office} · ${campaign.territory}`}</div></div>
                <div className="flex flex-wrap gap-3 text-xs font-bold text-white/60">{content.contact?.email && <a className="flex items-center gap-1" href={`mailto:${content.contact.email}`}><Mail size={14} /> {content.contact.email}</a>}{content.contact?.phone && <span className="flex items-center gap-1"><Phone size={14} /> {content.contact.phone}</span>}</div>
            </div>
        </footer>
    </main>;
}

function Cards({ title, items, accent, dark = false, id }: { title: string; items: Pair[]; accent: string; dark?: boolean; id?: string }) {
    const visible = items.filter((item) => item.title || item.description);
    if (visible.length === 0) return null;

    return <section id={id} className={`${dark ? 'bg-[#102f35] text-white' : 'bg-white text-[#102a33]'} px-5 py-20`}>
        <div className="mx-auto max-w-6xl">
            <div className="mb-8 text-[10px] font-black uppercase tracking-[.18em]" style={{ color: dark ? '#a8d7c8' : accent }}>{title}</div>
            <div className="grid gap-4 md:grid-cols-3">{visible.map((item, index) => <article key={index} className={`rounded-2xl p-6 ${dark ? 'bg-white/10' : 'bg-[#f8f9f6]'}`}><h3 className="text-xl font-black">{item.title}</h3>{item.description && <p className={`mt-3 text-sm leading-7 ${dark ? 'text-white/60' : 'text-slate-500'}`}>{item.description}</p>}</article>)}</div>
        </div>
    </section>;
}
