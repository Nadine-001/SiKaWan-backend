<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Kreait\Firebase\Factory;
use Kreait\Laravel\Firebase\Facades\Firebase;

class AdminController extends Controller
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
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 406);
        }

        $name = $request->name;
        $email = $request->email;
        $password = $request->password;

        try {
            $new_admin = $this->auth->createUserWithEmailAndPassword($email, $password);
            $uid = $new_admin->uid;
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'registration failed',
                'errors' => $th->getMessage()
            ], 400);
        }

        try {
            $admin = $this->firestore->collection('admins')->document($uid);

            $admin->set([
                'name' => $name,
                'email' => $email,
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

        $admins = $this->firestore->collection('admins')
            ->where('email', '==', $email)
            ->count();

        if ($admins) {
            $password = $request->password;

            try {
                $admin = $this->auth->signInWithEmailAndPassword($email, $password);

                $uid = $admin->firebaseUserId();
                $token = $admin->idToken();
            } catch (\Throwable $th) {
                return response()->json([
                    'message' => 'login failed',
                    'errors' => $th->getMessage()
                ], 401);
            }
        } else {
            return response()->json('unauthorized', 401);
        }

        return response()->json([
            'email' => $email,
            'UID' => $uid,
            'token' => $token,
        ]);
    }

    public function profile(Request $request)
    {
        $uid = $this->getUid($request);

        try {
            $admin = $this->firestore->collection('admins')
                ->document($uid)
                ->snapshot();

            if (!$admin->exists()) {
                return response()->json([
                    'message' => 'admin not found',
                ], 404);
            }

            $email = $admin->get('email');
            $name = $admin->get('name');
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'failed to get admin data',
                'errors' => $th->getMessage()
            ], 400);
        }

        return response()->json([
            'UID' => $uid,
            'email' => $email,
            'name' => $name,
        ]);
    }

    public function logout(Request $request)
    {
        $uid = $this->getUid($request);

        try {
            $this->auth->revokeRefreshTokens($uid);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'logout failed',
                'errors' => $th->getMessage()
            ], 401);
        }

        return response()->json('logout success');
    }

    public function dashboard(Request $request)
    {
        $presence_history = $this->firestore->collection('presence_history');

        if ($request->name) {
            $presence_history = $presence_history->where('name', '=', $request->name);
        }

        if ($request->status) {
            $presence_history = $presence_history->where('status', '=', $request->status);
        }

        if ($request->month) {
            $presence_history = $presence_history->where('month', '=', $request->month);
        }

        $documents =  $presence_history->documents();

        try {
            $presence_list = [];
            foreach ($documents as $document) {
                $name = $document->get('name');
                $date = Carbon::parse($document->get('entry_time'));
                $entry_time = Carbon::parse($document->get('entry_time'));
                $exit_time = Carbon::parse($document->get('exit_time'));
                $status = $document->get('status');

                $presence_list[] = [
                    'name' => $name,
                    'date' => $date->format('j F Y'),
                    'entry_time' => $entry_time->format('H:i:s A'),
                    'exit_time' => $exit_time->format('H:i:s A'),
                    'status' => $status,
                ];
            }
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'fetch data from database failed',
                'errors' => $th->getMessage()
            ], 400);
        }

        return response()->json($presence_list);
    }

    public function getUid(Request $request)
    {
        $token = $request->bearerToken();
        $verifiedIdToken = $this->auth->verifyIdToken($token);
        $uid = $verifiedIdToken->claims()->get('sub');

        return $uid;
    }
}
