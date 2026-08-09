<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\FGCheckerRecordController;
use App\Http\Controllers\FGCheckerScanController;
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

Route::post('/tabletID', [AdminController::class, 'saveTabletID'])->name('saveTabletID');

Route::get('/processes', [FGCheckerScanController::class, 'processes'])->name('processes');

Route::get('/lot-history', [FgCheckerScanController::class, 'lotHistory'])->name('lot-history');

Route::post('/scan', [FGCheckerScanController::class, 'store'])->name('scan.store');

