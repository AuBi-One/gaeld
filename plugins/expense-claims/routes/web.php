<?php

use Illuminate\Support\Facades\Route;
use Plugins\ExpenseClaims\Http\Controllers\BalanceController;
use Plugins\ExpenseClaims\Http\Controllers\ClaimController;
use Plugins\ExpenseClaims\Http\Controllers\SettingsController;

Route::middleware(['web', 'auth', 'verified', 'org', 'org-2fa', 'subscription'])->group(function (): void {
    Route::prefix('expense-claims')->name('expense-claims.')->group(function (): void {
        Route::get('/', [ClaimController::class, 'index'])->name('index');
        Route::get('/create', [ClaimController::class, 'create'])->name('create');
        Route::post('/', [ClaimController::class, 'store'])->name('store');
        Route::get('/distance', [ClaimController::class, 'distance'])->middleware('throttle:30,1')->name('distance');
        Route::get('/address-search', [ClaimController::class, 'searchAddress'])->middleware('throttle:60,1')->name('address-search');
        Route::post('/addresses', [ClaimController::class, 'storeAddress'])->middleware('throttle:30,1')->name('addresses.store');
        Route::get('/{claim}', [ClaimController::class, 'show'])->whereUuid('claim')->name('show');
        Route::get('/{claim}/edit', [ClaimController::class, 'edit'])->whereUuid('claim')->name('edit');
        Route::put('/{claim}', [ClaimController::class, 'update'])->whereUuid('claim')->name('update');
        Route::delete('/{claim}', [ClaimController::class, 'destroy'])->whereUuid('claim')->name('destroy');
        Route::post('/{claim}/approve', [ClaimController::class, 'approve'])->whereUuid('claim')->name('approve');
        Route::post('/{claim}/unapprove', [ClaimController::class, 'unapprove'])->whereUuid('claim')->name('unapprove');
        Route::post('/{claim}/pay-bank', [ClaimController::class, 'payBank'])->whereUuid('claim')->name('pay-bank');
        Route::post('/{claim}/cancel-bank', [ClaimController::class, 'cancelBank'])->whereUuid('claim')->name('cancel-bank');
        Route::post('/{claim}/attachments', [ClaimController::class, 'attach'])->whereUuid('claim')->name('attach');
        Route::get('/{claim}/attachments/{index}', [ClaimController::class, 'attachment'])->whereUuid('claim')->whereNumber('index')->name('attachment');
    });

    Route::prefix('expense-balances')->name('expense-balances.')->group(function (): void {
        Route::get('/', [BalanceController::class, 'index'])->name('index');
        Route::post('/approve', [BalanceController::class, 'approve'])->name('approve');
        Route::post('/pay', [BalanceController::class, 'pay'])->name('pay');
        Route::post('/debt', [BalanceController::class, 'debt'])->name('debt');
        Route::delete('/debts/{debt}', [BalanceController::class, 'cancel'])->whereUuid('debt')->name('cancel');
        Route::post('/debts/{debt}/repay', [BalanceController::class, 'repay'])->whereUuid('debt')->name('repay');
    });

    Route::prefix('settings/expense-claims')->name('expense-settings.')->group(function (): void {
        Route::get('/', [SettingsController::class, 'index'])->name('index');
        Route::put('/accounts', [SettingsController::class, 'updateAccounts'])->name('accounts');
        Route::post('/rates', [SettingsController::class, 'storeRate'])->name('rates.store');
        Route::delete('/rates/{rate}', [SettingsController::class, 'destroyRate'])->whereUuid('rate')->name('rates.destroy');
        Route::post('/places', [SettingsController::class, 'storePlace'])->name('places.store');
        Route::put('/places/{place}', [SettingsController::class, 'updatePlace'])->whereUuid('place')->name('places.update');
        Route::delete('/places/{place}', [SettingsController::class, 'destroyPlace'])->whereUuid('place')->name('places.destroy');
        Route::get('/address-search', [SettingsController::class, 'searchAddress'])->middleware('throttle:60,1')->name('address-search');
        Route::post('/people', [SettingsController::class, 'storePerson'])->name('people.store');
        Route::put('/people/{person}', [SettingsController::class, 'updatePerson'])->whereUuid('person')->name('people.update');
        Route::delete('/people/{person}', [SettingsController::class, 'destroyPerson'])->whereUuid('person')->name('people.destroy');
    });
});
