<?php

use Illuminate\Support\Facades\Route;
use Plugins\Offers\Http\Controllers\OfferController;
use Plugins\Offers\Http\Controllers\TemplateController;

Route::middleware(['web', 'auth', 'verified', 'org', 'org-2fa', 'subscription'])->group(function (): void {
    Route::prefix('offers')->name('offers.')->group(function (): void {
        Route::get('/', [OfferController::class, 'index'])->name('index');
        Route::get('/create', [OfferController::class, 'create'])->name('create');
        Route::post('/', [OfferController::class, 'store'])->name('store');
        Route::get('/line-source', [OfferController::class, 'lineSource'])->middleware('throttle:60,1')->name('line-source');
        Route::get('/{offer}', [OfferController::class, 'show'])->whereUuid('offer')->name('show');
        Route::get('/{offer}/edit', [OfferController::class, 'edit'])->whereUuid('offer')->name('edit');
        Route::put('/{offer}', [OfferController::class, 'update'])->whereUuid('offer')->name('update');
        Route::delete('/{offer}', [OfferController::class, 'destroy'])->whereUuid('offer')->name('destroy');
        Route::get('/{offer}/document', [OfferController::class, 'document'])->whereUuid('offer')->name('document');
        Route::post('/{offer}/revise', [OfferController::class, 'revise'])->whereUuid('offer')->name('revise');
        Route::get('/{offer}/invoice', [OfferController::class, 'invoiceForm'])->whereUuid('offer')->name('invoice-form');
        Route::post('/{offer}/invoice', [OfferController::class, 'invoice'])->whereUuid('offer')->name('invoice');
        Route::post('/{offer}/save-as-template', [OfferController::class, 'saveAsTemplate'])->whereUuid('offer')->name('save-as-template');
        Route::post('/{offer}/{action}', [OfferController::class, 'transition'])->whereUuid('offer')->whereIn('action', ['send', 'revert', 'accept', 'refuse', 'reopen'])->name('transition');
    });

    Route::prefix('offer-templates')->name('offer-templates.')->group(function (): void {
        Route::get('/', [TemplateController::class, 'index'])->name('index');
        Route::get('/create', [TemplateController::class, 'create'])->name('create');
        Route::post('/', [TemplateController::class, 'store'])->name('store');
        Route::put('/settings', [TemplateController::class, 'updateSettings'])->name('settings');
        Route::post('/preview', [TemplateController::class, 'preparePreview'])->middleware('throttle:30,1')->name('preview');
        Route::get('/preview/{token}', [TemplateController::class, 'preview'])->where('token', '[A-Za-z0-9]{40}')->name('preview.show');
        Route::get('/{template}/edit', [TemplateController::class, 'edit'])->whereUuid('template')->name('edit');
        Route::put('/{template}', [TemplateController::class, 'update'])->whereUuid('template')->name('update');
        Route::delete('/{template}', [TemplateController::class, 'destroy'])->whereUuid('template')->name('destroy');
    });
});
