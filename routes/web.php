<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\FGCheckerPrintController;
use App\Http\Controllers\FGCheckerRecordController;
use App\Http\Controllers\FGCheckerScanController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;


//Home
Route::get('/', function () { return Inertia::render('FGChecker'); })->name('home');

Route::post('/tabletID', [AdminController::class, 'saveTabletID'])->name('saveTabletID');

//Scan
Route::get('/scan', function () { return Inertia::render('Scan'); })->name('scan');

Route::get('/records', [FGCheckerRecordController::class, 'index'])->name('records');

Route::get('/export', [FGCheckerRecordController::class, 'export'])->name('export');


Route::get('/processes', [FGCheckerScanController::class, 'processes'])->name('processes');

Route::get('/lot-history', [FGCheckerScanController::class, 'lotHistory'])->name('lot-history');

Route::post('/scan', [FGCheckerScanController::class, 'store'])->name('scan.store');

//Print
Route::get('/print', function () { return Inertia::render('Print'); })->name('print');

Route::post('/print/rtv', [FGCheckerPrintController::class, 'printRtv'])->name('fgchecker.print.rtv');

Route::post('/print/validation-sticker', [FGCheckerPrintController::class, 'printValidationSticker'])->name('fgchecker.print.validation-sticker');

Route::get('/validations', [FGCheckerPrintController::class, 'validationTypes'])->name('fgchecker.validations');

Route::get('/models', [FGCheckerPrintController::class, 'models'])->name('fgchecker.models');

//Admin
Route::get('/admin', function () { return Inertia::render('Admin'); })->name('admin');



