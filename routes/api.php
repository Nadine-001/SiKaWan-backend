<?php

use App\Http\Controllers\AdminController;
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
Route::post('/door_access/{id_card}', [PresenceController::class, 'door_access']); //Absen Masuk, Buka Pintu, dan Absen Keluar

// ADMIN
Route::post('/sign_up_admin', [AdminController::class, 'sign_up']); //Sign Up Admin
Route::post('/login_admin', [AdminController::class, 'login']); //Login Admin

Route::group(['middleware' => 'firebase'], function () {
    Route::post('/upload_photo', [AuthController::class, 'upload_photo']); //Upload Foto Profil
    Route::get('/get_photo', [AuthController::class, 'get_photo']); //Menampilkan Foto Profil
    Route::delete('/delete_photo', [AuthController::class, 'delete_photo']); //Hapus Foto Profil
    Route::get('/profile', [AuthController::class, 'profile']); //Profil
    Route::get('/division', [AuthController::class, 'division']); //Divisi
    Route::get('/logout', [AuthController::class, 'logout']); //Logout
    Route::get('/work_time', [PresenceController::class, 'work_time']); //Jam Kerja
    Route::post('/entry', [PresenceController::class, 'entry']); //Absen Masuk
    Route::post('/exit', [PresenceController::class, 'exit']); //Absen Keluar
    Route::get('/history', [PresenceController::class, 'history']); //Riwayat Absensi
    Route::get('/statistic', [PresenceController::class, 'statistic']); //Statistik Kinerja
    Route::get('/projects', [ProjectController::class, 'project_list']); //Daftar Proyek
    Route::get('/projects/{project_id}', [ProjectController::class, 'project_detail']); //Detail Proyek

    Route::group(['middleware' => 'admin'], function () {
        Route::get('/profile_admin', [AdminController::class, 'profile']); //Profile Admin
        Route::get('/logout_admin', [AdminController::class, 'logout']); //Logout Admin
        Route::get('/dashboard', [AdminController::class, 'dashboard']); //Dashboard
        Route::post('/full_time', [AdminController::class, 'full_time_add']); //Add Full Time
        Route::post('/part_time', [AdminController::class, 'part_time_add']); //Add Part Time
        Route::post('/create_project', [ProjectController::class, 'create_project']); //Buat Proyek
        Route::put('/projects/{project_id}', [ProjectController::class, 'update_project']); //Update Proyek
        Route::delete('/projects/{project_id}', [ProjectController::class, 'delete_project']); //Hapus Proyek
    });
});
