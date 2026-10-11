<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Auth::routes(['register' => false]);

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {
    Route::view('/', 'admin.dashboard')->name('dashboard');
});

Route::middleware('auth')->prefix('field')->name('field.')->group(function () {
    Route::view('/', 'field.home')->name('home');
});
