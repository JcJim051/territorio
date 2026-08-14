import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ExternalLink, Globe2, Plus, Save, Send, Trash2, Upload } from 'lucide-react';
import { FormEvent, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { SharedProps } from '@/types';

type Pair = { title: string; description: string };
type GalleryItem = { type: 'image' | 'video'; url: string; alt: string };
type SocialAccount = { provider: 'instagram' | 'facebook'; handle: string; profile_url: string; status: 'not_configured' | 'connected' | 'error' | 'review_required' };
type SocialPost = { provider: 'instagram' | 'facebook'; url: string; title: string; summary: string; media_url: string; published_on: string };
type PublicSiteContent = {
    hero: { title: string; subtitle: string; media_url: string; cta_label: string; cta_url: string };
    biography: string;
    trajectory: Pair[];
    proposals: Pair[];
    gallery: GalleryItem[];
    social_accounts: SocialAccount[];
    social_posts: SocialPost[];
    contact: { email: string; phone: string; whatsapp_url: string };
    legal_footer: string;
};

export default function PublicSiteEditor({ site, previewUrl, publicUrl }: {
    site: { slug: string; customDomain?: string; status: 'draft' | 'published' | 'disabled'; draftContent: PublicSiteContent; publishedAt?: string };
    previewUrl: string;
    publicUrl: string;
}) {
    const { currentCampaign, errors } = usePage<SharedProps>().props;
    const form = useForm({
        slug: site.slug,
        custom_domain: site.customDomain ?? '',
        status: site.status === 'disabled' ? 'disabled' : 'draft',
        ...normalize(site.draftContent),
    });
    const [mediaFile, setMediaFile] = useState<File | null>(null);
    const [mediaAlt, setMediaAlt] = useState('');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put('/campaign/public-site', { preserveScroll: true });
    };
    const uploadMedia = () => {
        if (!mediaFile) return;

        const payload = new FormData();
        payload.append('media', mediaFile);
        payload.append('alt', mediaAlt);

        router.post('/campaign/public-site/media', payload, {
            forceFormData: true,
            preserveScroll: true,
            preserveState: false,
            onSuccess: () => {
                setMediaFile(null);
                setMediaAlt('');
            },
        });
    };
    const addPair = (key: 'trajectory' | 'proposals') => form.setData(key, [...form.data[key], { title: '', description: '' }]);
    const removePair = (key: 'trajectory' | 'proposals', index: number) => form.setData(key, form.data[key].filter((_, itemIndex) => itemIndex !== index));

    return <AppLayout title="Página pública" eyebrow={currentCampaign?.candidateName ?? 'Campaña'}>
        <Head title="Página pública" />
        <div className="mb-5 grid gap-4 lg:grid-cols-[1fr_320px]">
            <section className="overflow-hidden rounded-2xl bg-[#102f35] text-white">
                <div className="grid gap-5 p-6 md:grid-cols-[1fr_auto] md:items-center">
                    <div>
                        <div className="flex items-center gap-2 text-[10px] font-black uppercase tracking-[.18em] text-[#a8d7c8]"><Globe2 size={15} /> Sitio público del candidato</div>
                        <h2 className="mt-2 text-2xl font-black">{form.data.hero.title || currentCampaign?.candidateName}</h2>
                        <p className="mt-2 max-w-2xl text-sm leading-6 text-white/60">Edita el borrador sin afectar la web publicada. Cuando esté listo, publica los cambios.</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Link href={previewUrl} target="_blank" className="secondary-button border-white/20 bg-white/10 text-white hover:bg-white/15"><ExternalLink size={15} /> Vista previa</Link>
                        <button onClick={() => router.post('/campaign/public-site/publish', {}, { preserveScroll: true })} className="primary-button bg-[#e8754f]"><Send size={15} /> Publicar</button>
                    </div>
                </div>
            </section>
            <aside className="panel p-5">
                <div className="text-xs font-black uppercase tracking-wide text-slate-400">Estado</div>
                <div className="mt-2 text-lg font-black text-slate-800">{site.status === 'published' ? 'Publicado' : site.status === 'disabled' ? 'Desactivado' : 'Borrador'}</div>
                {site.publishedAt && <p className="mt-1 text-xs text-slate-400">Última publicación: {new Date(site.publishedAt).toLocaleString('es-CO')}</p>}
                {site.status === 'published' && <button onClick={() => router.post('/campaign/public-site/disable', {}, { preserveScroll: true })} className="secondary-button mt-4 w-full justify-center border-red-200 text-red-700">Desactivar página</button>}
            </aside>
        </div>

        <form onSubmit={submit} className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
            <div className="space-y-5">
                <Section title="Publicación">
                    <div className="grid gap-4 md:grid-cols-3">
                        <Field label="Slug público" error={form.errors.slug}><input className="field" value={form.data.slug} onChange={(event) => form.setData('slug', slugify(event.target.value))} /></Field>
                        <Field label="Dominio propio" error={form.errors.custom_domain}><input className="field" placeholder="www.candidato.com" value={form.data.custom_domain} onChange={(event) => form.setData('custom_domain', event.target.value.toLowerCase())} /></Field>
                        <Field label="Estado del borrador"><select className="field" value={form.data.status} onChange={(event) => form.setData('status', event.target.value as 'draft' | 'disabled')}><option value="draft">Borrador activo</option><option value="disabled">Desactivado</option></select></Field>
                    </div>
                    <p className="mt-2 text-xs text-slate-400">Preview: <span className="font-bold text-slate-600">{previewUrl}</span> · Pública al publicar: <span className="font-bold text-slate-600">{publicUrl}</span></p>
                </Section>

                <Section title="Hero">
                    <div className="grid gap-4 md:grid-cols-2">
                        <Field label="Título" error={form.errors['hero.title']} wide><input className="field" value={form.data.hero.title} onChange={(event) => form.setData('hero', { ...form.data.hero, title: event.target.value })} /></Field>
                        <Field label="Subtítulo"><input className="field" value={form.data.hero.subtitle} onChange={(event) => form.setData('hero', { ...form.data.hero, subtitle: event.target.value })} /></Field>
                        <Field label="Foto/video principal"><input className="field" placeholder="https://... o /storage/..." value={form.data.hero.media_url} onChange={(event) => form.setData('hero', { ...form.data.hero, media_url: event.target.value })} /></Field>
                        <Field label="Texto del botón"><input className="field" value={form.data.hero.cta_label} onChange={(event) => form.setData('hero', { ...form.data.hero, cta_label: event.target.value })} /></Field>
                        <Field label="URL del botón"><input className="field" value={form.data.hero.cta_url} onChange={(event) => form.setData('hero', { ...form.data.hero, cta_url: event.target.value })} /></Field>
                    </div>
                </Section>

                <Section title="Hoja de vida">
                    <textarea rows={8} className="field resize-y" value={form.data.biography} onChange={(event) => form.setData('biography', event.target.value)} />
                </Section>

                <EditablePairs title="Trayectoria y logros" items={form.data.trajectory} onAdd={() => addPair('trajectory')} onRemove={(index) => removePair('trajectory', index)} onChange={(items) => form.setData('trajectory', items)} />
                <EditablePairs title="Propuestas" items={form.data.proposals} onAdd={() => addPair('proposals')} onRemove={(index) => removePair('proposals', index)} onChange={(items) => form.setData('proposals', items)} />

                <Section title="Galería mixta">
                    <div className="space-y-3">
                        <div className="rounded-xl border border-dashed border-slate-200 bg-white p-3">
                            <div className="grid gap-3 md:grid-cols-[1fr_1fr_auto]">
                                <input type="file" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm" className="field py-2 text-xs" onChange={(event) => setMediaFile(event.target.files?.[0] ?? null)} />
                                <input className="field py-2 text-xs" placeholder="Texto alternativo" value={mediaAlt} onChange={(event) => setMediaAlt(event.target.value)} />
                                <button type="button" onClick={uploadMedia} disabled={!mediaFile} className="secondary-button justify-center disabled:cursor-not-allowed disabled:opacity-50"><Upload size={15} /> Subir</button>
                            </div>
                            <p className="mt-2 text-[11px] font-semibold text-slate-400">También puedes seguir pegando URLs externas o rutas /storage manualmente.</p>
                        </div>
                        {form.data.gallery.map((item, index) => <div key={index} className="grid gap-3 rounded-xl border border-slate-100 bg-slate-50 p-3 md:grid-cols-[120px_1fr_1fr_auto]">
                            <select className="field py-2 text-xs" value={item.type} onChange={(event) => form.setData('gallery', replaceAt(form.data.gallery, index, { ...item, type: event.target.value as 'image' | 'video' }))}><option value="image">Imagen</option><option value="video">Video</option></select>
                            <input className="field py-2 text-xs" placeholder="URL o /storage/..." value={item.url} onChange={(event) => form.setData('gallery', replaceAt(form.data.gallery, index, { ...item, url: event.target.value }))} />
                            <input className="field py-2 text-xs" placeholder="Texto alternativo" value={item.alt} onChange={(event) => form.setData('gallery', replaceAt(form.data.gallery, index, { ...item, alt: event.target.value }))} />
                            <RowActions
                                index={index}
                                total={form.data.gallery.length}
                                onUp={() => form.setData('gallery', moveItem(form.data.gallery, index, index - 1))}
                                onDown={() => form.setData('gallery', moveItem(form.data.gallery, index, index + 1))}
                                onRemove={() => form.setData('gallery', form.data.gallery.filter((_, itemIndex) => itemIndex !== index))}
                            />
                        </div>)}
                        <button type="button" onClick={() => form.setData('gallery', [...form.data.gallery, { type: 'image', url: '', alt: '' }])} className="secondary-button"><Plus size={15} /> Agregar medio</button>
                    </div>
                </Section>
            </div>

            <aside className="space-y-5">
                <Section title="Redes sociales">
                    <div className="space-y-3">
                        {form.data.social_accounts.map((account, index) => <div key={account.provider} className="rounded-xl border border-slate-100 bg-slate-50 p-3">
                            <div className="text-xs font-black capitalize text-slate-700">{account.provider}</div>
                            <input className="field mt-2 py-2 text-xs" placeholder="@usuario" value={account.handle} onChange={(event) => form.setData('social_accounts', replaceAt(form.data.social_accounts, index, { ...account, handle: event.target.value }))} />
                            <input className="field mt-2 py-2 text-xs" placeholder="URL del perfil" value={account.profile_url} onChange={(event) => form.setData('social_accounts', replaceAt(form.data.social_accounts, index, { ...account, profile_url: event.target.value }))} />
                            <select className="field mt-2 py-2 text-xs" value={account.status} onChange={(event) => form.setData('social_accounts', replaceAt(form.data.social_accounts, index, { ...account, status: event.target.value as SocialAccount['status'] }))}><option value="not_configured">No configurado</option><option value="connected">Conectado</option><option value="review_required">Requiere revisión/permisos</option><option value="error">Error</option></select>
                        </div>)}
                    </div>
                </Section>

                <Section title="Publicaciones destacadas">
                    <div className="space-y-3">
                        {form.data.social_posts.map((post, index) => <div key={index} className="rounded-xl border border-slate-100 bg-slate-50 p-3">
                            <div className="flex justify-between gap-2">
                                <select className="field py-2 text-xs" value={post.provider} onChange={(event) => form.setData('social_posts', replaceAt(form.data.social_posts, index, { ...post, provider: event.target.value as 'instagram' | 'facebook' }))}><option value="instagram">Instagram</option><option value="facebook">Facebook</option></select>
                                <RowActions
                                    compact
                                    index={index}
                                    total={form.data.social_posts.length}
                                    onUp={() => form.setData('social_posts', moveItem(form.data.social_posts, index, index - 1))}
                                    onDown={() => form.setData('social_posts', moveItem(form.data.social_posts, index, index + 1))}
                                    onRemove={() => form.setData('social_posts', form.data.social_posts.filter((_, itemIndex) => itemIndex !== index))}
                                />
                            </div>
                            <input className="field mt-2 py-2 text-xs" placeholder="URL de publicación" value={post.url} onChange={(event) => form.setData('social_posts', replaceAt(form.data.social_posts, index, { ...post, url: event.target.value }))} />
                            <input className="field mt-2 py-2 text-xs" placeholder="Título" value={post.title} onChange={(event) => form.setData('social_posts', replaceAt(form.data.social_posts, index, { ...post, title: event.target.value }))} />
                            <textarea rows={2} className="field mt-2 resize-none py-2 text-xs" placeholder="Resumen" value={post.summary} onChange={(event) => form.setData('social_posts', replaceAt(form.data.social_posts, index, { ...post, summary: event.target.value }))} />
                            <input className="field mt-2 py-2 text-xs" placeholder="Imagen/video destacado" value={post.media_url} onChange={(event) => form.setData('social_posts', replaceAt(form.data.social_posts, index, { ...post, media_url: event.target.value }))} />
                            <input type="date" className="field mt-2 py-2 text-xs" value={post.published_on ?? ''} onChange={(event) => form.setData('social_posts', replaceAt(form.data.social_posts, index, { ...post, published_on: event.target.value }))} />
                        </div>)}
                        <button type="button" onClick={() => form.setData('social_posts', [...form.data.social_posts, { provider: 'instagram', url: '', title: '', summary: '', media_url: '', published_on: '' }])} className="secondary-button w-full justify-center"><Plus size={15} /> Agregar publicación</button>
                    </div>
                </Section>

                <Section title="Contacto y legal">
                    <input className="field py-2 text-xs" placeholder="Correo" value={form.data.contact.email} onChange={(event) => form.setData('contact', { ...form.data.contact, email: event.target.value })} />
                    <input className="field mt-2 py-2 text-xs" placeholder="Teléfono" value={form.data.contact.phone} onChange={(event) => form.setData('contact', { ...form.data.contact, phone: event.target.value })} />
                    <input className="field mt-2 py-2 text-xs" placeholder="WhatsApp URL" value={form.data.contact.whatsapp_url} onChange={(event) => form.setData('contact', { ...form.data.contact, whatsapp_url: event.target.value })} />
                    <textarea rows={3} className="field mt-2 resize-none py-2 text-xs" placeholder="Pie legal" value={form.data.legal_footer} onChange={(event) => form.setData('legal_footer', event.target.value)} />
                </Section>

                {(Object.keys(errors).length > 0) && <div className="rounded-xl border border-red-200 bg-red-50 p-3 text-xs font-bold text-red-700">Revisa los campos marcados antes de guardar.</div>}
                <button className="primary-button w-full justify-center" disabled={form.processing}><Save size={16} /> Guardar borrador</button>
            </aside>
        </form>
    </AppLayout>;
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
    return <section className="panel p-5"><h2 className="mb-4 text-sm font-black text-slate-800">{title}</h2>{children}</section>;
}

