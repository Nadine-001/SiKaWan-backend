<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Kreait\Firebase\Factory;
use Kreait\Laravel\Firebase\Facades\Firebase;

class AuthController extends Controller
{
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

    public function sign_up(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'email' => 'required',
            'position' => 'required',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 406);
        }

        $name = $request->name;
        $email = $request->email;
        $password = $request->password;

        try {
            $new_user = $this->auth->createUserWithEmailAndPassword($email, $password);
            $uid = $new_user->uid;
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'registration failed',
                'errors' => $th->getMessage()
            ], 400);
        }

        try {
            $user = $this->firestore->collection('users')->document($uid);

            $user->set([
                'id_card' => null,
                'name' => $name,
                'email' => $email,
                'position' => $request->position,
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'insert data to database failed',
                'errors' => $th->getMessage()
            ], 400);
        }

        return response()->json([
            'name' => $name,
            'email' => $email,
            // 'token' => $token,
            'UID' => $uid,
        ]);
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 406);
        }

        $email = $request->email;
        $password = $request->password;

        try {
            $user = $this->auth->signInWithEmailAndPassword($email, $password);

            $uid = $user->firebaseUserId();
            $token = $user->idToken();

            $request->session()->start();
            $request->session()->put('uid', $uid);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'login failed',
                'errors' => $th->getMessage()
            ], 401);
        }

        return response()->json([
            'email' => $email,
            'UID' => $uid,
            'token' => $token,
        ]);
    }

    public function profile(Request $request)
    {
        $uid = $request->session()->get('uid');

        try {
            $user = $this->firestore->collection('users')
                ->document($uid)
                ->snapshot();

            if (!$user->exists()) {
                return response()->json([
                    'message' => 'user not found',
                ], 404);
            }

            $email = $user->get('email');
            $name = $user->get('name');
            $position = $user->get('position');
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'failed to get user data',
                'errors' => $th->getMessage()
            ], 400);
        }

        return response()->json([
            'UID' => $uid,
            'email' => $email,
            'name' => $name,
            'position' => $position,
        ]);
    }

    public function logout(Request $request)
    {
        $uid = $request->session()->get('uid');

        try {
            $this->auth->revokeRefreshTokens($uid);
            $request->session()->forget('uid');
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'logout failed',
                'errors' => $th->getMessage()
            ], 401);
        }

        return response()->json('logout success');
    }

    public function forgot_password(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 406);
        }

        $email = $request->email;

        try {
            $this->auth->sendPasswordResetLink($email);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'logout failed',
                'errors' => $th->getMessage()
            ], 401);
        }

        return response()->json('email sent');
    }
}
