<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->to(env('FRONTEND_URL', 'https://nd-hostpot.net'));
});

Route::get('/login', function () {
    return response()->json(['message' => 'Unauthenticated.'], 401);
})->name('login');
