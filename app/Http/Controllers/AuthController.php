<?php

namespace App\Http\Controllers;

use App\Models\CampaignMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt([...$credentials, 'is_active' => true], $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'Las credenciales no son correctas.'])->onlyInput('email');
        }

        $request->session()->regenerate();

        $membership = CampaignMembership::with(['role', 'user'])
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->whereHas('campaign', fn ($campaign) => $campaign->where('status', 'active'))
            ->orderBy('id')
            ->first();
        abort_unless($membership, 403, 'Tu cuenta no tiene una campaña activa asignada.');
        $request->session()->put('campaign_id', $membership->campaign_id);
        $destination = $membership->can('dashboard.view')
            ? route('dashboard')
            : ($membership->can('driver.routes.view') ? route('driver.routes') : route('admin.audit.index'));

        return redirect()->intended($destination);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
