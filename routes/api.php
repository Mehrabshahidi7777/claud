<?php

use App\Http\Controllers\Webhooks\AmootInboundController;
use Illuminate\Support\Facades\Route;

/*
| The inbound webhook. It lives under an unguessable path and additionally
| authenticates a shared secret, because the path alone leaking should not be
| enough to inject replies into anyone's tasks.
*/

Route::post('webhooks/sms/amoot/{slug}', AmootInboundController::class)
    ->where('slug', '[A-Za-z0-9\-_]+')
    ->name('webhooks.sms.amoot');
