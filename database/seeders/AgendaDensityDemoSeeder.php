<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\CampaignMembership;
use App\Models\Meeting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

class AgendaDensityDemoSeeder extends Seeder
{
    public function run(): void
    {
        $campaign = Campaign::query()
            ->where('slug', 'villavicencio-2027')
            ->first()
            ?? Campaign::query()->where('status', 'active')->orderBy('id')->first();

        if (! $campaign) {
            throw new RuntimeException('No existe una campaña activa para crear la agenda de evaluación.');
        }

        $requesterId = CampaignMembership::query()
            ->where('campaign_id', $campaign->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->value('user_id');

        if (! $requesterId) {
            throw new RuntimeException('La campaña no tiene un usuario activo que pueda solicitar actividades.');
        }

        $activities = [
            ['05:30', '06:00', 'Revisión operativa de primera hora', 'Sede central de campaña'],
            ['06:05', '06:35', 'Encuentro con líderes juveniles', 'Parque Los Fundadores'],
            ['06:40', '07:10', 'Reunión con equipo de comunicaciones', 'Sede central de campaña'],
            ['07:15', '07:45', 'Visita a medios comunitarios', 'Barrio Siete de Agosto'],
            ['09:40', '10:10', 'Mesa de trabajo con mujeres emprendedoras', 'Cámara de Comercio de Villavicencio'],
            ['10:20', '10:50', 'Reunión con coordinadores de comuna', 'Sede central de campaña'],
            ['11:00', '11:30', 'Encuentro con representantes deportivos', 'Villa Olímpica'],
            ['11:40', '12:10', 'Visita a comerciantes del centro', 'Plaza Los Libertadores'],
            ['12:20', '12:50', 'Almuerzo de trabajo con líderes sociales', 'Centro de Villavicencio'],
            ['13:00', '13:30', 'Reunión de seguimiento territorial', 'Sede central de campaña'],
            ['13:40', '14:10', 'Encuentro con sector cultural', 'Biblioteca Germán Arciniegas'],
            ['14:20', '14:50', 'Visita territorial a la Comuna 5', 'Comuna 5'],
            ['15:00', '15:30', 'Mesa técnica de logística electoral', 'Sede central de campaña'],
            ['15:40', '16:10', 'Encuentro con líderes rurales', 'Sede central de campaña'],
            ['16:20', '16:50', 'Cierre y evaluación de agenda territorial', 'Sede central de campaña'],
        ];

        foreach ($activities as $index => [$from, $to, $title, $location]) {
            $startsAt = Carbon::parse("2026-07-27 {$from}", $campaign->timezone);
            $endsAt = Carbon::parse("2026-07-27 {$to}", $campaign->timezone);

            Meeting::firstOrCreate(
                [
                    'campaign_id' => $campaign->id,
                    'title' => $title,
                    'starts_at' => $startsAt,
                ],
                [
                    'public_id' => (string) Str::ulid(),
                    'requested_by' => $requesterId,
                    'type' => str_contains($title, 'Visita') ? 'visita' : 'reunion',
                    'objective' => 'Actividad demostrativa para evaluar la visualización y aprobación de una jornada de alta densidad.',
                    'location' => $location,
                    'address' => $location.', Villavicencio, Meta',
                    'latitude' => 4.1420 + (($index % 3) * 0.0004),
                    'longitude' => -73.6266 + (($index % 3) * 0.0004),
                    'location_notes' => 'Actividad de evaluación de densidad de agenda.',
                    'ends_at' => $endsAt,
                    'expected_attendees' => 20 + ($index * 5),
                    'status' => 'requested',
                ],
            );
        }

        $this->command?->info('15 actividades de evaluación disponibles el 27 de julio de 2026.');
    }
}
