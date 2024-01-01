<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Kreait\Firebase\Factory;
use Kreait\Laravel\Firebase\Facades\Firebase;
use Symfony\Component\HttpFoundation\Response;

class FirebaseMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        $auth = app('firebase.auth');
        try {
            if ($token == null) {
                throw new FailedToVerifyToken('token can not be null');
            }

            $verifiedIdToken = $auth->verifyIdToken($token);
            return $next($request);
        } catch (FailedToVerifyToken $e) {
            return response()->json([
                'message' => 'invalid token',
                'error' => $e->getMessage()
            ]);
        }
    }
}
