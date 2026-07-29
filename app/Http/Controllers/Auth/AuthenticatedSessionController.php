<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Auth\AuthenticatedRedirectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class AuthenticatedSessionController extends Controller
{
    public function __construct(
        private readonly AuthenticatedRedirectService $redirectService,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            return redirect()->to($this->redirectService->destination($user));
        }

        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();

        /** @var User $user */
        $user = $request->user();

        return redirect()->intended(
            $this->redirectService->destination($user),
        );
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function unavailable(): View
    {
        return view('auth.access-unavailable');
    }
}
