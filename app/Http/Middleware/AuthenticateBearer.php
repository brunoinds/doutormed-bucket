<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class AuthenticateBearer
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Allow signed URLs to pass through (controller will verify signature)
        $signature = $request->query('signature');
        $expires = $request->query('expires');

        if ($signature && $expires) {
            return $next($request);
        }

        // Otherwise, require bearer token authentication
        $authBearer = env('AUTH_BEARER');


        if (empty($authBearer)) {
            return response()->json([
                'Error' => [
                    'Code' => 'InvalidToken',
                    'Message' => 'AUTH_BEARER is not configured',
                ]
            ], 500);
        }

        $token = $request->header('Authorization');


        Log::info('authBearer: ' . $authBearer);
        Log::info('token: ' . $token);


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
