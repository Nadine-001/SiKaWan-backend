<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Google\Cloud\Core\GeoPoint;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Storage\Connection\Rest;
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
            'entry_location' => 'required',
            'status' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 406);
        }

        try {
            $uid = $request->session()->get('uid');
            $name = $this->firestore->collection('users')
                ->document($uid)
                ->snapshot()
                ->get('name');

            $date = $request->date;
            $month = $request->month;
            $year = $request->year;
            $time = $request->time;

            $entry_time = new Timestamp(new \DateTime($date . '-' . $month . '-' . $year . ' ' . $time));
            // $entry_location = new GeoPoint();

            $entry = $this->firestore->collection('presence_history')->document($name . '-' . date("jny"));

            $entry->set([
                'uid' => $uid,
                'day' => $request->day,
                'date' => $date,
                'month' => $month,
                'year' => $year,
                'entry_time' => $entry_time,
                'exit_time' => null,
                'entry_note' => $request->entry_note,
                'entry_location' => $request->entry_location,
                'exit_location' => null,
                'status' => $request->status,
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'insert data to database failed',
                'errors' => $th->getMessage()
            ], 400);
        }

        return response()->json('success add entry time');
    }

    public function exit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date' => 'required',
            'month' => 'required',
            'year' => 'required',
            'time' => 'required',
            'exit_location' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 406);
        }

        try {
            $uid = $request->session()->get('uid');
            $name = $this->firestore->collection('users')
                ->document($uid)
                ->snapshot()
                ->get('name');

            $date = $request->date;
            $month = $request->month;
            $year = $request->year;
            $time = $request->time;

            $exit_time = new Timestamp(new \DateTime($date . '-' . $month . '-' . $year . ' ' . $time));
            // $exit_location = new GeoPoint();

            $exit = $this->firestore->collection('presence_history')->document($name . '-' . date('jny'));

            $exit->update([
                ['path' => 'exit_time', 'value' => $exit_time],
                ['path' => 'exit_note', 'value' => $request->exit_note],
                ['path' => 'exit_location', 'value' => $request->exit_location]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'insert data to database failed',
                'errors' => $th->getMessage()
            ], 400);
        }

        return response()->json('success add exit time');
    }

    public function door_access(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_card' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 406);
        }

        $id_card = intval($request->id_card);

        try {
            $id_card = $this->firestore->collection('users')->where('id_card', '==', $id_card);

            $documents = $id_card->documents();

            foreach ($documents as $document) {
                $name = $document->get('name');
            }
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'AccesS denied. ID Card not found.',
                'errors' => $th->getMessage()
            ], 400);
        }

        return response()->json('Access confirmed. Hello, ' . $name);
    }

    public function history(Request $request)
    {
        $uid = $request->session()->get('uid');

        try {
            $users = $this->firestore->collection('users')
                ->document($uid)
                ->snapshot();

            if (!$users->exists()) {
                return response()->json([
                    'message' => 'user not found',
                ], 404);
            }

            $name = $users->get('name');
            $position = $users->get('position');

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
                    'entry_time' => $entry_time->format('H:i:s'),
                    'exit_time' => $exit_time->format('H:i:s'),
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
        $uid = $request->session()->get('uid');

        try {
            $users = $this->firestore->collection('users')
                ->document($uid)
                ->snapshot();

            if (!$users->exists()) {
                return response()->json([
                    'message' => 'user not found',
                ], 404);
            }

            $name = $users->get('name');
            $position = $users->get('position');

            $presence_history = $this->firestore->collection('presence_history')
                ->where('uid', '==', $uid);

            $presence_day = $presence_history->count();

            $presence_percent = $presence_day / 26 * 100;
            $absent_percent = 100 - $presence_percent;

            $on_time_percent = $presence_history->where('status', '==', 'Tepat Waktu')
                ->count();

            $late_percent = $presence_history->where('status', '==', 'Terlambat')
                ->count();
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
            'absent_percent=' => $absent_percent,
            'on_time_percent' => $on_time_percent,
            'late_percent' => $late_percent,
        ]);
    }
}
