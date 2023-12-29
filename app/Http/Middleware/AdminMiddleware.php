<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Kreait\Firebase\Factory;
use Kreait\Laravel\Firebase\Facades\Firebase;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    protected $auth, $rtdb, $firestore;
    public function __construct()
    {
        $this->auth = Firebase::auth();

        $firebase = (new Factory)
            ->withServiceAccount(base_path(env('FIREBASE_CREDENTIALS')));

        $this->rtdb = $firebase->withDatabaseUri(env("FIREBASE_DATABASE_URL"))
            ->createDatabase();

        $this->firestore = $firebase->createFirestore()
            ->database();
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = (object) [];
        $token = $request->bearerToken();
        $response->token = $token;

        $auth = app('firebase.auth');
        try {
            $verifiedIdToken = $auth->verifyIdToken($token);
        } catch (FailedToVerifyToken $e) {
            return response()->json([
                'message' => 'invalid token',
                'error' => $e->getMessage()
            ]);
        }
        $email = $verifiedIdToken->claims()->get('email');

        $admins = $this->firestore->collection('admins')
        ->where('email', '==', $email)
        ->count();

        if ($admins) {
            return $next($request);
        }

        return response()->json([
            'message' => 'unauthorized',
        ]);
    }
}
