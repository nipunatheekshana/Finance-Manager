<?php

use Illuminate\Support\Facades\Route;

/*
| The Vue application is served from a single shell view. Every non-API path
| returns it so the client-side router can take over, while /api and the
| Sanctum endpoints are matched first by their own route files.
*/

Route::view('/{any?}', 'app')
    ->where('any', '^(?!api|sanctum|storage|build|up).*$')
    ->name('app');
