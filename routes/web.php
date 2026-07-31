<?php

use App\Http\Controllers\FGCheckerRecordController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('FGChecker');
})->name('home');

Route::get('/scan', function () {
    return Inertia::render('Scan');
})->name('scan');

Route::get('/print', function () {
    return Inertia::render('Print');
})->name('print');

Route::get('/admin', function () {
    return Inertia::render('Admin');
})->name('admin');

Route::get('/records', [FGCheckerRecordController::class, 'index'])->name('records');

Route::get('/export', [FGCheckerRecordController::class, 'export'])->name('export');
