<?php

namespace Tests\Unit;

use App\Models\Campaign;
use App\Models\Organization;
use App\Models\Person;
use App\Services\ReferralNetworkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReferralNetworkServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_network_rejects_cycles_and_multiple_active_parents(): void
    {
        $organization = Organization::create(['name' => 'Org', 'slug' => 'org']);
        $campaign = Campaign::create([
            'organization_id' => $organization->id,
            'name' => 'Campaña',
            'slug' => 'campana',
            'candidate_name' => 'Candidato',
            'office' => 'Concejo',
            'territory' => 'Meta',
        ]);
        $a = $this->person($campaign->id, 'A');
        $b = $this->person($campaign->id, 'B');
        $c = $this->person($campaign->id, 'C');
        $service = app(ReferralNetworkService::class);
        $service->connect($a, $b);
        $service->connect($b, $c);

        $this->expectException(ValidationException::class);
        $service->connect($c, $a);
    }

    private function person(int $campaignId, string $name): Person
    {
        return Person::create([
            'public_id' => (string) Str::ulid(),
            'campaign_id' => $campaignId,
            'name' => $name,
            'search_name' => strtolower($name),
            'status' => 'verified',
        ]);
    }
}
