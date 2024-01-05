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

            // if (!$name->exists()) {
            //     return response()->json([
            //         'message' => 'user not found',
            //     ], 404);
            // }

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

            // if (!$name->exists()) {
            //     return response()->json([
            //         'message' => 'user not found',
            //     ], 404);
            // }

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
        try {
            $id_card = intval($request->id_card);
            $users = $this->firestore->collection('users')->where('id_card', '==', $id_card);

            $documents = $users->documents();

            foreach ($documents as $document) {
                $name = $document->get('name');
            }

            return response()->json('Access confirmed. Hello, ' . $name . '!');
        } catch (\Throwable $th) {
            return response()->json('Access denied. ID Card not found.', 404);
        }
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
                $exit_time = Carbon::parse($document->get('exit_time'));

                $status = $document->get('status');

                $history_list[] = [
                    'day_date' => $day_date,
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

            $presence_day = $presence_history->count();

            $presence_percent = $presence_day / 26 * 100;
            $absent_percent = 100 - $presence_percent;

            $on_time_day = $presence_history->where('status', '==', 'Tepat Waktu')
                ->count();

            $late_day = $presence_history->where('status', '==', 'Terlambat')
                ->count();

            $on_time_percent = $on_time_day / ($on_time_day + $late_day) * 100;
            $late_percent = $late_day / ($on_time_day + $late_day) * 100;
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'fetch data from database failed',
                'errors' => $th->getMessage()
            ], 400);
        }

        return response()->json([
            'name' => $name,
            'position' => $position,
            'presence_percent' => $presence_percent,
            'absent_percent' => $absent_percent,
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
