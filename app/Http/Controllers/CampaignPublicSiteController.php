<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveCurrentCampaign;
use App\Models\Campaign;
use App\Models\CampaignPublicSite;
use App\Models\PublicSiteMedia;
use App\Support\Audit;
use App\Support\CurrentCampaign;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CampaignPublicSiteController extends Controller
{
    public function root(Request $request)
    {
        $site = $this->siteByHost($request);

        if ($site) {
            return $this->renderPublicSite($site);
        }

        if (! $request->user()) {
            return redirect()->route('login');
        }

        return app(ResolveCurrentCampaign::class)->handle(
            $request,
            fn (Request $request) => app(DashboardController::class)(app(CurrentCampaign::class))->toResponse($request),
        );
    }

    public function edit(CurrentCampaign $current): Response
    {
        $current->authorize('public_site.manage');
        $site = $this->siteFor($current->campaign);

        return Inertia::render('Campaign/PublicSite', [
            'site' => $this->serializeAdmin($site),
            'previewUrl' => '/campaign/public-site/preview',
            'publicUrl' => '/sites/'.$site->slug,
        ]);
    }

    public function update(Request $request, CurrentCampaign $current): RedirectResponse
    {
        $current->authorize('public_site.manage');
        $site = $this->siteFor($current->campaign);
        $data = $this->validatedData($request, $site);
        $old = $site->only(['slug', 'custom_domain', 'status', 'draft_content']);

        $site->update([
            'slug' => $data['slug'],
            'custom_domain' => $data['custom_domain'] ?: null,
            'status' => $site->status === 'published' ? 'published' : $data['status'],
            'draft_content' => $this->contentFrom($data),
        ]);

        Audit::record('public_site.draft_updated', $site, $site->only(['slug', 'custom_domain', 'status']), $old, $current->campaign);

        return back()->with('success', 'El borrador de la página pública fue guardado.');
    }

    public function uploadMedia(Request $request, CurrentCampaign $current): RedirectResponse
    {
        $current->authorize('public_site.manage');
        $site = $this->siteFor($current->campaign);
        $data = $request->validate([
            'media' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm', 'max:20480'],
            'alt' => ['nullable', 'string', 'max:180'],
        ]);

        $file = $data['media'];
        $path = $file->store('public-sites/'.$current->campaign->id, 'public');
        $storageUrl = Storage::disk('public')->url($path);
        $url = parse_url($storageUrl, PHP_URL_PATH) ?: $storageUrl;
        $url = Str::start($url, '/');
        $type = str_starts_with((string) $file->getMimeType(), 'video/') ? 'video' : 'image';
        $content = $site->draft_content ?: $this->defaultContent($current->campaign);
        $gallery = $content['gallery'] ?? [];
        $gallery[] = [
            'type' => $type,
            'url' => $url,
            'alt' => $data['alt'] ?? '',
        ];
        $content['gallery'] = $gallery;

        PublicSiteMedia::create([
            'campaign_id' => $current->campaign->id,
            'campaign_public_site_id' => $site->id,
            'type' => $type,
            'disk' => 'public',
            'path' => $path,
            'alt_text' => $data['alt'] ?? null,
            'sort_order' => count($gallery),
        ]);

        $site->update(['draft_content' => $content]);

        Audit::record('public_site.media_uploaded', $site, ['path' => $path, 'type' => $type], [], $current->campaign);

        return back()->with('success', 'El archivo fue agregado al borrador de la galería.');
    }

    public function publish(CurrentCampaign $current): RedirectResponse
    {
        $current->authorize('public_site.manage');
        $site = $this->siteFor($current->campaign);
        $old = $site->only(['status', 'published_content', 'published_at']);

        $site->update([
            'status' => 'published',
            'published_content' => $site->draft_content,
            'published_at' => now(),
            'published_by' => request()->user()->id,
        ]);

        Audit::record('public_site.published', $site, ['status' => 'published'], $old, $current->campaign);

        return back()->with('success', 'La página pública fue publicada.');
    }

    public function preview(CurrentCampaign $current): Response
    {
        $current->authorize('public_site.manage');
        $site = $this->siteFor($current->campaign);

        return Inertia::render('PublicSite/Show', [
            'campaign' => [
                'candidateName' => $current->campaign->candidate_name,
                'office' => $current->campaign->office,
                'territory' => $current->campaign->territory,
                'themeColor' => $current->campaign->theme_color ?? '#0D4D4B',
            ],
            'content' => $site->draft_content,
            'preview' => true,
        ]);
    }

    public function disable(CurrentCampaign $current): RedirectResponse
    {
        $current->authorize('public_site.manage');
        $site = $this->siteFor($current->campaign);
        $old = ['status' => $site->status];
        $site->update(['status' => 'disabled']);
        Audit::record('public_site.disabled', $site, ['status' => 'disabled'], $old, $current->campaign);

        return back()->with('success', 'La página pública fue desactivada.');
    }

    public function showBySlug(string $slug): Response
    {
        $site = CampaignPublicSite::query()
            ->with('campaign')
            ->where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        return $this->renderPublicSite($site);
    }

    private function siteByHost(Request $request): ?CampaignPublicSite
    {
        if (! Schema::hasTable('campaign_public_sites')) {
            return null;
        }

        $rawHost = $request->server('HTTP_HOST')
            ?: $request->headers->get('host')
            ?: $request->getHost();

        $host = strtolower(explode(':', (string) $rawHost)[0]);

        return CampaignPublicSite::query()
            ->with('campaign')
            ->where('custom_domain', $host)
            ->where('status', 'published')
            ->first();
    }

    private function renderPublicSite(CampaignPublicSite $site): Response
    {
        return Inertia::render('PublicSite/Show', [
            'campaign' => [
                'candidateName' => $site->campaign->candidate_name,
                'office' => $site->campaign->office,
                'territory' => $site->campaign->territory,
                'themeColor' => $site->campaign->theme_color ?? '#0D4D4B',
            ],
            'content' => $site->published_content,
        ]);
    }

    private function siteFor(Campaign $campaign): CampaignPublicSite
    {
        return CampaignPublicSite::firstOrCreate(
            ['campaign_id' => $campaign->id],
            [
                'slug' => $this->uniqueSlug($campaign->slug ?: $campaign->candidate_name),
                'status' => 'draft',
                'draft_content' => $this->defaultContent($campaign),
            ],
        );
    }

    private function validatedData(Request $request, CampaignPublicSite $site): array
    {
        return $request->validate([
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('campaign_public_sites', 'slug')->ignore($site->id)],
            'custom_domain' => ['nullable', 'string', 'max:180', 'regex:/^[a-z0-9.-]+$/', Rule::unique('campaign_public_sites', 'custom_domain')->ignore($site->id)],
            'status' => ['required', Rule::in(['draft', 'disabled'])],
            'hero' => ['required', 'array'],
            'hero.title' => ['required', 'string', 'max:160'],
            'hero.subtitle' => ['nullable', 'string', 'max:500'],
            'hero.media_url' => ['nullable', 'string', 'max:1000'],
            'hero.cta_label' => ['nullable', 'string', 'max:80'],
            'hero.cta_url' => ['nullable', 'string', 'max:1000'],
            'biography' => ['nullable', 'string', 'max:12000'],
            'trajectory' => ['nullable', 'array', 'max:20'],
            'trajectory.*.title' => ['nullable', 'string', 'max:160'],
            'trajectory.*.description' => ['nullable', 'string', 'max:1000'],
            'proposals' => ['nullable', 'array', 'max:20'],
            'proposals.*.title' => ['nullable', 'string', 'max:160'],
            'proposals.*.description' => ['nullable', 'string', 'max:1000'],
            'gallery' => ['nullable', 'array', 'max:30'],
            'gallery.*.type' => ['nullable', Rule::in(['image', 'video'])],
            'gallery.*.url' => ['nullable', 'string', 'max:1000'],
            'gallery.*.alt' => ['nullable', 'string', 'max:180'],
            'social_accounts' => ['nullable', 'array', 'max:4'],
            'social_accounts.*.provider' => ['nullable', Rule::in(['instagram', 'facebook'])],
            'social_accounts.*.handle' => ['nullable', 'string', 'max:120'],
            'social_accounts.*.profile_url' => ['nullable', 'string', 'max:1000'],
            'social_accounts.*.status' => ['nullable', Rule::in(['not_configured', 'connected', 'error', 'review_required'])],
            'social_posts' => ['nullable', 'array', 'max:20'],
            'social_posts.*.provider' => ['nullable', Rule::in(['instagram', 'facebook'])],
            'social_posts.*.url' => ['nullable', 'string', 'max:1000'],
            'social_posts.*.title' => ['nullable', 'string', 'max:160'],
            'social_posts.*.summary' => ['nullable', 'string', 'max:1000'],
            'social_posts.*.media_url' => ['nullable', 'string', 'max:1000'],
            'social_posts.*.published_on' => ['nullable', 'date'],
            'contact' => ['nullable', 'array'],
            'contact.email' => ['nullable', 'email', 'max:180'],
            'contact.phone' => ['nullable', 'string', 'max:80'],
            'contact.whatsapp_url' => ['nullable', 'string', 'max:1000'],
            'legal_footer' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function contentFrom(array $data): array
    {
        $hero = $data['hero'];
        $hero['media_url'] = $this->normalizeLocalStorageUrl($hero['media_url'] ?? '');
        $gallery = $this->cleanRows($data['gallery'] ?? [], ['type', 'url', 'alt']);
        $gallery = collect($gallery)
            ->map(fn (array $item) => [
                ...$item,
                'url' => $this->normalizeLocalStorageUrl($item['url'] ?? ''),
            ])
            ->all();
        $socialPosts = $this->cleanRows($data['social_posts'] ?? [], ['provider', 'url', 'title', 'summary', 'media_url', 'published_on']);
        $socialPosts = collect($socialPosts)
            ->map(fn (array $item) => [
                ...$item,
                'media_url' => $this->normalizeLocalStorageUrl($item['media_url'] ?? ''),
            ])
            ->all();

        return [
            'hero' => $hero,
            'biography' => $data['biography'] ?? '',
            'trajectory' => $this->cleanRows($data['trajectory'] ?? [], ['title', 'description']),
            'proposals' => $this->cleanRows($data['proposals'] ?? [], ['title', 'description']),
            'gallery' => $gallery,
            'social_accounts' => $this->cleanRows($data['social_accounts'] ?? [], ['provider', 'handle', 'profile_url', 'status']),
            'social_posts' => $socialPosts,
            'contact' => $data['contact'] ?? [],
            'legal_footer' => $data['legal_footer'] ?? '',
        ];
    }

    private function cleanRows(array $rows, array $keys): array
    {
        return collect($rows)
            ->map(fn (array $row) => collect($keys)->mapWithKeys(fn (string $key) => [$key => $row[$key] ?? null])->all())
            ->filter(fn (array $row) => collect($row)->filter(fn ($value) => filled($value))->isNotEmpty())
            ->values()
            ->all();
    }

    private function normalizeLocalStorageUrl(?string $url): string
    {
        $url = trim((string) $url);
        $path = parse_url($url, PHP_URL_PATH);

        if ($path && str_starts_with($path, '/storage/')) {
            return $path;
        }

        return $url;
    }

    private function serializeAdmin(CampaignPublicSite $site): array
    {
        return [
            'slug' => $site->slug,
            'customDomain' => $site->custom_domain,
            'status' => $site->status,
            'draftContent' => $site->draft_content,
            'publishedAt' => $site->published_at?->toIso8601String(),
        ];
    }

    private function defaultContent(Campaign $campaign): array
    {
        return [
            'hero' => [
                'title' => $campaign->candidate_name,
                'subtitle' => $campaign->office.' · '.$campaign->territory,
                'media_url' => '',
                'cta_label' => 'Conoce la propuesta',
                'cta_url' => '#propuestas',
            ],
            'biography' => '',
            'trajectory' => [['title' => '', 'description' => '']],
            'proposals' => [['title' => '', 'description' => '']],
            'gallery' => [['type' => 'image', 'url' => '', 'alt' => '']],
            'social_accounts' => [
                ['provider' => 'instagram', 'handle' => '', 'profile_url' => '', 'status' => 'not_configured'],
                ['provider' => 'facebook', 'handle' => '', 'profile_url' => '', 'status' => 'not_configured'],
            ],
            'social_posts' => [['provider' => 'instagram', 'url' => '', 'title' => '', 'summary' => '', 'media_url' => '', 'published_on' => '']],
            'contact' => ['email' => '', 'phone' => '', 'whatsapp_url' => ''],
            'legal_footer' => '',
        ];
    }

    private function uniqueSlug(string $value): string
    {
        $base = Str::slug($value) ?: 'campana';
        $slug = $base;
        $counter = 2;

        while (CampaignPublicSite::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter++;
        }

        return $slug;
    }
}