function Field({ label, error, wide, children }: { label: string; error?: string; wide?: boolean; children: React.ReactNode }) {
    return <div className={wide ? 'md:col-span-2' : ''}><label className="label">{label}</label>{children}{error && <p className="mt-1 text-xs font-bold text-red-600">{error}</p>}</div>;
}

function EditablePairs({ title, items, onAdd, onRemove, onChange }: { title: string; items: Pair[]; onAdd: () => void; onRemove: (index: number) => void; onChange: (items: Pair[]) => void }) {
    return <Section title={title}><div className="space-y-3">{items.map((item, index) => <div key={index} className="grid gap-3 rounded-xl border border-slate-100 bg-slate-50 p-3 md:grid-cols-[1fr_2fr_auto]"><input className="field py-2 text-xs" placeholder="Título" value={item.title} onChange={(event) => onChange(replaceAt(items, index, { ...item, title: event.target.value }))} /><textarea rows={2} className="field resize-none py-2 text-xs" placeholder="Descripción" value={item.description} onChange={(event) => onChange(replaceAt(items, index, { ...item, description: event.target.value }))} /><RowActions index={index} total={items.length} onUp={() => onChange(moveItem(items, index, index - 1))} onDown={() => onChange(moveItem(items, index, index + 1))} onRemove={() => onRemove(index)} /></div>)}<button type="button" onClick={onAdd} className="secondary-button"><Plus size={15} /> Agregar</button></div></Section>;
}

