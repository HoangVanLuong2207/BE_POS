<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUserRole
{
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        if (!in_array($request->user()->role, $roles)) {
            return response()->json(['message' => 'Bạn không có quyền truy cập.'], 403);
        }

        return $next($request);
    }
}
