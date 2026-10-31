<?php

use App\Http\Controllers\Api\PinterestController;
use Illuminate\Support\Facades\Route;

// Pinterest redirects the browser here after consent (the "state" parameter identifies the user).
Route::get('/pinterest/callback', [PinterestController::class, 'callback'])->middleware('throttle:auth');

// Everything else is the React single-page app.
Route::view('/{any?}', 'app')->where('any', '^(?!api/|up$).*$');
