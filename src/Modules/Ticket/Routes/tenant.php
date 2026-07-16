<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Ticket\Http\Controllers\TicketController;

Route::prefix('tickets')->group(function () {
    Route::get('/', [TicketController::class, 'index']);
    Route::post('/', [TicketController::class, 'store']);
    Route::get('/{ticketId}', [TicketController::class, 'show']);
    Route::put('/{ticketId}', [TicketController::class, 'update']);
    Route::delete('/{ticketId}', [TicketController::class, 'destroy']);
    Route::post('/{ticketId}/assign', [TicketController::class, 'assign']);
    Route::post('/{ticketId}/resolve', [TicketController::class, 'resolve']);
    Route::get('/{ticketId}/comments', [TicketController::class, 'comments']);
    Route::post('/{ticketId}/comments', [TicketController::class, 'addComment']);
});
