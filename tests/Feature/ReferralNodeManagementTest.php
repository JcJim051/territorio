<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignMembership;
use App\Models\CampaignRole;
use App\Models\DivipolSnapshot;
use App\Models\Election;
use App\Models\Organization;
use App\Models\Person;
use App\Models\PublicToken;
use App\Models\TerritoryUnit;
use App\Models\User;
use App\Models\VotingPlace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReferralNodeManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_promotes_node_and_gets_recoverable_encrypted_link(): void
    {
        [$campaign, $user, $person, $territory] = $this->context();

        $this->actingAs($user)->post("/territorial/nodes/{$person->public_id}", [
            'label' => 'Enlace Comuna Uno',
            'expires_at' => now()->addMonth()->format('Y-m-d'),
            'max_uses' => 25,
            'territory_unit_ids' => [$territory->id],
        ])->assertRedirect();

        $person->refresh();
        $token = PublicToken::where('owner_person_id', $person->id)->firstOrFail();
        $rawCiphertext = DB::table('public_tokens')->where('id', $token->id)->value('token_ciphertext');
        $this->assertTrue($person->is_referral_node);
        $this->assertSame(['referrals.create'], $token->abilities);
        $this->assertSame([$territory->id], $token->territorial_scope['territory_unit_ids']);
        $this->assertNotSame($token->token_ciphertext, $rawCiphertext);
        $this->assertSame(hash('sha256', $token->token_ciphertext), $token->token_hash);

        $this->actingAs($user)->get('/territorial/nodes')
            ->assertInertia(fn ($page) => $page
                ->component('Territorial/Nodes')
                ->where('nodes.data.0.name', $person->name)
                ->where('nodes.data.0.token.active', true)
                ->where('nodes.data.0.token.maxUses', 25)
                ->where('nodes.data.0.token.link', route('public.invitations.show', $token->token_ciphertext)));
    }

    public function test_rotating_token_revokes_previous_link_without_removing_network_owner(): void
    {
        [$campaign, $user, $person] = $this->context();
        $payload = ['label' => '', 'expires_at' => '', 'max_uses' => null, 'territory_unit_ids' => []];
        $this->actingAs($user)->post("/territorial/nodes/{$person->public_id}", $payload)->assertRedirect();
        $old = PublicToken::where('owner_person_id', $person->id)->firstOrFail();
        $oldPlain = $old->token_ciphertext;

        $this->actingAs($user)->post("/territorial/nodes/{$person->public_id}/rotate", $payload)->assertRedirect();

        $this->assertNotNull($old->fresh()->revoked_at);
        $new = PublicToken::where('owner_person_id', $person->id)->latest('id')->firstOrFail();
        $this->assertNotSame($oldPlain, $new->token_ciphertext);
        $this->get(route('public.invitations.show', $oldPlain))->assertGone();
        $this->get(route('public.invitations.show', $new->token_ciphertext))->assertOk();
    }

    public function test_node_and_token_actions_are_isolated_by_campaign(): void
    {
        [$campaign, $user] = $this->context();
        [$otherCampaign, $otherUser, $otherPerson] = $this->context('otra');

        $this->actingAs($user)->post("/territorial/nodes/{$otherPerson->public_id}", [
            'label' => '', 'expires_at' => '', 'max_uses' => null, 'territory_unit_ids' => [],
        ])->assertNotFound();

        $this->assertFalse($otherPerson->fresh()->is_referral_node);
    }

    public function test_user_without_token_permission_cannot_open_module_or_promote(): void
    {
        [$campaign, $user, $person] = $this->context();
        $user->campaignMemberships()->firstOrFail()->role->update(['permissions' => ['territorial.view']]);

        $this->actingAs($user)->get('/territorial/nodes')->assertForbidden();
        $this->actingAs($user)->post("/territorial/nodes/{$person->public_id}", [
            'label' => '', 'expires_at' => '', 'max_uses' => null, 'territory_unit_ids' => [],
        ])->assertForbidden();
    }

    private function context(string $suffix = 'principal'): array
    {
        $organization = Organization::create(['name' => 'Organización '.$suffix, 'slug' => 'org-'.$suffix]);
        $campaign = Campaign::create([
            'organization_id' => $organization->id,
            'name' => 'Campaña '.$suffix,
            'slug' => 'campana-'.$suffix,
            'candidate_name' => 'Candidato '.$suffix,
            'office' => 'Concejo',
            'territory' => 'Villavicencio',
            'status' => 'active',
            'timezone' => 'America/Bogota',
        ]);
        $role = CampaignRole::create([
            'campaign_id' => $campaign->id,
            'name' => 'Coordinación territorial',
            'slug' => 'coordinacion-'.$suffix,
            'permissions' => ['territorial.view', 'territorial.tokens.manage'],
            'assignment_level' => 60,
        ]);
        $user = User::factory()->create();
        CampaignMembership::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'campaign_role_id' => $role->id,
        ]);
        $territory = TerritoryUnit::create([
            'campaign_id' => $campaign->id,
            'type' => 'commune',
            'code' => 'C01',
            'name' => 'Comuna 1',
        ]);
        $election = Election::create(['campaign_id' => $campaign->id, 'name' => 'Elección', 'type' => 'concejo']);
        $snapshot = DivipolSnapshot::create(['election_id' => $election->id, 'name' => 'Actual']);
        VotingPlace::create([
            'campaign_id' => $campaign->id,
            'divipol_snapshot_id' => $snapshot->id,
            'territory_unit_id' => $territory->id,
            'dd' => '50', 'mm' => '001', 'zz' => '01', 'pp' => '01',
            'name' => 'Puesto central',
            'commune' => 'Comuna 1',
        ]);
        $person = Person::create([
            'public_id' => (string) Str::ulid(),
            'campaign_id' => $campaign->id,
            'name' => 'Líder '.$suffix,
            'search_name' => 'lider '.$suffix,
            'document_number' => '1000'.random_int(1000, 9999),
            'status' => 'verified',
            'verified_at' => now(),
        ]);

        return [$campaign, $user, $person, $territory];
    }
}
