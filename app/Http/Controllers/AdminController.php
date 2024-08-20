<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
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
            date_default_timezone_set('Asia/Jakarta');

            $startOfMonth = Carbon::createFromFormat('Y-m-d', date('Y') . '-' . date('m') . "-01");
            $endOfMonth = Carbon::today();
            $period = CarbonPeriod::create($startOfMonth, $endOfMonth);

            $user_dates = [];

            foreach ($documents as $document) {
                $name = $document->get('name');
                $date = Carbon::parse($document->get('entry_time'))->format('Y-m-d');

                if (!isset($user_dates[$name])) {
                    $user_dates[$name] = [];
                }

                $user_dates[$name][] = $date;
            }

            $missing_dates_per_user = [];

            foreach ($user_dates as $name => $dates) {
                $active_employees = $this->rtdb->getReference('/active_employee')->getValue();

                if (in_array($name, $active_employees)) {
                    $missing_dates = [];

                    foreach ($period as $date) {
                        if (($date->isWeekday() || $date->isSaturday()) && !in_array($date->format('Y-m-d'), $dates)) {
                            $missing_dates[] = $date->format('Y-m-d');

                            $uid = $this->firestore->collection('users')
                                ->where('name', '=', $name)
                                ->limit(1)
                                ->documents()
                                ->rows()[0]
                                ->id();

                            $absence = $this->firestore->collection('presence_history')->document($name . '-' . $date->format('jnY'));

                            $absence->set([
                                'uid' => $uid,
                                'name' => $name,
                                'day' => intval($date->format('l')),
                                'date' => intval($date->format('j')),
                                'month' => intval($date->format('n')),
                                'year' => intval($date->format('Y')),
                                'entry_time' => null,
                                'exit_time' => null,
                                'entry_location' => null,
                                'exit_location' => null,
                                'arrival_location' => null,
                                'departure_location' => null,
                                'status' => 'TIDAK ABSEN',
                            ]);
                        }
                    }

                    $missing_dates_per_user[] = [
                        'name' => $name,
                        'missing_dates' => $missing_dates,
                    ];
                }
            }

            // $date = Carbon::parse($document->get('entry_time'))->format('Y-m-d');

            // $now_date = $this_date->isoFormat('D');

            // if ($now_date != $date_now) {
            $documents =  $presence_history->documents();

            $presence_list = [];
            foreach ($documents as $document) {
                $name = $document->get('name');

                $entry_time = $document->get('entry_time');

                if ($entry_time == null) $entry_time = "-";
                else $entry_time = Carbon::parse($document->get('entry_time'))->format('H:i:s');

                $exit_time = $document->get('exit_time');

                if ($exit_time == null) $exit_time = "-";
                else $exit_time = Carbon::parse($document->get('exit_time'))->format('H:i:s');

                $status = $document->get('status');

                $arrival_location = $document->get('arrival_location');
                if ($arrival_location == null) $arrival_location = '-';

                $departure_location = $document->get('departure_location');

                if ($departure_location == null) $departure_location = '-';

                $date = $document->get('date');
                $month = $document->get('month');
                $year = $document->get('year');

                $month_name = Carbon::create()->month($month)->format('F');

                $presence_list[] = [
                    'sort_date' => $date . '-' . $month . '-' . $year,
                    'name' => $name,
                    'date' => Carbon::parse("$date $month_name $year")->format('d F Y'),
                    'entry_time' => $entry_time,
                    'exit_time' => $exit_time,
                    'arrival_location' => $arrival_location,
                    'departure_location' => $departure_location,
                    'status' => $status,
                ];
            }
            // }

            usort($presence_list, [$this, "compareDates"]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'fetch data from database failed',
                'errors' => $th->getMessage()
            ], 400);
        }

        return response()->json($presence_list);
    }

    public function work_time_add(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'entry_time' => 'required',
            'exit_time' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 406);
        }

        try {
            $this->rtdb->getReference('/work_time')->update([
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

    public function active_employee_add(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'names' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 406);
        }

        try {
            $names = $request->names;
            $employees = [];

            foreach ($names as $key => $name) {
                $employees["employee_{$key}"] = $name;
            }

            $this->rtdb->getReference('/active_employee')->update($employees);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'add employee failed',
                'errors' => $th->getMessage()
            ], 400);
        }

        return response()->json('success add employee');
    }

    public function translate_geolocation()
    {
        $presence_history = $this->firestore->collection('presence_history');

        try {
            $documents =  $presence_history->documents();

            foreach ($documents as $document) {
                $data = $document->data();

                if (!array_key_exists('departure_location', $data)) {
                    // if (!array_key_exists('arrival_location', $data)) {
                    $exit_location = $document->get('exit_location');
                    // $entry_location = $document->get('entry_location');

                    if ($exit_location) {
                    // if ($entry_location) {
                        $longitude = $exit_location->longitude();
                        $latitude = $exit_location->latitude();
                        // $longitude = $entry_location->longitude();
                        // $latitude = $entry_location->latitude();

                        $GOOGLE_API_KEY = 'AIzaSyCq5KQ9guAzQHQGUq0wfGJqt3ud2ZBgzNo';

                        $format_lat_long = trim($latitude) . ',' . trim($longitude);
                        $geocode = file_get_contents("https://maps.googleapis.com/maps/api/geocode/json?latlng={$format_lat_long}&key={$GOOGLE_API_KEY}");
                        $geocode_decode = json_decode($geocode);
                        $location = $geocode_decode->results[1]->formatted_address;

                        if (explode(',', $location)[0] == 'W93Q+2R8') $location = 'ATNAVA Coffee & Space';
                    } else {
                        $location = null;
                    }

                    $document_id = $document->reference();
                    $document_id->set([
                        'departure_location' => $location
                        // 'arrival_location' => $location
                    ], ['merge' => true]);
                } else {
                    continue;
                }
            }
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'translate geolocation failed',
                'errors' => $th->getMessage(),
            ], 400);
        }

        return response()->json([
            'message' => 'OK'
        ]);
    }

    private function compareDates($a, $b)
    {
        return strtotime($b['sort_date']) - strtotime($a['sort_date']);
    }

    public function getUid(Request $request)
    {
        try {
            $token = $request->bearerToken();
            $verifiedIdToken = $this->auth->verifyIdToken($token, true);
            $uid = $verifiedIdToken->claims()->get('sub');
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'The Firebase ID token has been revoked',
                'errors' => $th->getMessage()
            ], 401);
        }

        return $uid;
    }
}
