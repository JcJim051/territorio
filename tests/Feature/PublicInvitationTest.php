<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\DivipolSnapshot;
use App\Models\Election;
use App\Models\Organization;
use App\Models\Person;
use App\Models\PublicToken;
use App\Models\VotingPlace;
use App\Models\VotingTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_token_registers_person_consent_document_and_relationship(): void
    {
        Storage::fake('local');
        [$campaign, $place, $table] = $this->territorialContext();
        $owner = Person::create([
            'public_id' => (string) Str::ulid(),
            'campaign_id' => $campaign->id,
            'name' => 'Líder',
            'search_name' => 'lider',
            'status' => 'active',
        ]);
        $plainToken = 'secure-demo-token';
        PublicToken::create([
            'campaign_id' => $campaign->id,
            'owner_person_id' => $owner->id,
            'token_hash' => hash('sha256', $plainToken),
            'abilities' => ['referrals.create'],
            'expires_at' => now()->addDay(),
        ]);

        $response = $this->post('/public/v1/invitations/'.$plainToken.'/accept', [
            'name' => 'Nueva Persona',
            'email' => 'persona@example.com',
            'phone' => '3001234567',
            'document_number' => '1.234.567',
            'voting_place_id' => $place->id,
            'voting_table_number' => $table->number,
            'identity_document' => UploadedFile::fake()->createWithContent('cedula.pdf', "%PDF-1.4\nDocumento de prueba"),
            'consent_version' => '2026-07-v1',
            'consent_accepted' => '1',
        ]);

        $response->assertRedirect();
        $person = Person::where('campaign_id', $campaign->id)->where('name', 'Nueva Persona')->firstOrFail();
        $this->assertSame('verified', $person->status);
        $this->assertDatabaseHas('consents', ['person_id' => $person->id, 'version' => '2026-07-v1']);
        $this->assertDatabaseHas('identity_documents', ['person_id' => $person->id, 'is_encrypted' => true]);
        $this->assertDatabaseHas('referral_relationships', [
            'parent_person_id' => $owner->id,
            'child_person_id' => $person->id,
        ]);
    }

    public function test_revoked_token_is_rejected(): void
    {
        [$campaign] = $this->territorialContext();
        PublicToken::create([
            'campaign_id' => $campaign->id,
            'token_hash' => hash('sha256', 'revoked'),
            'abilities' => ['referrals.create'],
            'revoked_at' => now(),
        ]);

        $this->get('/public/v1/invitations/revoked')->assertGone();
    }

    public function test_file_renamed_as_pdf_is_rejected_by_its_content(): void
    {
        [$campaign, $place, $table] = $this->territorialContext();
        $plainToken = 'content-validation-token';
        PublicToken::create([
            'campaign_id' => $campaign->id,
            'token_hash' => hash('sha256', $plainToken),
            'abilities' => ['referrals.create'],
        ]);

        $this->post('/public/v1/invitations/'.$plainToken.'/accept', [
            'name' => 'Archivo Inválido',
            'email' => 'invalido@example.com',
            'phone' => '3000000000',
            'document_number' => '999999',
            'voting_place_id' => $place->id,
            'voting_table_number' => $table->number,
            'identity_document' => UploadedFile::fake()->createWithContent('cedula.pdf', 'esto no es un pdf'),
            'consent_version' => '2026-07-v1',
            'consent_accepted' => '1',
        ])->assertSessionHasErrors('identity_document');

        $this->assertDatabaseMissing('persons', ['name' => 'Archivo Inválido']);
    }

    private function territorialContext(): array
    {
        $organization = Organization::create(['name' => 'Org', 'slug' => 'org']);
        $campaign = Campaign::create([
            'organization_id' => $organization->id,
            'name' => 'Campaña',
            'slug' => 'campana',
            'candidate_name' => 'Candidato',
            'office' => 'Concejo',
            'territory' => 'Villavicencio',
        ]);
        $election = Election::create(['campaign_id' => $campaign->id, 'name' => 'Elección', 'type' => 'concejo']);
        $snapshot = DivipolSnapshot::create(['election_id' => $election->id, 'name' => 'Snapshot']);
        $place = VotingPlace::create([
            'campaign_id' => $campaign->id,
            'divipol_snapshot_id' => $snapshot->id,
            'dd' => '50',
            'mm' => '001',
            'zz' => '01',
            'pp' => '01',
            'name' => 'Puesto',
            'tables_count' => 1,
        ]);
        $table = VotingTable::create([
            'campaign_id' => $campaign->id,
            'voting_place_id' => $place->id,
            'number' => 1,
        ]);

        return [$campaign, $place, $table];
    }
}
