<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\PublicToken;
use App\Models\ReferralRelationship;
use App\Models\VotingPlace;
use App\Models\VotingTable;
use App\Services\ReferralNetworkService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PublicInvitationController extends Controller
{
    public function show(string $token): Response
    {
        $tokenRow = $this->resolveToken($token);

        return Inertia::render('Public/Invitation', [
            'token' => $token,
            'campaign' => [
                'name' => $tokenRow->campaign->name,
                'candidateName' => $tokenRow->campaign->candidate_name,
                'office' => $tokenRow->campaign->office,
                'territory' => $tokenRow->campaign->territory,
                'themeColor' => $tokenRow->campaign->theme_color,
            ],
            'inviter' => $tokenRow->owner?->name,
            'places' => VotingPlace::query()
                ->where('campaign_id', $tokenRow->campaign_id)
                ->when($this->territoryIds($tokenRow), fn ($query, $ids) => $query->whereIn('territory_unit_id', $ids))
                ->orderBy('commune')
                ->orderBy('name')
                ->limit(1000)
                ->get(['id', 'name', 'commune', 'dd', 'mm', 'zz', 'pp', 'tables_count']),
            'consent' => [
                'version' => '2026-07-v1',
                'text' => 'Autorizo de manera previa, expresa e informada el tratamiento de mis datos personales, incluidos los datos sensibles relacionados con participación política, exclusivamente para la gestión de esta campaña, conforme a su política de tratamiento.',
            ],
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $tokenRow = $this->resolveToken($token);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'email' => ['required', 'email', 'max:180'],
            'phone' => ['required', 'string', 'max:30'],
            'document_number' => ['required', 'string', 'max:30'],
            'voting_place_id' => ['required', 'integer'],
            'voting_table_number' => ['required', 'integer', 'min:1'],
            'identity_document' => ['required', 'file', 'mimes:pdf', 'max:5120'],
            'consent_version' => ['required', 'string', 'in:2026-07-v1'],
            'consent_accepted' => ['accepted'],
        ]);

        $normalizedDocument = preg_replace('/\D+/', '', $data['document_number']);
        if ($normalizedDocument === '') {
            throw ValidationException::withMessages(['document_number' => 'La cédula no es válida.']);
        }
        $documentHash = hash_hmac('sha256', $normalizedDocument, config('app.key'));

        $place = VotingPlace::where('campaign_id', $tokenRow->campaign_id)
            ->when($this->territoryIds($tokenRow), fn ($query, $ids) => $query->whereIn('territory_unit_id', $ids))
            ->whereKey($data['voting_place_id'])
            ->firstOrFail();
        $table = VotingTable::where('campaign_id', $tokenRow->campaign_id)
            ->where('voting_place_id', $place->id)
            ->where('number', $data['voting_table_number'])
            ->first();
        if (! $table) {
            throw ValidationException::withMessages(['voting_table_number' => 'La mesa no existe en el puesto seleccionado.']);
        }

        $file = $request->file('identity_document');
        $plainContents = file_get_contents($file->getRealPath());
        if (! str_starts_with($plainContents, '%PDF-')) {
            throw ValidationException::withMessages([
                'identity_document' => 'El archivo no contiene un documento PDF válido.',
            ]);
        }
        $checksum = hash('sha256', $plainContents);
        $storagePath = 'identity-documents/'.$tokenRow->campaign_id.'/'.Str::ulid().'.pdf.enc';

        $person = DB::transaction(function () use (
            $tokenRow,
            $data,
            $normalizedDocument,
            $documentHash,
            $place,
            $table,
            $file,
            $plainContents,
            $checksum,
            $storagePath,
            $request,
        ) {
            $lockedToken = PublicToken::whereKey($tokenRow->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedToken->isUsable(), 410, 'Este enlace ya no está disponible.');

            if (Person::where('campaign_id', $lockedToken->campaign_id)->where('document_hash', $documentHash)->exists()) {
                throw ValidationException::withMessages([
                    'document_number' => 'La persona ya está registrada en esta campaña. Solicita apoyo al equipo territorial.',
                ]);
            }

            $person = Person::create([
                'public_id' => (string) Str::ulid(),
                'campaign_id' => $lockedToken->campaign_id,
                'voting_place_id' => $place->id,
                'voting_table_id' => $table->id,
                'name' => trim($data['name']),
                'search_name' => Str::lower(Str::ascii(trim($data['name']))),
                'email' => Str::lower(trim($data['email'])),
                'phone' => trim($data['phone']),
                'document_number' => $normalizedDocument,
                'document_hash' => $documentHash,
                'document_last_four' => substr($normalizedDocument, -4),
                'status' => 'verified',
                'verified_at' => now(),
            ]);

            $consentText = 'Autorizo de manera previa, expresa e informada el tratamiento de mis datos personales, incluidos los datos sensibles relacionados con participación política, exclusivamente para la gestión de esta campaña, conforme a su política de tratamiento.';
            DB::table('consents')->insert([
                'campaign_id' => $lockedToken->campaign_id,
                'person_id' => $person->id,
                'version' => $data['consent_version'],
                'text_hash' => hash('sha256', $consentText),
                'channel' => 'public_link',
                'accepted_at' => now(),
                'ip_hash' => $request->ip() ? hash_hmac('sha256', $request->ip(), config('app.key')) : null,
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Storage::disk('local')->put($storagePath, Crypt::encryptString($plainContents));
            DB::table('identity_documents')->insert([
                'campaign_id' => $lockedToken->campaign_id,
                'person_id' => $person->id,
                'disk' => 'local',
                'path' => $storagePath,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'mime_type' => 'application/pdf',
                'size' => $file->getSize(),
                'checksum' => $checksum,
                'is_encrypted' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $invitationId = DB::table('referral_invitations')->insertGetId([
                'campaign_id' => $lockedToken->campaign_id,
                'public_token_id' => $lockedToken->id,
                'inviter_person_id' => $lockedToken->owner_person_id,
                'invitee_person_id' => $person->id,
                'status' => 'accepted',
                'accepted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($lockedToken->owner_person_id) {
                $parent = Person::where('campaign_id', $lockedToken->campaign_id)
                    ->whereKey($lockedToken->owner_person_id)
                    ->firstOrFail();
                app(ReferralNetworkService::class)->connect($parent, $person, $invitationId);
            }

            $lockedToken->increment('uses');
            $lockedToken->forceFill(['last_used_at' => now()])->save();

            return $person;
        });

        Audit::record('person.public_registered', $person, [
            'status' => 'verified',
            'voting_place_id' => $place->id,
        ], campaign: $tokenRow->campaign);

        return redirect()->route('public.invitations.show', $token)
            ->with('success', 'Tu información fue registrada y protegida correctamente.');
    }

    private function resolveToken(string $token): PublicToken
    {
        $tokenRow = PublicToken::query()
            ->with(['campaign', 'owner'])
            ->where('token_hash', hash('sha256', $token))
            ->firstOrFail();

        abort_unless($tokenRow->isUsable(), 410, 'Este enlace ya no está disponible.');
        abort_unless(in_array('referrals.create', $tokenRow->abilities ?? [], true), 403);

        return $tokenRow;
    }

    private function territoryIds(PublicToken $token): array
    {
        return collect($token->territorial_scope['territory_unit_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
