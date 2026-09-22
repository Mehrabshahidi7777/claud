<?php

use App\Providers\AiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\SmsServiceProvider;

return [
    AppServiceProvider::class,
    SmsServiceProvider::class,
    AiServiceProvider::class,
];
