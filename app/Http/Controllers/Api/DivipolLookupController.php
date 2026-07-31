<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PublicToken;
use App\Models\VotingPlace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DivipolLookupController extends Controller
{
    public function __invoke(Request $request, VotingPlace $place): JsonResponse
    {
        $request->validate(['token' => ['required', 'string', 'max:255']]);
        $token = PublicToken::where('token_hash', hash('sha256', $request->string('token')->toString()))->firstOrFail();
        abort_unless($token->isUsable() && $token->campaign_id === $place->campaign_id, 403);

        return response()->json([
            'data' => $place->tables()->orderBy('number')->get(['id', 'number', 'census']),
        ]);
    }
}
