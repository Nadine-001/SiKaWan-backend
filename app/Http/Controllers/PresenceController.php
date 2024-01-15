<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Google\Cloud\Core\GeoPoint;
use Google\Cloud\Core\Timestamp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Kreait\Firebase\Factory;
use Kreait\Laravel\Firebase\Facades\Firebase;

class PresenceController extends Controller
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

    public function entry(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'day' => 'required',
            'date' => 'required',
            'month' => 'required',
            'year' => 'required',
            'time' => 'required',
            'latitude' => 'required',
            'longitude' => 'required',
            'status' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 406);
        }

        $uid = $this->getUid($request);

        try {
            $name = $this->firestore->collection('users')
                ->document($uid)
                ->snapshot()
                ->get('name');

            $date = $request->date;
            $month = $request->month;
            $year = $request->year;
            $time = $request->time;
            $latitude = $request->latitude;
            $longitude = $request->longitude;

            $entry_time = new Timestamp(new \DateTime($date . '-' . $month . '-' . $year . ' ' . $time));
            $entry_location = new GeoPoint($latitude, $longitude);

            $entry = $this->firestore->collection('presence_history')->document($name . '-' . $date . $month . $year);

            $entry->set([
                'uid' => $uid,
                'name' => $name,
                'day' => $request->day,
                'date' => $date,
                'month' => $month,
                'year' => $year,
                'entry_time' => $entry_time,
                'exit_time' => null,
                'entry_note' => $request->entry_note,
                'exit_note' => null,
                'entry_location' => $entry_location,
                'exit_location' => null,
                'status' => $request->status,
                'button_state' => true
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'insert data to database failed',
                'errors' => $th->getMessage()
            ], 400);
        }

        return response()->json([
            'button_state' => true
        ]);
    }

    public function exit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date' => 'required',
            'month' => 'required',
            'year' => 'required',
            'time' => 'required',
            'latitude' => 'required',
            'longitude' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 406);
        }

        $uid = $this->getUid($request);

        try {
            $name = $this->firestore->collection('users')
                ->document($uid)
                ->snapshot()
                ->get('name');

            $date = $request->date;
            $month = $request->month;
            $year = $request->year;
            $time = $request->time;
            $latitude = $request->latitude;
            $longitude = $request->longitude;

            $exit_time = new Timestamp(new \DateTime($date . '-' . $month . '-' . $year . ' ' . $time));
            $exit_location = new GeoPoint($latitude, $longitude);

            $exit = $this->firestore->collection('presence_history')->document($name . '-' . $date . $month . $year);

            $exit->update([
                ['path' => 'exit_time', 'value' => $exit_time],
                ['path' => 'exit_note', 'value' => $request->exit_note],
                ['path' => 'exit_location', 'value' => $exit_location],
                ['path' => 'button_state', 'value' => false]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'insert data to database failed',
                'errors' => $th->getMessage()
            ], 400);
        }

        return response()->json([
            'button_state' => false
        ]);
    }

    public function door_access(Request $request, $id_card)
    {
        $work_time = strtotime("10:00");
        $exit_time_should_be = strtotime("18:00");
        $timestamp = strtotime($request->timestamp);

        $id_card = intval($id_card);
        $users = $this->firestore->collection('users')->where('id_card', '==', $id_card);

        if ($users->count()) {
            $documents = $users->documents();

            foreach ($documents as $document) {
                $uid = $document->id();
                $name = $document->get('name');
            }

            $date = intval(date('j', $timestamp));
            $month = intval(date('n', $timestamp));
            $year = intval(date('Y', $timestamp));

            $presence_history = $this->firestore->collection('presence_history')
                ->where('uid', '==', $uid)
                ->where('date', '==', $date)
                ->where('month', '==', $month)
                ->where('year', '==', $year);

            $documents = $presence_history->documents();

            foreach ($documents as $document) {
                $exit_time_should_be = Carbon::parse($document->get('exit_time_should_be'))->format('H:i:s');
            }

            if (!$presence_history->count()) {
                $day = date('l', $timestamp);
                $latitude = -7.0968667;
                $longitude = 110.3897417;

                $entry_time = new \DateTime($request->timestamp);
                $entry_location = new GeoPoint($latitude, $longitude);

                $status = "Tepat Waktu";
                if (strtotime(date("H:i", $timestamp)) > $work_time) {
                    $status = "Terlambat";

                    $minutes = date("i", $timestamp);
                    $exit_time_should_be = strtotime("+$minutes minutes", $exit_time_should_be);
                }

                $entry = $this->firestore->collection('presence_history')->document($name . '-' . date("jnY", $timestamp));

                $entry->set([
                    'uid' => $uid,
                    'name' => $name,
                    'day' => $day,
                    'date' => $date,
                    'month' => $month,
                    'year' => $year,
                    'entry_time' => $entry_time,
                    'exit_time' => null,
                    'entry_note' => null,
                    'exit_note' => null,
                    'entry_location' => $entry_location,
                    'exit_location' => null,
                    'status' => $status,
                    'exit_time_should_be' => new Timestamp(new \DateTime(date("j-n-Y", $timestamp) . ' ' . date("H:i", $exit_time_should_be)))
                ]);
            } else if (strtotime(date("H:i", $timestamp)) >= strtotime($exit_time_should_be)) {
                $latitude = -7.0968667;
                $longitude = 110.3897417;

                $exit_time = new \DateTime($request->timestamp);
                $exit_location = new GeoPoint($latitude, $longitude);

                $exit = $this->firestore->collection('presence_history')->document($name . '-' . $date . $month . $year);

                $exit->update([
                    ['path' => 'exit_time', 'value' => $exit_time],
                    ['path' => 'exit_note', 'value' => null],
                    ['path' => 'exit_location', 'value' => $exit_location],
                ]);

                return response()->json('Sampai jumpa besok, ' . $name . '!');
            } else {
                return response()->json('Silakan masuk, ' . $name);
            }
        } else {
            return response()->json('Akses ditolak. ID Kartu tidak ditemukan.', 404);
        }

        return response()->json('Selamat datang, ' . $name . '! Jam pulang Anda : ' . date("H:i", $exit_time_should_be));
    }

    public function history(Request $request)
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

            $name = $user->get('name');
            $position = $user->get('position');

            $history = $this->firestore->collection('presence_history')
                ->where('uid', '==', $uid);

            $documents = $history->documents();

            $history_list = [];
            foreach ($documents as $document) {
                $created_date = Carbon::createFromDate(
                    $document->get('year'),
                    $document->get('month'),
                    $document->get('date')
                );

                $day_date = $created_date->format('l, j F Y');

                $entry_time = Carbon::parse($document->get('entry_time'));

                if ($document->get('exit_time')) {;
                    $exit_time = Carbon::parse($document->get('exit_time'))->format('H:i:s');
                } else {
                    $exit_time = '';
                }

                $status = $document->get('status');

                $history_list[] = [
                    'day_date' => $day_date,
                    'entry_time' => $entry_time->format('H:i:s'),
                    'exit_time' => $exit_time,
                    'status' => $status,
                ];
            }
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'fetch data from database failed',
                'errors' => $th->getMessage()
            ], 400);
        }

        return response()->json([
            'name' => $name,
            'position' => $position,
            'history_list' => $history_list,
        ]);
    }

    public function statistic(Request $request)
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

            $name = $user->get('name');
            $position = $user->get('position');

            $presence_history = $this->firestore->collection('presence_history')
                ->where('uid', '==', $uid);

            $presence_day = $presence_history->where('month', '==', intval(date('n')))
                ->where('year', '==', intval(date('Y')))
                ->count();

            if ($presence_day) {
                $on_time_day = $presence_history->where('status', '==', 'Tepat Waktu')
                    ->where('month', '==', intval(date('n')))
                    ->where('year', '==', intval(date('Y')))
                    ->count();


                $late_day = $presence_history->where('status', '==', 'Terlambat')
                    ->where('month', '==', intval(date('n')))
                    ->where('year', '==', intval(date('Y')))
                    ->count();


                $on_time_percent = $on_time_day / ($on_time_day + $late_day) * 100;
                $late_percent = $late_day / ($on_time_day + $late_day) * 100;
            } else {
                $on_time_percent = 0;
                $late_percent = 0;
            }
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'fetch data from database failed',
                'errors' => $th->getMessage()
            ], 400);
        }

        return response()->json([
            'name' => $name,
            'position' => $position,
            'on_time_percent' => $on_time_percent,
            'late_percent' => $late_percent,
        ]);
    }

    public function getUid(Request $request)
    {
        $token = $request->bearerToken();
        $verifiedIdToken = $this->auth->verifyIdToken($token);
        $uid = $verifiedIdToken->claims()->get('sub');

        return $uid;
    }
}
