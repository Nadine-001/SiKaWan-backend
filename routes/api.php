<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\PresenceController;
use App\Http\Controllers\ProjectController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/
Route::post('/sign_up', [AuthController::class, 'sign_up']); //Sign Up
Route::post('/login', [AuthController::class, 'login']); //Login
Route::post('/forgot_password', [AuthController::class, 'forgot_password']); //Lupa Password
Route::post('/door_access', [PresenceController::class, 'door_access']); //Buka Pintu

Route::group(['middleware' => 'firebase'], function () {
    Route::get('/logout', [AuthController::class, 'logout']); //Logout
    Route::post('/entry', [PresenceController::class, 'entry']); //Absen Masuk
    Route::post('/exit', [PresenceController::class, 'exit']); //Absen Keluar
    Route::get('/history', [PresenceController::class, 'history']); //Riwayat Absensi
    Route::get('/statistic', [PresenceController::class, 'statistic']); //Statistik Kinerja

    Route::post('/create_project', [ProjectController::class, 'create_project']); //Absen Masuk
    Route::get('/projects', [ProjectController::class, 'project_list']); //Daftar Proyek
    Route::get('/projects/{project_id}', [ProjectController::class, 'project_detail']); //Detail Proyek
    Route::put('/projects/{project_id}', [ProjectController::class, 'update_project']); //Update Proyek
    Route::delete('/projects/{project_id}', [ProjectController::class, 'delete_project']); //Hapus Proyek
});

// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });
