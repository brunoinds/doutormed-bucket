<?php

use App\Http\Controllers\BucketController;
use Illuminate\Support\Facades\Route;

// S3-compliant bucket operations
// Routes support paths like: /buckets/{bucket}/{path...}
// Example: /buckets/my-bucket/path/to/file.mp4



Route::match(['HEAD'], '/buckets/{bucket}/{path}', [BucketController::class, 'head'])->where('path', '.*');

// List bucket contents (GET /buckets/{bucket})
Route::get('/buckets/{bucket}', [BucketController::class, 'list'])
    ->where('bucket', '[^/]+');

// Get file (GET /buckets/{bucket}/{path})
Route::get('/buckets/{bucket}/{path}', [BucketController::class, 'get'])
    ->where('bucket', '[^/]+')
    ->where('path', '.*');

// Upload/Put file (PUT /buckets/{bucket}/{path}) - Requires authentication
Route::put('/buckets/{bucket}/{path}', [BucketController::class, 'put'])
    ->middleware('auth.bearer')
    ->where('bucket', '[^/]+')
    ->where('path', '.*');

// Upload file (POST /buckets/{bucket}/{path}) - Requires authentication
Route::post('/buckets/{bucket}/{path}', [BucketController::class, 'put'])
    ->middleware('auth.bearer')
    ->where('bucket', '[^/]+')
    ->where('path', '.*');

// Delete file (DELETE /buckets/{bucket}/{path}) - Requires authentication
Route::delete('/buckets/{bucket}/{path}', [BucketController::class, 'delete'])
    ->middleware('auth.bearer')
    ->where('bucket', '[^/]+')
    ->where('path', '.*');

// Generate signed URL for upload (POST /buckets/{bucket}/{path}/signed-url) - Requires authentication
Route::post('/buckets/{bucket}/{path}/signed-url', [BucketController::class, 'generateSignedUrl'])
    ->middleware('auth.bearer')
    ->where('bucket', '[^/]+')
    ->where('path', '.*');
