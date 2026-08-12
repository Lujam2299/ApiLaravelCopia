<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AuthenticateAPI
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, $scope)
    {
        if (! $request->user()->tokenCan($scope)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        return $next($request);
    }
}
