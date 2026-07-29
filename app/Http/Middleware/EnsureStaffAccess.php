<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 認証済みユーザーがスタッフ用UIを利用できるか確認します。
 */
final class EnsureStaffAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $user->loadMissing('roles');

        abort_unless(
            $user->status === 'active' && $user->hasRole('staff'),
            403,
        );

        return $next($request);
    }
}
