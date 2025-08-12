<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return 'sexo';
});

require __DIR__.'/auth.php';
