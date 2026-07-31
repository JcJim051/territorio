<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\CampaignMembership;
use App\Models\CampaignRole;
use App\Models\DivipolSnapshot;
use App\Models\Election;
use App\Models\Meeting;
use App\Models\Organization;
use App\Models\Person;
use App\Models\PublicToken;
use App\Models\ReferralRelationship;
use App\Models\Resource;
use App\Models\TerritoryUnit;
use App\Models\User;
use App\Models\VotingPlace;
use App\Models\VotingTable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $organization = Organization::create([
            'name' => 'Movimiento Territorial',
            'slug' => 'movimiento-territorial',
            'status' => 'active',
        ]);

        $admin = User::create([
            'name' => 'Gerencia de Campaña',
            'email' => 'admin@territorio.test',
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'is_super_admin' => true,
        ]);

        $campaign = Campaign::create([
            'organization_id' => $organization->id,
            'name' => 'Villavicencio 2027',
            'slug' => 'villavicencio-2027',
            'candidate_name' => 'Candidato Demo',
            'office' => 'Concejo de Villavicencio',
            'territory' => 'Villavicencio, Meta',
            'starts_at' => now()->toDateString(),
            'election_at' => '2027-10-31',
            'theme_color' => '#0D4D4B',
            'enabled_modules' => ['territorial', 'meetings', 'inventory', 'analytics'],
            'settings' => ['node_activation' => 'approval'],
        ]);

        $managerRole = CampaignRole::create([
            'campaign_id' => $campaign->id,
            'name' => 'Gerencia',
            'slug' => 'manager',
            'permissions' => ['*'],
            'is_system' => true,
        ]);
        CampaignMembership::create([
            'campaign_id' => $campaign->id,
            'user_id' => $admin->id,
            'campaign_role_id' => $managerRole->id,
        ]);

        $election = Election::create([
            'campaign_id' => $campaign->id,
            'name' => 'Elecciones territoriales 2027',
            'type' => 'concejo_municipal',
            'election_at' => '2027-10-31',
        ]);
        $snapshot = DivipolSnapshot::create([
            'election_id' => $election->id,
            'name' => 'DIVIPOL demostrativa',
            'source' => 'Datos de demostración',
            'cutoff_at' => now()->toDateString(),
        ]);

        $municipality = TerritoryUnit::create([
            'campaign_id' => $campaign->id,
            'divipol_snapshot_id' => $snapshot->id,
            'type' => 'municipality',
            'code' => '50001',
            'name' => 'Villavicencio',
            'path' => '50001',
        ]);
        $communeOne = TerritoryUnit::create([
            'campaign_id' => $campaign->id,
            'divipol_snapshot_id' => $snapshot->id,
            'parent_id' => $municipality->id,
            'type' => 'commune',
            'code' => 'C01',
            'name' => 'Comuna 1',
            'path' => '50001.C01',
        ]);
        $communeFive = TerritoryUnit::create([
            'campaign_id' => $campaign->id,
            'divipol_snapshot_id' => $snapshot->id,
            'parent_id' => $municipality->id,
            'type' => 'commune',
            'code' => 'C05',
            'name' => 'Comuna 5',
            'path' => '50001.C05',
        ]);

        $placeOne = $this->createPlace($campaign->id, $snapshot->id, $communeOne->id, [
            'zz' => '01', 'pp' => '05', 'name' => 'I.E. Juan Pablo II', 'commune' => 'Comuna 1', 'tables' => 12,
        ]);
        $placeFive = $this->createPlace($campaign->id, $snapshot->id, $communeFive->id, [
            'zz' => '05', 'pp' => '02', 'name' => 'Colegio Departamental La Esperanza', 'commune' => 'Comuna 5', 'tables' => 10,
        ]);

        $names = [
            ['María Fernanda Rojas', $placeOne, 2, 'active'],
            ['Carlos Andrés Pérez', $placeOne, 4, 'verified'],
            ['Diana Marcela Silva', $placeOne, 6, 'verified'],
            ['Jorge Ramírez', $placeFive, 1, 'verified'],
            ['Paola Gómez', $placeFive, 3, 'verified'],
            ['Luis Herrera', $placeOne, 8, 'verified'],
            ['Natalia Cárdenas', $placeFive, 7, 'pending'],
            ['Ricardo Torres', $placeOne, 10, 'verified'],
        ];
        $people = collect();
        foreach ($names as $index => [$name, $place, $tableNumber, $status]) {
            $document = '10000000'.($index + 1);
            $people->push(Person::create([
                'public_id' => (string) Str::ulid(),
                'campaign_id' => $campaign->id,
                'voting_place_id' => $place->id,
                'voting_table_id' => $place->tables()->where('number', $tableNumber)->value('id'),
                'name' => $name,
                'search_name' => Str::lower(Str::ascii($name)),
                'email' => "persona{$index}@example.test",
                'phone' => '30000000'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'document_number' => $document,
                'document_hash' => hash_hmac('sha256', $document, config('app.key')),
                'document_last_four' => substr($document, -4),
                'status' => $status,
                'verified_at' => $status === 'pending' ? null : now()->subDays(8 - $index),
                'created_at' => now()->subDays(8 - $index),
                'updated_at' => now(),
            ]));
        }

        $links = [[0, 1], [0, 2], [0, 3], [1, 4], [1, 5], [3, 6], [5, 7]];
        foreach ($links as [$parent, $child]) {
            ReferralRelationship::create([
                'campaign_id' => $campaign->id,
                'parent_person_id' => $people[$parent]->id,
                'child_person_id' => $people[$child]->id,
                'path' => $people[$parent]->id.'.'.$people[$child]->id,
                'depth' => 1,
                'started_at' => now()->subDays(7 - $child),
            ]);
        }

        DB::table('territorial_roles')->insert([
            'campaign_id' => $campaign->id,
            'person_id' => $people[0]->id,
            'territory_unit_id' => $communeOne->id,
            'role' => 'commune_coordinator',
            'title' => 'Coordinadora Comuna 1',
            'status' => 'approved',
            'approved_by' => $admin->id,
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        PublicToken::create([
            'campaign_id' => $campaign->id,
            'owner_person_id' => $people[0]->id,
            'token_hash' => hash('sha256', 'demo-villavicencio-2027'),
            'label' => 'Enlace demo de María Fernanda',
            'abilities' => ['referrals.create'],
            'territorial_scope' => ['territory_unit_ids' => [$communeOne->id]],
            'expires_at' => now()->addYear(),
        ]);

        Meeting::create([
            'public_id' => (string) Str::ulid(),
            'campaign_id' => $campaign->id,
            'requested_by' => $admin->id,
            'leader_person_id' => $people[0]->id,
            'territory_unit_id' => $communeOne->id,
            'type' => 'reunion',
            'title' => 'Encuentro con líderes de la Comuna 1',
            'objective' => 'Presentar la estrategia territorial y escuchar prioridades del sector.',
            'location' => 'Salón comunal Chapinerito',
            'address' => 'Carrera 32 # 35-20, Villavicencio',
            'latitude' => 4.1531000,
            'longitude' => -73.6377000,
            'location_notes' => 'Ingreso por la puerta principal del salón comunal.',
            'starts_at' => now()->addDay()->setTime(18, 0),
            'ends_at' => now()->addDay()->setTime(20, 0),
            'expected_attendees' => 45,
            'status' => 'approved',
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ]);
        Meeting::create([
            'public_id' => (string) Str::ulid(),
            'campaign_id' => $campaign->id,
            'requested_by' => $admin->id,
            'leader_person_id' => $people[3]->id,
            'territory_unit_id' => $communeFive->id,
            'type' => 'desayuno',
            'title' => 'Desayuno con comerciantes',
            'location' => 'Sector La Esperanza',
            'address' => 'Avenida Catama, sector La Esperanza, Villavicencio',
            'latitude' => 4.1392000,
            'longitude' => -73.6073000,
            'starts_at' => now()->addDays(3)->setTime(8, 0),
            'ends_at' => now()->addDays(3)->setTime(9, 30),
            'expected_attendees' => 20,
            'status' => 'requested',
        ]);

        Resource::create([
            'organization_id' => $organization->id,
            'campaign_id' => $campaign->id,
            'name' => 'Refrigerios',
            'sku' => 'REF-001',
            'kind' => 'consumable',
            'unit' => 'unidad',
            'quantity' => 50,
            'minimum_quantity' => 60,
        ]);
        Resource::create([
            'organization_id' => $organization->id,
            'name' => 'Videobeam',
            'sku' => 'AV-001',
            'kind' => 'asset',
            'unit' => 'equipo',
            'quantity' => 2,
            'minimum_quantity' => 1,
            'is_shared' => true,
        ]);

        $secondCampaign = Campaign::create([
            'organization_id' => $organization->id,
            'name' => 'Acacías 2027',
            'slug' => 'acacias-2027',
            'candidate_name' => 'Campaña Acacías',
            'office' => 'Concejo de Acacías',
            'territory' => 'Acacías, Meta',
            'starts_at' => now()->toDateString(),
            'election_at' => '2027-10-31',
            'enabled_modules' => ['territorial', 'meetings'],
        ]);
        $secondRole = CampaignRole::create([
            'campaign_id' => $secondCampaign->id,
            'name' => 'Gerencia',
            'slug' => 'manager',
            'permissions' => ['*'],
            'is_system' => true,
        ]);
        CampaignMembership::create([
            'campaign_id' => $secondCampaign->id,
            'user_id' => $admin->id,
            'campaign_role_id' => $secondRole->id,
        ]);
    }

    private function createPlace(int $campaignId, int $snapshotId, int $territoryId, array $data): VotingPlace
    {
        $place = VotingPlace::create([
            'campaign_id' => $campaignId,
            'divipol_snapshot_id' => $snapshotId,
            'territory_unit_id' => $territoryId,
            'dd' => '50',
            'mm' => '001',
            'zz' => $data['zz'],
            'pp' => $data['pp'],
            'unique_code' => '50001'.$data['zz'].$data['pp'],
            'name' => $data['name'],
            'address' => 'Dirección demostrativa',
            'commune' => $data['commune'],
            'census' => $data['tables'] * 350,
            'tables_count' => $data['tables'],
        ]);
        foreach (range(1, $data['tables']) as $number) {
            VotingTable::create([
                'campaign_id' => $campaignId,
                'voting_place_id' => $place->id,
                'number' => $number,
                'census' => 350,
            ]);
        }

        return $place;
    }
}
