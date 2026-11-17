<?php

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerHistoryController;
use App\Http\Controllers\CutoffController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceMessageController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\Support\AccountController as SupportAccountController;
use App\Http\Controllers\Support\RequestController as SupportRequestAdminController;
use App\Http\Controllers\Support\SessionController as SupportSessionController;
use App\Http\Controllers\Support\SubscriptionController as SupportSubscriptionController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TariffGroupController;
use App\Http\Controllers\UnpaidController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\AuthenticateSupport;
use App\Http\Middleware\EnsureAccountAdmin;
use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/tableau-de-bord');

Route::get('langue/{locale}', fn (string $locale) => back(fallback: '/')->withCookie(cookie()->forever('locale', $locale)))
    ->whereIn('locale', SetLocale::LOCALES)->name('locale.switch');

Route::get('theme/{theme}', fn (string $theme) => back(fallback: '/')->withCookie(cookie()->forever('theme', $theme)))
    ->whereIn('theme', ['light', 'dark'])->name('theme.switch');

Route::get('csrf/refresh', fn () => response()->json(['token' => csrf_token()]))->name('csrf.refresh');

require __DIR__.'/auth.php';

Route::middleware('auth')->group(function () {
    Route::get('tableau-de-bord', DashboardController::class)->name('dashboard');

    Route::resource('clients', CustomerController::class)->names('customers')->parameters(['clients' => 'customer']);
    Route::post('clients/{customer}/coupure', [CustomerController::class, 'suspend'])->name('customers.suspend');
    Route::post('clients/{customer}/reabonnement', [CustomerController::class, 'reconnect'])->name('customers.reconnect');
    Route::post('clients/{customer}/resiliation', [CustomerController::class, 'terminate'])->name('customers.terminate');
    Route::get('clients/{customer}/historique', [CustomerHistoryController::class, 'show'])->name('customers.history');
    Route::get('clients/{customer}/historique/pdf', [CustomerHistoryController::class, 'pdf'])->name('customers.history.pdf');

    Route::resource('groupes', TariffGroupController::class)->names('groups')->parameters(['groupes' => 'group']);

    Route::get('carte', [MapController::class, 'index'])->name('map.index');
    Route::get('coupures', [CutoffController::class, 'index'])->name('cutoffs.index');

    Route::get('factures', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('factures/imprimer', [InvoiceController::class, 'month'])->name('invoices.month');
    Route::get('factures/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('factures/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
    Route::get('factures/{invoice}/modifier', [InvoiceController::class, 'edit'])->name('invoices.edit');
    Route::put('factures/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');

    Route::get('impayes', [UnpaidController::class, 'index'])->name('unpaid.index');

    Route::get('paiements', [PaymentController::class, 'index'])->name('payments.index');
    Route::post('clients/{customer}/paiements', [PaymentController::class, 'store'])->name('payments.store');
    Route::get('paiements/{payment}/modifier', [PaymentController::class, 'edit'])->name('payments.edit');
    Route::put('paiements/{payment}', [PaymentController::class, 'update'])->name('payments.update');
    Route::delete('paiements/{payment}', [PaymentController::class, 'destroy'])->name('payments.destroy');

    Route::get('messages', [InvoiceMessageController::class, 'index'])->name('messages.index');
    Route::post('factures/{invoice}/message/{channel}', [InvoiceMessageController::class, 'open'])->whereIn('channel', ['whatsapp', 'sms'])->name('messages.open')->middleware(\App\Http\Middleware\EnsureMessagingEnabled::class);
    Route::post('factures/{invoice}/rappel/{channel}', [InvoiceMessageController::class, 'openReminder'])->whereIn('channel', ['whatsapp', 'sms'])->name('messages.reminder')->middleware(\App\Http\Middleware\EnsureMessagingEnabled::class);
    Route::post('paiements/{payment}/message/{channel}', [InvoiceMessageController::class, 'openPayment'])->whereIn('channel', ['whatsapp', 'sms'])->name('messages.payment')->middleware(\App\Http\Middleware\EnsureMessagingEnabled::class);
    Route::post('clients/{customer}/relance/{channel}', [InvoiceMessageController::class, 'openLateNotice'])->whereIn('channel', ['whatsapp', 'sms'])->name('messages.late')->middleware(\App\Http\Middleware\EnsureMessagingEnabled::class);
    Route::post('clients/{customer}/preavis-coupure/{channel}', [InvoiceMessageController::class, 'openCutoffWarning'])->whereIn('channel', ['whatsapp', 'sms'])->name('messages.cutoff_warning')->middleware(\App\Http\Middleware\EnsureMessagingEnabled::class);
    Route::post('evenements/{event}/message/{channel}', [InvoiceMessageController::class, 'openEvent'])->whereIn('channel', ['whatsapp', 'sms'])->name('messages.event')->middleware(\App\Http\Middleware\EnsureMessagingEnabled::class);

    Route::get('abonnement', [SubscriptionController::class, 'index'])->name('subscription.index');
    Route::post('abonnement', [SubscriptionController::class, 'store'])->middleware(['throttle:10,1', EnsureAccountAdmin::class])->name('subscription.store');

    Route::resource('utilisateurs', UserController::class)->except('show')->names('users')->parameters(['utilisateurs' => 'member'])->whereNumber('member')->middleware(EnsureAccountAdmin::class);

    Route::get('parametres', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('parametres', [SettingsController::class, 'update'])->middleware(EnsureAccountAdmin::class)->name('settings.update');
    Route::get('parametres/logo', [SettingsController::class, 'showLogo'])->name('settings.logo');
    Route::post('parametres/logo', [SettingsController::class, 'updateLogo'])->middleware(EnsureAccountAdmin::class)->name('settings.logo.update');
    Route::delete('parametres/logo', [SettingsController::class, 'destroyLogo'])->middleware(EnsureAccountAdmin::class)->name('settings.logo.destroy');
    Route::put('parametres/mot-de-passe', [SettingsController::class, 'updatePassword'])->name('settings.password');
    Route::put('parametres/questions-securite', [SettingsController::class, 'updateSecurityQuestions'])->name('settings.security');
});

// Poste de support : comptes séparés de ceux des distributeurs.
Route::prefix('support')->name('support.')->group(function () {
    Route::get('/', fn () => redirect()->route(Auth::guard('support')->check() ? 'support.accounts.index' : 'support.login'))->name('home');

    Route::middleware('guest:support')->group(function () {
        Route::get('connexion', [SupportSessionController::class, 'create'])->name('login');
        Route::post('connexion', [SupportSessionController::class, 'store'])->middleware('throttle:10,1');
    });

    Route::middleware(AuthenticateSupport::class)->group(function () {
        Route::post('deconnexion', [SupportSessionController::class, 'destroy'])->name('logout');
        Route::get('demandes', [SupportRequestAdminController::class, 'index'])->name('requests.index');
        Route::get('demandes/{supportRequest}', [SupportRequestAdminController::class, 'show'])->name('requests.show');
        Route::post('demandes/{supportRequest}/reinitialiser', [SupportRequestAdminController::class, 'reset'])->name('requests.reset');
        Route::post('demandes/{supportRequest}/cloturer', [SupportRequestAdminController::class, 'close'])->name('requests.close');

        Route::get('comptes', [SupportAccountController::class, 'index'])->name('accounts.index');
        Route::get('comptes/{organisation}', [SupportAccountController::class, 'show'])->whereNumber('organisation')->name('accounts.show');
        Route::post('comptes/{organisation}/utilisateurs/{user}/reinitialiser', [SupportAccountController::class, 'resetPassword'])->whereNumber('user')->name('accounts.reset');
        Route::post('comptes/{organisation}/suspendre', [SupportAccountController::class, 'suspend'])->name('accounts.suspend');
        Route::post('comptes/{organisation}/reactiver', [SupportAccountController::class, 'reactivate'])->name('accounts.reactivate');
        Route::post('comptes/{organisation}/prolonger', [SupportSubscriptionController::class, 'extend'])->name('subscriptions.extend');

        Route::get('abonnements', [SupportSubscriptionController::class, 'index'])->name('subscriptions.index');
        Route::get('abonnements/plans', [SupportSubscriptionController::class, 'settings'])->name('subscriptions.settings');
        Route::put('abonnements/plans', [SupportSubscriptionController::class, 'updatePlans'])->name('subscriptions.plans');
        Route::put('abonnements/modes', [SupportSubscriptionController::class, 'updateMethods'])->name('subscriptions.methods');
        Route::post('abonnements/{payment}/valider', [SupportSubscriptionController::class, 'approve'])->name('subscriptions.approve');
        Route::post('abonnements/{payment}/refuser', [SupportSubscriptionController::class, 'reject'])->name('subscriptions.reject');
    });
});
