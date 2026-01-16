<?php

use App\Http\Controllers\BucketController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/uploader', [BucketController::class, 'showUploader'])->name('uploader');
Route::post('/uploader', [BucketController::class, 'uploadFile'])->name('uploader.upload');
