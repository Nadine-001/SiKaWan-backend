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
            'division' => 'required',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 406);
        }

        $name = $request->name;
        $email = $request->email;
        $password = $request->password;
        $position = $request->position;
        $division = $request->division;

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
                'position' => $position,
                'division' => $division,
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

    public function upload_photo(Request $request)
    {
        $uid = $this->getUid($request);

        try {
            $image = $request->file('image');

            $firebase_storage_path = 'ProfilePhoto/';
            $localfolder = public_path('firebase-temp-uploads') . '/';

            $users = $this->firestore->collection('users')->document($uid);

            $name = $users->snapshot()->get('name');
            $file = $name . '.jpg';

            $image->move($localfolder, $file);
            $uploadedfile = fopen($localfolder . $file, 'r');
            app('firebase.storage')->getBucket()->upload($uploadedfile, ['name' => $firebase_storage_path . $file]);
            unlink($localfolder . $file);

            $users->update([
                ['path' => 'image', 'value' => $firebase_storage_path . $file]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'failed to upload profile photo',
                'errors' => $th->getMessage()
            ], 400);
        }

        return response()->json('upload profile photo success');
    }

    public function get_photo(Request $request)
    {
        $uid = $this->getUid($request);

        $imagePath = $this->firestore->collection('users')
            ->document($uid)
            ->snapshot()
            ->get('image');

        try {
            if (!$imagePath) {
                return response()->json('profile photo not found', 404);
            }

            $name = $this->firestore->collection('users')
                ->document($uid)
                ->snapshot()
                ->get('name');

            $expiresAt = new \DateTime('tomorrow');

            $imageReference = app('firebase.storage')->getBucket()->object('ProfilePhoto/' . $name . '.jpg');
            if ($imageReference->exists())
                $image = $imageReference->signedUrl($expiresAt);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'failed to get profile photo',
                'errors' => $th->getMessage()
            ], 400);
        }

        return response()->json($image);
    }

    public function delete_photo(Request $request)
    {
        $uid = $this->getUid($request);

        try {
            $users = $this->firestore->collection('users')->document($uid);

            $name = $users->snapshot()->get('name');

            app('firebase.storage')->getBucket()->object('ProfilePhoto/' . $name . '.jpg')->delete();

            $users->update([
                ['path' => 'image', 'value' => null]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'failed to delete profile photo',
                'errors' => $th->getMessage()
            ], 400);
        }

        return response()->json('delete profile photo success');
    }

    public function profile(Request $request)
    {
        $uid = $this->getUid($request);

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
            $division = $user->get('division');
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
            'division' => $division,
        ]);
    }

    public function division(Request $request)
    {
        $uid = $this->getUid($request);

        try {
            $user = $this->firestore->collection('users')
                ->document($uid)
                ->snapshot();

            if (!$user->exists()) {
                return response()->json([
                    'message' => 'user not found',
                ], 404);
            }

            $division_name = $user->get('division');

            $division = true;
            if ($division_name == 'Food $ Beverage') {
                $division = false;
            }
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'failed to get user data',
                'errors' => $th->getMessage()
            ], 400);
        }

        return response()->json([
            'division' => $division,
        ]);
    }

    public function logout(Request $request)
    {
        try {
            $this->auth->revokeRefreshTokens($this->getUid($request));
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

    public function getUid(Request $request)
    {
        $token = $request->bearerToken();
        $verifiedIdToken = $this->auth->verifyIdToken($token);
        $uid = $verifiedIdToken->claims()->get('sub');

        return $uid;
    }
}
