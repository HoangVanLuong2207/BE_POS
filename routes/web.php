<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;


Route::get('/migrate', function () {
    Artisan::call('migrate', ['--force' => true]);
    return "✅ Migrations executed successfully!";
});

Route::get('/test-cloudinary', function () {
    try {
        // Test Cloudinary connection
        $result = Cloudinary::upload('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', ['folder' => 'test']);
        return response()->json([
            'success' => true,
            'url' => $result->getSecurePath(),
            'message' => 'Cloudinary is working!'
        ]);
    } catch (Exception $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage(),
            'message' => 'Cloudinary error!'
        ]);
    }
});
