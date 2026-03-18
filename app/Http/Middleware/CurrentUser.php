<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CurrentUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->session()->get('user_id');

        if ($userId) {
            $user = User::find($userId);

            if ($user) {
                $request->setUserResolver(fn () => $user);
                return $next($request);
            }

            $request->session()->forget('user_id');
        }

        if ($request->expectsJson()) {
            return response()->json([
                'data' => null,
                'error' => 'No active rider selected',
            ], 401);
        }

        return redirect('/select-user');
    }
}
