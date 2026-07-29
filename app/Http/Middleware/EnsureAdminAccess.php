<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 認証済みユーザーが管理者用UIを利用できるか確認します。
 */
final class EnsureAdminAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $user->loadMissing('roles');

        abort_unless($user->canAccessAdmin(), 403);

        return $next($request);
    }
}
