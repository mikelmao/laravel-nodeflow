<?php

use Illuminate\Support\Facades\Route;
use Nodeflow\Http\Controllers\WebhookTriggerController;

Route::post('hooks/{token}', WebhookTriggerController::class)->name('nodeflow.webhooks.receive');
