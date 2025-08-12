<?php

use App\Http\Controllers\XmlController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('/xml', [XmlController::class, 'transformarXml']);
Route::get('/xmlView', [XmlController::class,'xmlrequest']);
