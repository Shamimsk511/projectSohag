<?php

use App\Enums\Role;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\RoleMiddleware;

Route::get('/', HomeController::class)->middleware('auth')->name('home');

Auth::routes(['register' => false]);

Route::middleware(['auth', RoleMiddleware::using(Role::names(Role::office()))])
    ->prefix('admin')->name('admin.')->group(function () {
        Route::view('/', 'admin.dashboard')->name('dashboard');
        Route::resource('users', UserController::class)->except(['show', 'destroy']);
    });

Route::middleware(['auth', RoleMiddleware::using(Role::names([Role::FieldOfficer, Role::Admin]))])
    ->prefix('field')->name('field.')->group(function () {
        Route::view('/', 'field.home')->name('home');
    });
