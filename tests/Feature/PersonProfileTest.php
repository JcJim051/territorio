<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignMembership;
use App\Models\Organization;
use App\Models\Person;
use App\Models\ReferralRelationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_open_a_person_360_profile_with_network_metrics(): void
    {
        [$campaign, $user] = $this->context();
        $parent = $this->person($campaign, 'Nodo principal');
        $parent->update([
            'document_number' => '1122334455',
            'document_last_four' => '4455',
        ]);
        $child = $this->person($campaign, 'Referido directo');

        ReferralRelationship::create([
            'campaign_id' => $campaign->id,
            'parent_person_id' => $parent->id,
            'child_person_id' => $child->id,
            'path' => "{$parent->id}.{$child->id}",
            'depth' => 1,
            'started_at' => now(),
        ]);

        $this->actingAs($user)
            ->get("/people/{$parent->public_id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('People/Show')
                ->where('person.id', $parent->public_id)
                ->where('person.name', 'Nodo principal')
                ->where('person.document', '1122334455')
                ->where('person.metrics.directReferrals', 1)
                ->where('person.metrics.descendants', 1)
                ->where('person.metrics.networkDepth', 1)
                ->has('person.directReferrals', 1)
                ->where('person.directReferrals.0.id', $child->public_id));

        $this->actingAs($user)
            ->get('/people?search=nodo%20principal')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('people.data.0.document', '1122334455'));
    }

    public function test_person_profile_cannot_be_opened_from_another_campaign(): void
    {
        [$campaign, $user, $organization] = $this->context();
        $otherCampaign = Campaign::create([
            'organization_id' => $organization->id,
            'name' => 'Otra campaña',
            'slug' => 'otra-campana',
            'candidate_name' => 'Otra candidatura',
            'office' => 'Concejo',
            'territory' => 'Meta',
        ]);
        $person = $this->person($otherCampaign, 'Persona externa');

        $this->actingAs($user)
            ->get("/people/{$person->public_id}")
            ->assertNotFound();
    }

    private function context(): array
    {
        $organization = Organization::create(['name' => 'Organización', 'slug' => 'organizacion']);
        $campaign = Campaign::create([
            'organization_id' => $organization->id,
            'name' => 'Campaña',
            'slug' => 'campana',
            'candidate_name' => 'Candidatura',
            'office' => 'Concejo',
            'territory' => 'Villavicencio',
        ]);
        $user = User::factory()->create(['is_super_admin' => true]);
        CampaignMembership::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
        ]);

        return [$campaign, $user, $organization];
    }

    private function person(Campaign $campaign, string $name): Person
    {
        return Person::create([
            'public_id' => (string) str()->ulid(),
            'campaign_id' => $campaign->id,
            'name' => $name,
            'search_name' => str($name)->ascii()->lower()->toString(),
            'status' => 'verified',
        ]);
    }
}
