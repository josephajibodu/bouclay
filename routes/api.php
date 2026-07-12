<?php

use App\Http\Controllers\Api\V1\CheckoutSessionController;
use App\Http\Controllers\Api\V1\CustomerAddressController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomerPaymentMethodController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PriceController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::get('customers', [CustomerController::class, 'index']);
Route::post('customers', [CustomerController::class, 'store']);
Route::get('customers/{customer}', [CustomerController::class, 'show']);
Route::patch('customers/{customer}', [CustomerController::class, 'update']);
Route::post('customers/{customer}/archive', [CustomerController::class, 'archive']);
Route::post('customers/{customer}/restore', [CustomerController::class, 'restore']);

Route::get('customers/{customer}/addresses', [CustomerAddressController::class, 'index']);
Route::post('customers/{customer}/addresses', [CustomerAddressController::class, 'store']);
Route::get('customers/{customer}/addresses/{address}', [CustomerAddressController::class, 'show']);
Route::patch('customers/{customer}/addresses/{address}', [CustomerAddressController::class, 'update']);

Route::get('customers/{customer}/payment-methods', [CustomerPaymentMethodController::class, 'index']);
Route::get('customers/{customer}/payment-methods/{paymentMethod}', [CustomerPaymentMethodController::class, 'show']);
Route::delete('customers/{customer}/payment-methods/{paymentMethod}', [CustomerPaymentMethodController::class, 'destroy']);

Route::get('products', [ProductController::class, 'index']);
Route::post('products', [ProductController::class, 'store']);
Route::get('products/{product}', [ProductController::class, 'show']);
Route::patch('products/{product}', [ProductController::class, 'update']);
Route::post('products/{product}/archive', [ProductController::class, 'archive']);

Route::get('prices', [PriceController::class, 'index']);
Route::post('products/{product}/prices', [PriceController::class, 'store']);
Route::get('prices/{price}', [PriceController::class, 'show']);
Route::patch('prices/{price}', [PriceController::class, 'update']);
Route::post('prices/{price}/archive', [PriceController::class, 'archive']);

Route::get('subscriptions', [SubscriptionController::class, 'index']);
Route::post('subscriptions', [SubscriptionController::class, 'store']);
Route::get('subscriptions/{subscription}', [SubscriptionController::class, 'show']);
Route::post('subscriptions/{subscription}/pause', [SubscriptionController::class, 'pause']);
Route::post('subscriptions/{subscription}/resume', [SubscriptionController::class, 'resume']);
Route::post('subscriptions/{subscription}/cancel', [SubscriptionController::class, 'cancel']);
Route::post('subscriptions/{subscription}/undo-cancel', [SubscriptionController::class, 'undoCancel']);
Route::post('subscriptions/{subscription}/retry-payment', [SubscriptionController::class, 'retryPayment']);
Route::patch('subscriptions/{subscription}/items/{item}', [SubscriptionController::class, 'updateItem']);

Route::get('invoices', [InvoiceController::class, 'index']);
Route::post('invoices', [InvoiceController::class, 'store']);
Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void']);
Route::post('invoices/{invoice}/mark-uncollectible', [InvoiceController::class, 'markUncollectible']);

Route::get('payments', [PaymentController::class, 'index']);
Route::get('payments/{payment}', [PaymentController::class, 'show']);

Route::get('events', [EventController::class, 'index']);
Route::get('events/{event}', [EventController::class, 'show']);

Route::post('checkout-sessions', [CheckoutSessionController::class, 'store']);
Route::get('checkout-sessions/{checkoutSession}', [CheckoutSessionController::class, 'show']);
