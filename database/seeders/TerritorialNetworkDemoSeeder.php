<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\Person;
use App\Models\ReferralRelationship;
use App\Models\VotingPlace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class TerritorialNetworkDemoSeeder extends Seeder
{
    private const CAMPAIGN_SLUG = 'villavicencio-2027';

    private const TARGET_PEOPLE = 300;

    public function run(): void
    {
        $campaign = Campaign::query()->where('slug', self::CAMPAIGN_SLUG)->first();

        if (! $campaign) {
            throw new RuntimeException('No existe la campaña Villavicencio 2027.');
        }

        $places = VotingPlace::query()
            ->where('campaign_id', $campaign->id)
            ->with('tables')
            ->orderBy('id')
            ->get();

        if ($places->isEmpty()) {
            throw new RuntimeException('La campaña no tiene puestos de votación para simular la red.');
        }

        DB::transaction(function () use ($campaign, $places): void {
            $people = Person::query()
                ->where('campaign_id', $campaign->id)
                ->orderBy('id')
                ->get();

            if ($people->isEmpty()) {
                throw new RuntimeException('La campaña necesita al menos un nodo inicial.');
            }

            $missing = max(0, self::TARGET_PEOPLE - $people->count());
            $createdPeople = collect();

            for ($offset = 0; $offset < $missing; $offset++) {
                $sequence = $people->count() + $offset + 1;
                $place = $places[$sequence % $places->count()];
                $tables = $place->tables->values();
                $table = $tables[$sequence % $tables->count()];
                $document = '9'.str_pad((string) $sequence, 9, '0', STR_PAD_LEFT);
                $name = $this->nameFor($sequence);
                $status = match (true) {
                    $sequence % 17 === 0 => 'pending',
                    $sequence % 11 === 0 => 'active',
                    default => 'verified',
                };

                $createdPeople->push(Person::create([
                    'public_id' => (string) Str::ulid(),
                    'campaign_id' => $campaign->id,
                    'voting_place_id' => $place->id,
                    'voting_table_id' => $table->id,
                    'name' => $name,
                    'search_name' => Str::lower(Str::ascii($name)),
                    'email' => "simulacion.{$sequence}@territorio.test",
                    'phone' => '310'.str_pad((string) $sequence, 7, '0', STR_PAD_LEFT),
                    'document_number' => $document,
                    'document_hash' => hash_hmac('sha256', $document, config('app.key')),
                    'document_last_four' => substr($document, -4),
                    'status' => $status,
                    'verified_at' => $status === 'pending' ? null : now()->subDays($sequence % 90),
                    'metadata' => [
                        'simulation' => true,
                        'simulation_batch' => 'territorial-network-300-v1',
                        'sequence' => $sequence,
                    ],
                    'created_at' => now()->subDays(120 - ($sequence % 120)),
                    'updated_at' => now(),
                ]));
            }

            $people = $people->concat($createdPeople)->values();
            $existingChildren = ReferralRelationship::query()
                ->where('campaign_id', $campaign->id)
                ->whereNull('ended_at')
                ->pluck('child_person_id')
                ->mapWithKeys(fn ($id) => [(int) $id => true]);

            $paths = $this->resolvePaths($campaign->id, $people);

            foreach ($createdPeople as $person) {
                if ($existingChildren->has($person->id)) {
                    continue;
                }

                $position = $people->search(fn (Person $candidate) => $candidate->is($person));
                $parentPosition = max(0, intdiv(max(0, $position - 1), 4));
                $parent = $people[$parentPosition];
                $parentPath = $paths[$parent->id] ?? (string) $parent->id;
                $path = $parentPath.'.'.$person->id;

                ReferralRelationship::create([
                    'campaign_id' => $campaign->id,
                    'parent_person_id' => $parent->id,
                    'child_person_id' => $person->id,
                    'path' => $path,
                    'depth' => substr_count($path, '.'),
                    'started_at' => $person->created_at,
                    'change_reason' => 'Simulación de red territorial',
                ]);

                $paths[$person->id] = $path;
            }

            $this->createConsents($campaign->id, $createdPeople);
            $this->createTerritorialRoles($campaign->id, $people, $places);
        });
    }

    private function resolvePaths(int $campaignId, $people): array
    {
        $relationships = ReferralRelationship::query()
            ->where('campaign_id', $campaignId)
            ->whereNull('ended_at')
            ->get(['parent_person_id', 'child_person_id', 'path']);
        $paths = [];

        foreach ($people as $person) {
            $relationship = $relationships->firstWhere('child_person_id', $person->id);
            $paths[$person->id] = $relationship?->path ?: (string) $person->id;
        }

        return $paths;
    }

    private function createConsents(int $campaignId, $people): void
    {
        $now = now();
        $rows = $people->map(fn (Person $person) => [
            'campaign_id' => $campaignId,
            'person_id' => $person->id,
            'version' => 'simulation-v1',
            'text_hash' => hash('sha256', 'Consentimiento ficticio para datos de simulación'),
            'channel' => 'simulation',
            'accepted_at' => $person->created_at,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('consents')->insert($chunk);
        }
    }

    private function createTerritorialRoles(int $campaignId, $people, $places): void
    {
        $adminId = DB::table('users')->where('is_super_admin', true)->value('id');
        $now = now();

        foreach ([0, 4, 12, 24, 40, 60, 84] as $position) {
            $person = $people->get($position);

            if (! $person) {
                continue;
            }

            $place = $places[$position % $places->count()];
            DB::table('territorial_roles')->updateOrInsert(
                [
                    'campaign_id' => $campaignId,
                    'person_id' => $person->id,
                    'role' => $position === 0 ? 'municipal_coordinator' : 'commune_coordinator',
                ],
                [
                    'territory_unit_id' => $place->territory_unit_id,
                    'title' => $position === 0 ? 'Coordinación municipal' : "Coordinación territorial {$place->commune}",
                    'status' => 'approved',
                    'approved_by' => $adminId,
                    'approved_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function nameFor(int $sequence): string
    {
        $firstNames = [
            'Andrea', 'Andrés', 'Camila', 'Carlos', 'Carolina', 'Daniel', 'Daniela', 'Diana',
            'Felipe', 'Gabriela', 'Javier', 'Johana', 'José', 'Juan', 'Laura', 'Luisa',
            'María', 'Miguel', 'Natalia', 'Paola', 'Ricardo', 'Sandra', 'Santiago', 'Valentina',
        ];
        $lastNames = [
            'Álvarez', 'Barrera', 'Cárdenas', 'Castro', 'Díaz', 'García', 'Gómez', 'González',
            'Gutiérrez', 'Hernández', 'Jiménez', 'López', 'Martínez', 'Moreno', 'Ortiz',
            'Pérez', 'Ramírez', 'Rodríguez', 'Rojas', 'Romero', 'Sánchez', 'Silva', 'Torres', 'Vargas',
        ];
        $secondLastNames = [
            'Acosta', 'Arias', 'Beltrán', 'Cruz', 'Forero', 'Herrera', 'León', 'Méndez',
            'Molina', 'Navarro', 'Parra', 'Peña', 'Quintero', 'Reyes', 'Rincón', 'Suárez',
        ];

        $first = $firstNames[$sequence % count($firstNames)];
        $last = $lastNames[intdiv($sequence, count($firstNames)) % count($lastNames)];
        $secondLast = $secondLastNames[intdiv($sequence, 7) % count($secondLastNames)];

        return "{$first} {$last} {$secondLast}";
    }
}
