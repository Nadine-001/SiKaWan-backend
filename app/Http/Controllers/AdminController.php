<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Google\Cloud\Core\Timestamp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Kreait\Firebase\Factory;
use Kreait\Laravel\Firebase\Facades\Firebase;

class AdminController extends Controller
{
    protected $auth, $rtdb, $firestore, $googleMaps;
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
            // $GOOGLE_API_KEY = 'AIzaSyCq5KQ9guAzQHQGUq0wfGJqt3ud2ZBgzNo';

            $presence_list = [];
            foreach ($documents as $document) {
                // $date_now = Carbon::parse(date(now()));
                // $date_now->locale('id');
                // $date_now = $date_now->isoFormat('D');

                // $date = Carbon::parse($document->get('entry_time'))->format('Y-m-d');
                // dd($date);

                $this_date = Carbon::parse($document->get('entry_time'));
                $this_date->locale('id');
                // $now_date = $this_date->isoFormat('D');

                // if ($now_date != $date_now) {
                $name = $document->get('name');

                $entry_time = Carbon::parse($document->get('entry_time'));
                $exit_time = $document->get('exit_time');

                if ($exit_time == null) {
                    $exit_time = "-";
                } else {
                    $exit_time = Carbon::parse($document->get('exit_time'))->format('H:i:s A');
                }

                $status = $document->get('status');

                // $entry_location = $document->get('entry_location');
                // $longitude = $entry_location->longitude();
                // $latitude = $entry_location->latitude();
                // $formatted_latlng = trim($latitude) . ',' . trim($longitude);
                // $geocodeFromLatLng = file_get_contents("https://maps.googleapis.com/maps/api/geocode/json?latlng={$formatted_latlng}&key={$GOOGLE_API_KEY}");
                // $apiResponse = json_decode($geocodeFromLatLng);
                // $location = $apiResponse->results[1]->formatted_address;

                $presence_list[] = [
                    'sort_date' => $this_date->format('Y-m-d'),
                    'name' => $name,
                    'date' => $this_date->isoFormat('D MMMM YYYY'),
                    'entry_time' => $entry_time->format('H:i:s A'),
                    'exit_time' => $exit_time,
                    // 'location' => $location,
                    'status' => $status,
                ];
                // }
            }

            usort($presence_list, [$this, "compareDates"]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'fetch data from database failed',
                'errors' => $th->getMessage()
            ], 400);
        }

        return response()->json($presence_list);
    }

    public function full_time_add(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'entry_time' => 'required',
            'exit_time' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 406);
        }

        try {
            $this->rtdb->getReference('/full_time')->update([
                'entry_time' => $request->entry_time,
                'exit_time' => $request->exit_time
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'add time failed',
                'errors' => $th->getMessage()
            ], 400);
        }

        return response()->json('success add time');
    }

    public function part_time_add(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'entry_time' => 'required',
            'exit_time' => 'required',
            'name' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 406);
        }

        try {
            $count = $this->rtdb->getReference('/part_time/')->getSnapshot()->numChildren();
            $category = chr(ord('A') + $count);

            $this->rtdb->getReference('/part_time/' . $category)->update([
                'entry_time' => $request->entry_time,
                'exit_time' => $request->exit_time
            ]);

            $names = explode(',', $request->name);

            $uids = [];
            foreach ($names as $name) {
                $users = $this->firestore->collection('users')
                    ->where('name', '=', $name)
                    ->documents();

                foreach ($users as $user) {
                    $uid = $user->id();
                    $uids[] = $uid;
                }
            }

            $part_timer = $this->firestore->collection('part_timer')
                ->document($category);

            $part_timer->set([
                'uid' => $uids
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'add category failed',
                'errors' => $th->getMessage()
            ], 400);
        }

        return response()->json('success add category');
    }

    public function part_time_update(Request $request, $category)
    {
        $validator = Validator::make($request->all(), [
            'entry_time' => 'required',
            'exit_time' => 'required',
            'name' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 406);
        }

        try {
            $this->rtdb->getReference('/part_time/' . $category)->update([
                'entry_time' => $request->entry_time,
                'exit_time' => $request->exit_time
            ]);

            $names = explode(',', $request->name);

            $uids = [];
            foreach ($names as $name) {
                $users = $this->firestore->collection('users')
                    ->where('name', '=', $name)
                    ->documents();

                foreach ($users as $user) {
                    $uid = $user->id();
                    $uids[] = $uid;
                }
            }

            $part_timer = $this->firestore->collection('part_timer')
                ->document($category);

            $part_timer->set([
                'uid' => $uids
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'update category failed',
                'errors' => $th->getMessage()
            ], 400);
        }

        return response()->json('success update category');
    }

    public function part_time_delete(Request $request, $category)
    {
        try {
            $this->rtdb->getReference('/part_time/' . $category)->remove();

            $this->firestore->collection('part_timer')
                ->document($category)
                ->delete();
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'delete category failed',
                'errors' => $th->getMessage()
            ], 400);
        }

        return response()->json('success delete category');
    }

    private function compareDates($a, $b)
    {
        return strtotime($b['sort_date']) - strtotime($a['sort_date']);
    }

    public function getUid(Request $request)
    {
        $token = $request->bearerToken();
        $verifiedIdToken = $this->auth->verifyIdToken($token);
        $uid = $verifiedIdToken->claims()->get('sub');

        return $uid;
    }
}
