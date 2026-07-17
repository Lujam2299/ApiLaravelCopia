<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (config("modules.disabled.{$module}", false)) {
            return response()->json([
                'message' => 'Módulo deshabilitado por indicación operativa.',
            ], 403);
        }

        return $next($request);
    }
}
