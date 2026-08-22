<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\FGCheckerPrintController;
use App\Http\Controllers\FGCheckerR4Controller;
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

Route::get('/admin/access', [AdminController::class, 'checkAccess']);

Route::get('/admin/models', [AdminController::class, 'models']);
Route::post('/admin/models', [AdminController::class, 'storeModel']);
Route::put('/admin/models/{no}', [AdminController::class, 'updateModel']);
Route::delete('/admin/models/{no}', [AdminController::class, 'destroyModel']);

Route::get('/admin/validations', [AdminController::class, 'validations']);
Route::post('/admin/validations', [AdminController::class, 'storeValidation']);
Route::put('/admin/validations/{id}', [AdminController::class, 'updateValidation']);
Route::delete('/admin/validations/{id}', [AdminController::class, 'destroyValidation']);

Route::get('/admin/processes', [AdminController::class, 'processes']);
Route::post('/admin/processes', [AdminController::class, 'storeProcess']);
Route::put('/admin/processes/{no}', [AdminController::class, 'updateProcess']);
Route::delete('/admin/processes/{no}', [AdminController::class, 'destroyProcess']);

Route::get('/admin/tablets', [AdminController::class, 'tablets']);
Route::post('/admin/tablets', [AdminController::class, 'storeTablet']);
Route::put('/admin/tablets/{id}', [AdminController::class, 'updateTablet']);
Route::delete('/admin/tablets/{id}', [AdminController::class, 'destroyTablet']);

//Arduino R4
Route::get('/r4-status-stream', [FGCheckerR4Controller::class, 'stream']);
