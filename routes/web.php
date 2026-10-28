<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => ['app' => config('app.name'), 'api' => url('/api/v1')]);
