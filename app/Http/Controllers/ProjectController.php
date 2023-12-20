<?php

namespace App\Http\Controllers;

use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FieldValue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Kreait\Firebase\Factory;
use Kreait\Laravel\Firebase\Facades\Firebase;
use SebastianBergmann\CodeCoverage\Report\Xml\Project;

class ProjectController extends Controller
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

    public function create_project(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'start_date' => 'required',
            'deadline' => 'required',
            'value' => 'required',
            'description' => 'required',
            'assigned_to' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 406);
        }

        try {
            $start_date = new Timestamp(new \DateTime($request->start_date));
            $deadline = new Timestamp(new \DateTime($request->deadline));

            $names = explode(',', $request->assigned_to);

            $uids = [];
            for ($i = 0; $i < count($names); $i++) {
                $name = $this->firestore->collection('users')
                    ->where('name', '==', $names[$i]);

                $documents = $name->documents();

                foreach ($documents as $document) {
                    $uid = $document->id();
                    array_push($uids, $uid);
                }
            }

            $assigned_to = FieldValue::arrayUnion($uids);

            $project = $this->firestore->collection('projects')->newDocument();

            $project->set([
                'name' => $request->name,
                'start_date' => $start_date,
                'deadline' => $deadline,
                'value' => $request->value,
                'description' => $request->description,
                'assigned_to' => $assigned_to,
                'status' => 'On Going',
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'add project failed',
                'errors' => $th->getMessage()
            ], 400);
        }

        return response()->json('success add project');
    }

    public function project_list()
    {
        $documents = $this->firestore->collection('projects')->documents();

        try {
            $project_data = [];
            foreach ($documents as $document) {
                $projects = $document->id();
                $project = $this->firestore->collection('projects')
                    ->document($projects)
                    ->snapshot()
                    ->data();

                $project['id'] = $projects;

                array_push($project_data, $project);
            }
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'fetch data from database failed',
                'errors' => $th->getMessage()
            ], 400);
        }

        return response()->json($project_data);
    }

    public function project_detail(Request $request, $project_id)
    {
        $project = $this->firestore->collection('projects')
            ->document($project_id)
            ->snapshot()
            ->data();

        try {
            $assignee = [];
            for ($i = 0; $i < count($project['assigned_to']); $i++) {
                $names = $project['assigned_to'][$i];

                $user = $this->firestore->collection('users')
                    ->document($names)
                    ->snapshot()
                    ->data();

                $name = ['name' => $user['name'], 'position' => $user['position']];
                array_push($assignee, $name);
            }
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'fetch data from database failed',
                'errors' => $th->getMessage()
            ], 400);
        }

        return response()->json([
            'project' => $project,
            'assignee' => $assignee,
        ]);
    }

    public function update_project(Request $request, $project_id)
    {
        try {
            $project = $this->firestore->collection('projects')->document($project_id);

            $data = [];
            foreach ($request->all() as $key => $value) {
                array_push($data, ['path' => $key, 'value' => $value]);
            }

            $project->update($data);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'update project failed',
                'errors' => $th->getMessage()
            ], 400);
        }
    }

    public function delete_project(Request $request, $project_id)
    {
        try {
            $this->firestore->collection('projects')->document($project_id)->delete();
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'delete project failed',
                'errors' => $th->getMessage()
            ], 400);
        }
    }
}