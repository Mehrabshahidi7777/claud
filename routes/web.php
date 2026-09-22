<?php

use App\Http\Controllers\Auth\PhoneLoginController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PaymentCallbackController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskParseController;
use App\Http\Controllers\WeeklyReportController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/tasks');

/*
| Sign-in. No password, no email: the phone number is the identity, which is
| what lets the follow-up engine reach everyone from the moment they exist.
*/

Route::middleware('guest')->group(function () {
    Route::get('login', [PhoneLoginController::class, 'show'])->name('login');
    Route::post('login', [PhoneLoginController::class, 'requestCode'])->name('login.request');
    Route::get('login/code', [PhoneLoginController::class, 'showCodeForm'])->name('login.code');
    Route::post('login/code', [PhoneLoginController::class, 'verifyCode'])->name('login.verify');
});

Route::post('logout', [PhoneLoginController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

/*
| The bank's return, outside the authenticated group on purpose: the payer
| comes back through a redirect chain that may have dropped their session, and
| refusing them here would strand a completed payment. The reference plays the
| part of authorisation, and nothing is trusted until the verify call agrees.
*/

Route::match(['get', 'post'], 'billing/callback', PaymentCallbackController::class)
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('billing.callback');

Route::middleware('auth')->group(function () {
    Route::get('onboarding', [OnboardingController::class, 'show'])->name('onboarding');
    Route::post('onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');

    Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::post('tasks/{task}/complete', [TaskController::class, 'complete'])->name('tasks.complete');
    Route::post('tasks/{task}/cancel', [TaskController::class, 'cancel'])->name('tasks.cancel');
    Route::post('tasks/{task}/reschedule', [TaskController::class, 'reschedule'])->name('tasks.reschedule');

    Route::post('tasks/parse', TaskParseController::class)->name('tasks.parse');

    Route::get('members', [MemberController::class, 'index'])->name('members.index');
    Route::post('members', [MemberController::class, 'store'])->name('members.store');
    Route::patch('members/{member}', [MemberController::class, 'update'])->name('members.update');
    Route::post('members/{member}/resume-sms', [MemberController::class, 'resumeSms'])->name('members.resume-sms');

    Route::get('billing', [BillingController::class, 'index'])->name('billing.index');
    Route::post('billing', [BillingController::class, 'store'])->name('billing.store');
    Route::get('billing/invoices/{invoice}', [BillingController::class, 'invoice'])->name('billing.invoice');
    Route::post('billing/invoices/{invoice}/pay', [BillingController::class, 'pay'])->name('billing.pay');

    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/weekly', [WeeklyReportController::class, 'index'])->name('reports.weekly.index');
    Route::get('reports/weekly/{token}', [WeeklyReportController::class, 'show'])
        ->where('token', '[A-Za-z0-9]+')
        ->name('reports.weekly.show');
});
