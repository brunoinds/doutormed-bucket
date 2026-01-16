<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateBearer
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authBearer = env('AUTH_BEARER');

        if (empty($authBearer)) {
            return response()->json([
                'Error' => [
                    'Code' => 'InvalidToken',
                    'Message' => 'AUTH_BEARER is not configured',
                ]
            ], 500);
        }

        $token = $request->bearerToken();

        if (!$token || $token !== $authBearer) {
            return response()->json([
                'Error' => [
                    'Code' => 'InvalidToken',
                    'Message' => 'The provided token is invalid',
                ]
            ], 403);
        }

        return $next($request);
    }
}