function RowActions({ index, total, onUp, onDown, onRemove, compact = false }: { index: number; total: number; onUp: () => void; onDown: () => void; onRemove: () => void; compact?: boolean }) {
    return <div className={`flex ${compact ? 'gap-1' : 'justify-end gap-1 md:justify-start'}`}>
        <button type="button" onClick={onUp} disabled={index === 0} title="Subir" className="rounded-lg p-2 text-slate-500 transition hover:bg-white hover:text-slate-800 disabled:cursor-not-allowed disabled:opacity-30"><ArrowUp size={15} /></button>
        <button type="button" onClick={onDown} disabled={index >= total - 1} title="Bajar" className="rounded-lg p-2 text-slate-500 transition hover:bg-white hover:text-slate-800 disabled:cursor-not-allowed disabled:opacity-30"><ArrowDown size={15} /></button>
        <button type="button" onClick={onRemove} title="Eliminar" className="rounded-lg p-2 text-red-500 transition hover:bg-white"><Trash2 size={15} /></button>
    </div>;
}

function replaceAt<T>(items: T[], index: number, value: T): T[] {
    return items.map((item, itemIndex) => itemIndex === index ? value : item);
}

function moveItem<T>(items: T[], from: number, to: number): T[] {
    if (to < 0 || to >= items.length) return items;
    const next = [...items];
    const [item] = next.splice(from, 1);
    next.splice(to, 0, item);
    return next;
}

function slugify(value: string): string {
    return value.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
}

function normalize(content: PublicSiteContent): PublicSiteContent {
    return {
        ...content,
        trajectory: content.trajectory?.length ? content.trajectory : [{ title: '', description: '' }],
        proposals: content.proposals?.length ? content.proposals : [{ title: '', description: '' }],
        gallery: content.gallery?.length ? content.gallery : [{ type: 'image', url: '', alt: '' }],
        social_accounts: content.social_accounts?.length ? content.social_accounts : [
            { provider: 'instagram', handle: '', profile_url: '', status: 'not_configured' },
            { provider: 'facebook', handle: '', profile_url: '', status: 'not_configured' },
        ],
        social_posts: content.social_posts?.length ? content.social_posts : [{ provider: 'instagram', url: '', title: '', summary: '', media_url: '', published_on: '' }],
    };
}
