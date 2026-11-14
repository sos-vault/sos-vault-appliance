<?php

use Illuminate\Support\Facades\Route;

Route::group(['middleware' => 'web'], function () {
    Route::post('/vaultState', '\Wave\Http\Controllers\API\SvaultController@vaultState')->name('api.vaultState');
    Route::get('/userInfo', '\Wave\Http\Controllers\API\SvaultController@userInfo');
});

// Tight per-endpoint throttles on the unauthenticated/credential-bearing API
// auth routes — the generic api limiter (60/min) is far too permissive for
// brute-force / credential-stuffing / automated signup. Mirrors the web posture
// (web login 5/min, register 3/min). Upload is looser (30/min) for batch clients.
Route::post('login', '\Wave\Http\Controllers\API\AuthController@login')->middleware('throttle:5,1');
Route::post('register', '\Wave\Http\Controllers\API\AuthController@register')->middleware('throttle:3,1');
Route::post('logout', '\Wave\Http\Controllers\API\AuthController@logout');
Route::post('refresh', '\Wave\Http\Controllers\API\AuthController@refresh')->middleware('throttle:10,1');
Route::post('token', '\Wave\Http\Controllers\API\AuthController@token')->middleware('throttle:10,1');
Route::post('upload', '\Wave\Http\Controllers\API\AuthController@upload')->middleware('throttle:30,1');
