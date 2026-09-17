<?php

use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return auth()->user();
});

Wave::api();

// Posts Example API Route
Route::group(['middleware' => 'auth:api'], function () {
    Route::get('/posts', '\App\Http\Controllers\Api\ApiController@posts');
});

Route::group(['middleware' => 'web'], function(){
    //Route::post('/vaultState',            '\App\Http\Controllers\Api\SvaultController@vaultState')->name('api.vaultState');
    //Route::post('/uploadState/{uploadid}','\App\Http\Controllers\Api\fileUploadController@uploadStatus');
});

