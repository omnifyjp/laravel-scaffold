<?php

use FammSupport\Http\Controllers\Api\CollectionController;
use FammSupport\Http\Controllers\Api\ObjectController;
use FammSupport\Http\Controllers\Api\SelectController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web'])->group(function () {
    Route::prefix('api/v1')->group(function () {
        Route::get('selects', [SelectController::class, 'list'])->name('api.selects');
        Route::get('selects/{selectName}', [SelectController::class, 'show'])->name('api.selects.show');
        Route::get('objects', [ObjectController::class, 'list'])->name('api.objects');
        Route::get('objects/{objectName}', [ObjectController::class, 'getObject'])->name('api.objects.get-object');
        Route::get('objects/{objectName}/{propertyName}', [ObjectController::class, 'getProperty'])->name('api.objects.get-property');
        Route::post('objects/{objectName}/{propertyName}/upload', [ObjectController::class, 'upload'])->name('api.objects.upload');

        Route::get('data/{objectName}', [CollectionController::class, 'index'])->name('api.objects.index');

    });
});
