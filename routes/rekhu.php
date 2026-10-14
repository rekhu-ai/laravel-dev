<?php

use Illuminate\Support\Facades\Route;
use Rekhu\LaravelDev\Http\RunController;

// The platform's door into the app in direct mode: signed with the app's own secret, never behind a session or CSRF.
Route::post(config('rekhu.path', 'rekhu').'/run', [RunController::class, 'run'])->name('rekhu.run');
Route::get(config('rekhu.path', 'rekhu').'/ping', [RunController::class, 'ping'])->name('rekhu.ping');
