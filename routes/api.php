<?php

use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['api.key', 'throttle:60,1'])->group(function () {
    Route::post('/notifications/bulk', [NotificationController::class, 'bulk']);
    Route::get('/subscribers/{subscriber_id}/notifications', [NotificationController::class, 'subscriberNotifications'])
        ->where('subscriber_id', '[0-9]+');
});
