<?php

use FammSupport\Http\Controllers\Api\SelectController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web'])->group(function () {
    Route::prefix('api/v1')->group(function () {
        Route::get('selects', [SelectController::class, 'list'])->name('api.selects');
        Route::get('selects/{selectName}', [SelectController::class, 'show'])->name('api.selects.show');
    });
});