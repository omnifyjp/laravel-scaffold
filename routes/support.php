<?php

use Illuminate\Support\Facades\Route;
use OmnifyJP\LaravelScaffold\Http\Controllers\Api\CollectionController;
use OmnifyJP\LaravelScaffold\Http\Controllers\Api\SelectController;

Route::middleware(['web'])->group(function () {
    Route::prefix('api/v1')->group(function () {
        Route::get('selects', [SelectController::class, 'list'])->name('api.selects');
        Route::get('selects/{selectName}', [SelectController::class, 'show'])->name('api.selects.show');
        Route::get('data/{objectName}', [CollectionController::class, 'index'])->name('api.objects.index');
    });
});
