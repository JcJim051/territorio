<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignMembership;
use App\Models\CampaignPublicSite;
use App\Models\CampaignRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CampaignPublicSiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_is_private_until_it_is_published(): void
    {
        [$campaign, $admin] = $this->context(['public_site.manage']);
        $payload = $this->payload([
            'slug' => 'candidato-villavicencio',
            'hero' => [
                'title' => 'Candidato Villavicencio',
                'subtitle' => 'Hoja de vida y propuestas',
                'media_url' => 'https://example.test/foto.jpg',
                'cta_label' => 'Apoyar',
                'cta_url' => '/public/v1/invitations/landing',
            ],
        ]);

        $this->actingAs($admin)
            ->get('/campaign/public-site')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('site.slug', 'campana'));

        $this->actingAs($admin)
            ->put('/campaign/public-site', $payload)
            ->assertRedirect();

        $site = CampaignPublicSite::where('campaign_id', $campaign->id)->firstOrFail();
        $this->assertSame('Candidato Villavicencio', $site->draft_content['hero']['title']);
        $this->get('/sites/candidato-villavicencio')->assertNotFound();

        $this->actingAs($admin)
            ->post('/campaign/public-site/publish')
            ->assertRedirect();

        $this->get('/sites/candidato-villavicencio')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('campaign.candidateName', 'Candidato')
                ->where('content.hero.title', 'Candidato Villavicencio'));
    }

    public function test_custom_domain_is_unique_across_campaigns(): void
    {
        [$campaign, $admin] = $this->context(['public_site.manage']);
        [$otherCampaign] = $this->context(['public_site.manage'], 'otra');
        CampaignPublicSite::create([
            'campaign_id' => $otherCampaign->id,
            'slug' => 'otra-candidata',
            'custom_domain' => 'www.candidata.test',
            'status' => 'draft',
            'draft_content' => [],
        ]);

        $this->actingAs($admin)
            ->get('/campaign/public-site')
            ->assertOk();

        $this->actingAs($admin)
            ->put('/campaign/public-site', $this->payload([
                'slug' => 'candidato',
                'custom_domain' => 'www.candidata.test',
            ]))
            ->assertSessionHasErrors('custom_domain');

        $this->assertDatabaseMissing('campaign_public_sites', [
            'campaign_id' => $campaign->id,
            'custom_domain' => 'www.candidata.test',
        ]);
    }

    public function test_published_custom_domain_renders_public_site_without_authentication(): void
    {
        [$campaign] = $this->context(['public_site.manage']);
        CampaignPublicSite::create([
            'campaign_id' => $campaign->id,
            'slug' => 'candidato',
            'custom_domain' => 'www.candidato.test',
            'status' => 'published',
            'draft_content' => $this->payload(),
            'published_content' => $this->payload([
                'hero' => [
                    'title' => 'Dominio propio',
                    'subtitle' => 'Página oficial',
                    'media_url' => '',
                    'cta_label' => 'Apoyar',
                    'cta_url' => '/public/v1/invitations/landing',
                ],
            ]),
            'published_at' => now(),
        ]);

        $this->get('http://www.candidato.test/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('content.hero.title', 'Dominio propio'));
    }

    public function test_user_without_permission_cannot_manage_public_site(): void
    {
        [, $user] = $this->context(['dashboard.view']);

        $this->actingAs($user)->get('/campaign/public-site')->assertForbidden();
        $this->actingAs($user)->put('/campaign/public-site', $this->payload())->assertForbidden();
    }

    public function test_gallery_accepts_local_uploaded_media(): void
    {
        Storage::fake('public');
        [$campaign, $admin] = $this->context(['public_site.manage']);

        $this->actingAs($admin)
            ->post('/campaign/public-site/media', [
                'media' => UploadedFile::fake()->image('recorrido.jpg'),
                'alt' => 'Recorrido territorial',
            ])
            ->assertRedirect();

        $site = CampaignPublicSite::where('campaign_id', $campaign->id)->firstOrFail();
        $media = $site->media()->firstOrFail();

        Storage::disk('public')->assertExists($media->path);
        $this->assertSame('image', $media->type);
        $this->assertSame('Recorrido territorial', $media->alt_text);
        $galleryItem = collect($site->fresh()->draft_content['gallery'])->last();
        $this->assertSame('Recorrido territorial', $galleryItem['alt']);
        $this->assertStringStartsWith('/storage/public-sites/'.$campaign->id.'/', $galleryItem['url']);
    }

    private function context(array $permissions, string $suffix = 'campana'): array
    {
        $organization = Organization::firstOrCreate(['slug' => 'organizacion-'.$suffix], ['name' => 'Organización '.$suffix]);
        $campaign = Campaign::create([
            'organization_id' => $organization->id,
            'name' => 'Campaña '.$suffix,
            'slug' => $suffix,
            'candidate_name' => 'Candidato',
            'office' => 'Concejo',
            'territory' => 'Villavicencio',
            'status' => 'active',
        ]);
        $role = CampaignRole::create([
            'campaign_id' => $campaign->id,
            'name' => 'Rol',
            'slug' => 'rol',
            'permissions' => $permissions,
        ]);
        $user = User::factory()->create();
        CampaignMembership::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'campaign_role_id' => $role->id,
        ]);

        return [$campaign, $user];
    }

    private function payload(array $overrides = []): array
    {
        return [
            'slug' => 'campana',
            'custom_domain' => '',
            'status' => 'draft',
            'hero' => [
                'title' => 'Candidato',
                'subtitle' => 'Concejo · Villavicencio',
                'media_url' => '',
                'cta_label' => 'Conocer propuestas',
                'cta_url' => '#propuestas',
            ],
            'biography' => 'Hoja de vida pública.',
            'trajectory' => [['title' => 'Servicio público', 'description' => 'Trabajo comunitario.']],
            'proposals' => [['title' => 'Seguridad', 'description' => 'Gestión territorial.']],
            'gallery' => [['type' => 'image', 'url' => 'https://example.test/image.jpg', 'alt' => 'En territorio']],
            'social_accounts' => [
                ['provider' => 'instagram', 'handle' => '@candidato', 'profile_url' => 'https://instagram.com/candidato', 'status' => 'not_configured'],
                ['provider' => 'facebook', 'handle' => 'Candidato', 'profile_url' => 'https://facebook.com/candidato', 'status' => 'not_configured'],
            ],
            'social_posts' => [[
                'provider' => 'instagram',
                'url' => 'https://instagram.com/p/demo',
                'title' => 'Recorrido en barrio',
                'summary' => 'Publicación destacada.',
                'media_url' => 'https://example.test/post.jpg',
                'published_on' => now()->toDateString(),
            ]],
            'contact' => ['email' => 'contacto@example.test', 'phone' => '3001234567', 'whatsapp_url' => 'https://wa.me/573001234567'],
            'legal_footer' => 'Publicidad política pagada.',
            ...$overrides,
        ];
    }
}
