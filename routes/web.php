<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AccountRecoveryController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PreviousAccountController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Admin\EmailReplacementController as AdminEmailReplacementController;
use App\Http\Controllers\Admin\UserDeletionController;
use App\Http\Controllers\Auth\EmailReplacementConfirmController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\MobileVerificationController;
use App\Http\Controllers\Contact\ContactController;
use App\Http\Controllers\Admin\AnalyticsController as AdminAnalyticsController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\NotaryController as AdminNotaryController;
use App\Http\Controllers\Admin\RequestController as AdminRequestController;
use App\Http\Controllers\Admin\DocumentController as AdminDocumentController;
use App\Http\Controllers\Admin\MessageController as AdminMessageController;
use App\Http\Controllers\Admin\AuditLogController as AdminAuditLogController;
use App\Http\Controllers\Admin\DeliveryController as AdminDeliveryController;
use App\Http\Controllers\Admin\SecurityController as AdminSecurityController;
use App\Http\Controllers\Admin\ServiceController as AdminServiceController;
use App\Http\Controllers\Admin\VerificationController as AdminVerificationController;
use App\Http\Controllers\Admin\MfaController as AdminMfaController;
use App\Http\Controllers\Admin\ReauthController as AdminReauthController;
use App\Http\Controllers\Notary\PendingController;
use App\Http\Controllers\Notary\RequestController;
use App\Http\Controllers\User\DashboardController as UserDashboardController;
use App\Http\Controllers\User\DocumentController as UserDocumentController;
use App\Http\Controllers\User\RequestController as UserRequestController;
use App\Http\Controllers\User\MobileChangeController as UserMobileChangeController;
use App\Http\Controllers\User\ProfileController as UserProfileController;
use App\Http\Controllers\User\ServiceController as UserServiceController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\DocumentAccessController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfilePhotoController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\VerificationController;

/*
|--------------------------------------------------------------------------
| Public routes (no login needed)
|--------------------------------------------------------------------------
| "throttle:<name>" uses the named limiters in AppServiceProvider (one bucket per feature).
*/

// Home page: landing page with the login and sign-up forms built in.
Route::view('/', 'home.index')->name('home');
Route::view('/login', 'auth.login')->middleware('guest')->name('login');
Route::view('/register', 'auth.register')->middleware('guest')->name('register');
Route::view('/privacy', 'pages.privacy')->name('privacy');
Route::view('/terms', 'pages.terms')->name('terms');
Route::view('/about', 'pages.about')->name('about');
Route::view('/disclaimer', 'pages.disclaimer')->name('disclaimer');
Route::get('/services', [ServiceController::class, 'index'])->name('services.index');
Route::get('/services/{slug}', [ServiceController::class, 'show'])->name('services.show');
Route::post('/locale', [SettingsController::class, 'locale'])->middleware('throttle:portal-write')->name('locale.update');

// Public lookup of demonstration certificates (no personal details are shown).
Route::middleware('throttle:verify-public')->group(function () {
    Route::get('/verify', [VerificationController::class, 'index'])->name('verify.index');
    Route::get('/verify/{code}', [VerificationController::class, 'show'])->name('verify.show');
});

// Login / register are called by JavaScript on the home page and return JSON.
Route::post('/custom-login', [LoginController::class, 'login'])->middleware('throttle:login')->name('login.submit');
Route::post('/custom-register', [RegisterController::class, 'register'])->middleware('throttle:register')->name('register.submit');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

// Contact form (persists the message, emails an acknowledgement + admin notice).
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:contact')->name('contact.store');

// Password reset by email link (Laravel password broker) ...
Route::get('/forgot-password', [ForgotPasswordController::class, 'showForgotForm'])->name('password.request');
Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLink'])->middleware('throttle:password-request')->name('password.email');
Route::get('/reset-password/{token}', [ForgotPasswordController::class, 'showTokenResetForm'])->name('password.reset.token');
Route::post('/reset-password', [ForgotPasswordController::class, 'resetPasswordToken'])->middleware('throttle:password-reset')->name('password.update');
// A new address for a restored account whose owner lost the old mailbox (EmailReplacementService).
// Opening the link changes nothing; only the button on the page does.
Route::get('/email-replacement/{replacement}/{token}', [EmailReplacementConfirmController::class, 'show'])
    ->whereNumber('replacement')->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:password-reset')->name('email-replacement.show');
Route::post('/email-replacement/{replacement}/{token}', [EmailReplacementConfirmController::class, 'confirm'])
    ->whereNumber('replacement')->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:password-reset')->name('email-replacement.confirm');

// ... or by mobile OTP (verify OTP, then set a new password).
Route::post('/verify-otp', [ForgotPasswordController::class, 'verifyOtp'])->middleware('throttle:otp-verify')->name('password.verify.otp');
Route::post('/forgot-password/restart', [ForgotPasswordController::class, 'restart'])->middleware('throttle:password-request')->name('password.otp.restart');
Route::get('/reset-password-form', [ForgotPasswordController::class, 'showResetForm'])->name('password.reset.form');
Route::post('/reset-password-form', [ForgotPasswordController::class, 'resetPassword'])->middleware('throttle:password-reset')->name('password.reset.submit');

/*
|--------------------------------------------------------------------------
| Email verification (login required, signed link)
|--------------------------------------------------------------------------
*/

// The signed link is the proof, so it also works when opened signed out, such as on a phone.
Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware(['signed', 'throttle:verification'])->name('verification.verify');

Route::middleware('auth')->group(function () {
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:verification')->name('verification.send');
    // The 6-digit code from the verification email. 10 tries a minute per account, and OtpService
    // locks the code after 5 wrong ones.
    Route::post('/email/verify/code', [EmailVerificationController::class, 'verifyCode'])
        ->middleware('throttle:otp-verify')->name('verification.code');
});

/*
|--------------------------------------------------------------------------
| Mobile (SMS OTP) verification: compulsory second sign-up step for citizens,
| and optional for approved notaries, whose SMS password recovery depends on it.
|--------------------------------------------------------------------------
| Login + verified email required, but deliberately NOT mobile.verified,
| otherwise nobody could complete the step. In local development this page
| confirms the email too, so it opens first.
*/

Route::middleware(['auth', 'verified.or.bridge', 'account.choice', 'role:public,notary'])->group(function () {
    Route::get('/mobile/verify', [MobileVerificationController::class, 'notice'])->name('verification.mobile.notice');
    Route::post('/mobile/verification-code', [MobileVerificationController::class, 'send'])->middleware('throttle:otp-send')->name('mobile.verification.send');
    Route::post('/mobile/verify', [MobileVerificationController::class, 'verify'])->middleware('throttle:otp-verify')->name('mobile.verification.verify');
    Route::post('/mobile/number', [MobileVerificationController::class, 'changeNumber'])->middleware(['role:public', 'throttle:otp-send'])->name('mobile.verification.number');
});

/*
|--------------------------------------------------------------------------
| A previous account (Welcome Back), reachable only after verification
|--------------------------------------------------------------------------
| Each action checks the person's own state on the server. There are no ids in
| these routes, so one person can never reach another's request.
*/

Route::middleware(['auth', 'schema.ready'])->group(function () {
    Route::get('/account/previous', [PreviousAccountController::class, 'show'])->name('account.previous');
    Route::post('/account/previous/new', [PreviousAccountController::class, 'startNew'])->middleware('throttle:portal-write')->name('account.previous.new');
    Route::get('/account/previous/recover', [PreviousAccountController::class, 'code'])->name('account.previous.code');
    Route::post('/account/previous/recover/code', [PreviousAccountController::class, 'send'])->middleware('throttle:otp-send')->name('account.previous.send');
    Route::post('/account/previous/recover', [PreviousAccountController::class, 'confirm'])->middleware('throttle:otp-verify')->name('account.previous.confirm');
    Route::get('/account/recovery', [PreviousAccountController::class, 'status'])->name('account.recovery.status');
    Route::post('/account/recovery/cancel', [PreviousAccountController::class, 'cancel'])->middleware('throttle:portal-write')->name('account.recovery.cancel');
});

/*
|--------------------------------------------------------------------------
| Logged-in user routes (citizen area; email AND mobile must be verified)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'verified', 'account.choice', 'role:public', 'mobile.verified', 'schema.ready'])->group(function () {
    Route::get('/dashboard', [UserDashboardController::class, 'index'])->name('dashboard');

    // User document upload & listing
    Route::get('/user/upload', [UserDocumentController::class, 'create'])->name('user.upload');
    Route::post('/user/upload', [UserDocumentController::class, 'store'])->middleware('throttle:upload')->name('user.upload.store');
    Route::get('/user/documents', [UserDocumentController::class, 'index'])->name('user.documents');
    Route::get('/user/documents/{document}', [UserDocumentController::class, 'show'])->name('user.documents.show');
    Route::get('/user/documents/{document}/download', [UserDocumentController::class, 'download'])->name('user.documents.download');
    Route::delete('/user/documents/{document}', [UserDocumentController::class, 'destroy'])->name('user.documents.destroy');

    // Service catalogue and the guided application
    Route::get('/user/services', [UserServiceController::class, 'index'])->name('user.services.index');
    Route::get('/user/services/{slug}/apply', [UserServiceController::class, 'apply'])->name('user.services.apply');
    Route::post('/user/services/{slug}/apply', [UserServiceController::class, 'save'])->middleware('throttle:upload')->name('user.services.save');
    Route::delete('/user/services/{slug}/documents/{key}', [UserServiceController::class, 'removeFile'])->middleware('throttle:portal-write')->name('user.services.documents.destroy');
    Route::get('/user/services/{slug}/review', [UserServiceController::class, 'review'])->name('user.services.review');
    Route::post('/user/services/{slug}/submit', [UserServiceController::class, 'submit'])->middleware('throttle:upload')->name('user.services.submit');
    Route::delete('/user/draft', [UserServiceController::class, 'discard'])->middleware('throttle:portal-write')->name('user.services.discard');

    // User requests
    Route::get('/user/requests', [UserRequestController::class, 'index'])->name('user.requests');
    Route::get('/user/requests/{notaryRequest}', [UserRequestController::class, 'show'])->name('user.requests.show');
    Route::post('/user/requests/{notaryRequest}/resubmit', [UserRequestController::class, 'resubmit'])->middleware('throttle:upload')->name('user.requests.resubmit');
    Route::post('/user/requests/{notaryRequest}/withdraw', [UserRequestController::class, 'withdraw'])->middleware('throttle:portal-write')->name('user.requests.withdraw');

    // User profile
    Route::get('/user/profile', [UserProfileController::class, 'edit'])->name('user.profile');
    Route::put('/user/profile', [UserProfileController::class, 'update'])->middleware('throttle:portal-write')->name('user.profile.update');

    // Changing the number: password, then an SMS code to the new number. The old one stays until then.
    Route::post('/user/mobile/change', [UserMobileChangeController::class, 'start'])->middleware('throttle:mobile-change')->name('user.mobile.change');
    Route::post('/user/mobile/change/verify', [UserMobileChangeController::class, 'verify'])->middleware('throttle:otp-verify')->name('user.mobile.change.verify');
    Route::post('/user/mobile/change/resend', [UserMobileChangeController::class, 'resend'])->middleware('throttle:otp-send')->name('user.mobile.change.resend');
    Route::delete('/user/mobile/change', [UserMobileChangeController::class, 'cancel'])->middleware('throttle:portal-write')->name('user.mobile.change.cancel');
});

/*
|--------------------------------------------------------------------------
| Admin routes (login + "admin" role required)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'admin', 'schema.ready'])->group(function () {
    Route::get('/admin/dashboard', [DashboardController::class, 'index'])->name('admin.dashboard');
    Route::get('/admin/analytics', [AdminAnalyticsController::class, 'index'])->name('admin.analytics.index');
    Route::get('/admin/analytics/export.csv', [AdminAnalyticsController::class, 'csv'])->middleware(['admin.reauth', 'throttle:pdf'])->name('admin.analytics.csv');
    Route::get('/admin/analytics/export.xlsx', [AdminAnalyticsController::class, 'xlsx'])->middleware(['admin.reauth', 'throttle:pdf'])->name('admin.analytics.xlsx');
    Route::get('/admin/analytics/export.pdf', [AdminAnalyticsController::class, 'pdf'])->middleware(['admin.reauth', 'throttle:pdf'])->name('admin.analytics.pdf');

    Route::get('/admin/users', [AdminUserController::class, 'index'])->name('admin.users.index');
    Route::get('/admin/users/deleted', [UserDeletionController::class, 'index'])->name('admin.users.deleted');
    // Admin step-up (a fresh two-factor code) and two-factor sign-in. "admin.reauth" on a route means
    // the action needs an authenticator or recovery code entered within accounts.admin_reauth_minutes.
    Route::get('/admin/confirm-password', [AdminReauthController::class, 'show'])->name('admin.reauth.show');
    Route::post('/admin/confirm-password', [AdminReauthController::class, 'confirm'])->middleware('throttle:otp-verify')->name('admin.reauth.confirm');
    Route::get('/admin/security/two-factor', [AdminMfaController::class, 'show'])->name('admin.mfa.show');
    Route::post('/admin/security/two-factor/enroll', [AdminMfaController::class, 'enroll'])->middleware('throttle:portal-write')->name('admin.mfa.enroll');
    Route::post('/admin/security/two-factor/confirm', [AdminMfaController::class, 'confirm'])->middleware('throttle:otp-verify')->name('admin.mfa.confirm');
    Route::post('/admin/security/two-factor/recovery-codes', [AdminMfaController::class, 'regenerate'])->middleware(['admin.reauth', 'throttle:portal-write'])->name('admin.mfa.recovery');
    Route::delete('/admin/security/two-factor', [AdminMfaController::class, 'disable'])->middleware(['admin.reauth', 'throttle:otp-verify'])->name('admin.mfa.disable');
    Route::get('/admin/two-factor-challenge', [AdminMfaController::class, 'challenge'])->name('admin.mfa.challenge');
    Route::post('/admin/two-factor-challenge', [AdminMfaController::class, 'verify'])->middleware('throttle:otp-verify')->name('admin.mfa.challenge.verify');

    Route::delete('/admin/users/{user}/account', [UserDeletionController::class, 'destroy'])->middleware(['admin.reauth', 'throttle:portal-write'])->name('admin.users.delete');
    Route::post('/admin/users/{user}/restore', [UserDeletionController::class, 'restore'])->middleware(['admin.reauth', 'throttle:portal-write'])->name('admin.users.restore');
    Route::post('/admin/account-deletions/{deletion}/retry/{notice}', [UserDeletionController::class, 'retry'])->middleware('throttle:portal-write')->name('admin.users.deletion.retry');
    Route::post('/admin/account-deletions/{deletion}/reset-link', [UserDeletionController::class, 'resendResetLink'])->middleware(['admin.reauth', 'throttle:portal-write'])->name('admin.users.deletion.reset-link');
    Route::get('/admin/users/{user}/email-replacement', [AdminEmailReplacementController::class, 'create'])->name('admin.users.email-replacement');
    Route::post('/admin/users/{user}/email-replacement', [AdminEmailReplacementController::class, 'store'])->middleware(['admin.reauth', 'throttle:portal-write'])->name('admin.users.email-replacement.store');
    Route::post('/admin/email-replacements/{replacement}/cancel', [AdminEmailReplacementController::class, 'cancel'])->middleware(['admin.reauth', 'throttle:portal-write'])->name('admin.email-replacements.cancel');
    Route::get('/admin/account-recovery', [AccountRecoveryController::class, 'index'])->name('admin.recovery.index');
    Route::get('/admin/account-recovery/{recovery}', [AccountRecoveryController::class, 'show'])->name('admin.recovery.show');
    Route::post('/admin/account-recovery/{recovery}/review', [AccountRecoveryController::class, 'review'])->middleware('throttle:portal-write')->name('admin.recovery.review');
    Route::post('/admin/account-recovery/{recovery}/approve', [AccountRecoveryController::class, 'approve'])->middleware(['admin.reauth', 'throttle:portal-write'])->name('admin.recovery.approve');
    Route::post('/admin/account-recovery/{recovery}/reject', [AccountRecoveryController::class, 'reject'])->middleware(['admin.reauth', 'throttle:portal-write'])->name('admin.recovery.reject');
    Route::post('/admin/account-recovery/{recovery}/cancel', [AccountRecoveryController::class, 'cancel'])->middleware(['admin.reauth', 'throttle:portal-write'])->name('admin.recovery.cancel');
    Route::post('/admin/account-recovery/{recovery}/retry/{notice}', [AccountRecoveryController::class, 'retry'])->middleware(['admin.reauth', 'throttle:portal-write'])->name('admin.recovery.retry');

    // User Verification Management: manual verification and temporary access for Citizens and Notaries.
    // Every POST needs a reason, the evidence method, a tick box and the acting Admin's own password.
    Route::get('/admin/verification', [AdminVerificationController::class, 'index'])->name('admin.verification.index');
    Route::get('/admin/verification/{user}', [AdminVerificationController::class, 'show'])->whereNumber('user')->name('admin.verification.show');
    Route::post('/admin/verification/{user}/verify', [AdminVerificationController::class, 'verify'])->whereNumber('user')->middleware(['admin.reauth', 'throttle:portal-write'])->name('admin.verification.verify');
    Route::post('/admin/verification/{user}/temporary-access', [AdminVerificationController::class, 'grant'])->whereNumber('user')->middleware(['admin.reauth', 'throttle:portal-write'])->name('admin.verification.access.grant');
    Route::post('/admin/verification/{user}/temporary-access/{grant}/revoke', [AdminVerificationController::class, 'revoke'])->whereNumber(['user', 'grant'])->middleware(['admin.reauth', 'throttle:portal-write'])->name('admin.verification.access.revoke');

    Route::get('/admin/users/{user}', [AdminUserController::class, 'show'])->name('admin.users.show');
    Route::get('/admin/users/{user}/edit', [AdminUserController::class, 'edit'])->name('admin.users.edit');
    Route::put('/admin/users/{user}', [AdminUserController::class, 'update'])->middleware(['admin.reauth', 'throttle:admin-write'])->name('admin.users.update');
    Route::delete('/admin/users/{user}', [AdminUserController::class, 'destroy'])->middleware(['admin.reauth', 'throttle:admin-write'])->name('admin.users.destroy');
    Route::post('/admin/users/{user}/reactivate', [AdminUserController::class, 'reactivate'])->middleware(['admin.reauth', 'throttle:admin-write'])->name('admin.users.reactivate');

    Route::get('/admin/notaries', [AdminNotaryController::class, 'index'])->name('admin.notaries.index');
    Route::post('/admin/notaries/{user}/approve', [AdminNotaryController::class, 'approve'])->middleware(['admin.reauth', 'throttle:admin-write'])->name('admin.notaries.approve');
    Route::post('/admin/notaries/{user}/reject', [AdminNotaryController::class, 'reject'])->middleware(['admin.reauth', 'throttle:admin-write'])->name('admin.notaries.reject');

    Route::get('/admin/requests', [AdminRequestController::class, 'index'])->name('admin.requests.index');
    Route::get('/admin/requests/{request}', [AdminRequestController::class, 'show'])->name('admin.requests.show');
    Route::post('/admin/requests/{request}/assign', [AdminRequestController::class, 'assign'])->middleware(['admin.reauth', 'throttle:admin-write'])->name('admin.requests.assign');
    Route::post('/admin/requests/{request}/approve', [AdminRequestController::class, 'approve'])->middleware(['admin.reauth', 'throttle:admin-write'])->name('admin.requests.approve');
    Route::post('/admin/requests/{request}/reject', [AdminRequestController::class, 'reject'])->middleware(['admin.reauth', 'throttle:admin-write'])->name('admin.requests.reject');
    Route::post('/admin/requests/{request}/correct', [AdminRequestController::class, 'correct'])->middleware(['admin.reauth', 'throttle:admin-write'])->name('admin.requests.correct');
    Route::post('/admin/requests/{request}/complete', [AdminRequestController::class, 'complete'])->middleware(['admin.reauth', 'throttle:admin-write'])->name('admin.requests.complete');

    Route::get('/admin/documents', [AdminDocumentController::class, 'index'])->name('admin.documents.index');
    Route::get('/admin/documents/{document}', [AdminDocumentController::class, 'show'])->name('admin.documents.show');
    Route::get('/admin/documents/{document}/download', [AdminDocumentController::class, 'download'])->middleware('admin.reauth')->name('admin.documents.download');
    Route::delete('/admin/documents/{document}', [AdminDocumentController::class, 'destroy'])->middleware(['admin.reauth', 'throttle:admin-write'])->name('admin.documents.destroy');

    Route::get('/admin/messages', [AdminMessageController::class, 'index'])->name('admin.messages.index');
    Route::post('/admin/messages/{message}/read', [AdminMessageController::class, 'markRead'])->middleware('throttle:admin-write')->name('admin.messages.read');
    Route::delete('/admin/messages/{message}', [AdminMessageController::class, 'destroy'])->middleware(['admin.reauth', 'throttle:admin-write'])->name('admin.messages.destroy');

    Route::get('/admin/audit-logs', [AdminAuditLogController::class, 'index'])->name('admin.audit-logs.index');

    Route::put('/admin/settings', [SettingsController::class, 'updateSite'])->middleware(['admin.reauth', 'throttle:admin-write'])->name('admin.settings.update');

    Route::get('/admin/services', [AdminServiceController::class, 'index'])->name('admin.services.index');
    Route::get('/admin/services/{slug}/edit', [AdminServiceController::class, 'edit'])->name('admin.services.edit');
    Route::put('/admin/services/{slug}', [AdminServiceController::class, 'update'])->middleware(['admin.reauth', 'throttle:admin-write'])->name('admin.services.update');
    Route::post('/admin/services/{slug}/reset', [AdminServiceController::class, 'reset'])->middleware(['admin.reauth', 'throttle:admin-write'])->name('admin.services.reset');

    Route::get('/admin/security', [AdminSecurityController::class, 'index'])->name('admin.security.index');
    Route::post('/admin/security/check', [AdminSecurityController::class, 'check'])->middleware('throttle:portal-write')->name('admin.security.check');
    Route::get('/admin/security/permissions', [AdminSecurityController::class, 'permissions'])->name('admin.security.permissions');
    Route::get('/admin/security/privacy-requests', [AdminSecurityController::class, 'privacyRequests'])->name('admin.security.privacy');
    Route::patch('/admin/security/privacy-requests/{privacy}', [AdminSecurityController::class, 'updatePrivacyRequest'])->middleware(['admin.reauth', 'throttle:admin-write'])->name('admin.security.privacy.update');
    Route::get('/admin/security/incidents', [AdminSecurityController::class, 'incidents'])->name('admin.security.incidents');
    Route::post('/admin/security/incidents', [AdminSecurityController::class, 'storeIncident'])->middleware(['admin.reauth', 'throttle:admin-write'])->name('admin.security.incidents.store');
    Route::patch('/admin/security/incidents/{incident}', [AdminSecurityController::class, 'updateIncident'])->middleware(['admin.reauth', 'throttle:admin-write'])->name('admin.security.incidents.update');

    Route::get('/admin/delivery', [AdminDeliveryController::class, 'index'])->name('admin.delivery.index');
    Route::post('/admin/delivery/{id}/retry', [AdminDeliveryController::class, 'retry'])->middleware('throttle:portal-write')->name('admin.delivery.retry');
    Route::get('/admin/audit-logs/export.pdf', [ReportController::class, 'auditPdf'])->middleware(['admin.reauth', 'throttle:pdf'])->name('admin.audit-logs.pdf');
});

// A Notary waiting for approval: their own sign-up state only, and no work.
Route::get('/notary/pending', PendingController::class)
    ->middleware(['auth', 'verified', 'account.choice', 'role:notary'])->name('notary.pending');

/*
|--------------------------------------------------------------------------
| Notary routes (login + verified email + approved "notary" account)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'verified', 'account.choice', 'notary', 'schema.ready'])->group(function () {
    Route::get('/notary/dashboard', [RequestController::class, 'index'])->name('notary.dashboard');
    Route::get('/notary/requests', [RequestController::class, 'requests'])->name('notary.requests');
    Route::get('/notary/request/{id}', [RequestController::class, 'show'])->name('notary.request.show');
    Route::post('/notary/request/{id}/approve', [RequestController::class, 'approve'])->name('notary.request.approve');
    Route::post('/notary/request/{id}/reject', [RequestController::class, 'reject'])->name('notary.request.reject');
    Route::post('/notary/request/{id}/correct', [RequestController::class, 'correct'])->name('notary.request.correct');
    Route::post('/notary/request/{id}/complete', [RequestController::class, 'complete'])->name('notary.request.complete');
    Route::get('/notary/request/{id}/documents/{document}/download', [RequestController::class, 'download'])->name('notary.request.document.download');
    Route::get('/notary/profile', [RequestController::class, 'profile'])->name('notary.profile');
    Route::post('/notary/profile/password', [RequestController::class, 'changePassword'])->middleware('throttle:portal-write')->name('notary.profile.password');
});

/*
|--------------------------------------------------------------------------
| Pages shared by the three roles
|--------------------------------------------------------------------------
| "portal" applies each role's entry rules; the controllers and policies then
| limit every record to the people allowed to see it.
*/

Route::middleware(['auth', 'schema.ready', 'account.choice', 'portal'])->group(function () {
    Route::get('/documents/{document}/preview', [DocumentAccessController::class, 'preview'])->middleware('admin.reauth')->name('documents.preview');
    Route::get('/applications/{notaryRequest}/certificate', [DocumentAccessController::class, 'certificate'])->middleware('admin.reauth')->name('applications.certificate');
    Route::middleware('throttle:pdf')->group(function () {
        Route::get('/applications/{notaryRequest}/summary.pdf', [ReportController::class, 'applicationSummary'])->middleware('admin.reauth')->name('applications.summary');
        Route::get('/applications/{notaryRequest}/receipt.pdf', [ReportController::class, 'applicationReceipt'])->middleware('admin.reauth')->name('applications.receipt');
        Route::get('/applications/{notaryRequest}/review-report.pdf', [ReportController::class, 'reviewReport'])->middleware('admin.reauth')->name('applications.review-report');
        Route::get('/applications/{notaryRequest}/certificate.pdf', [ReportController::class, 'certificatePdf'])->middleware('admin.reauth')->name('applications.certificate-pdf');
        Route::get('/appointments/{appointment}/information.pdf', [ReportController::class, 'appointmentPdf'])->middleware('admin.reauth')->name('appointments.pdf');
    });

    Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments.index');
    Route::post('/appointments', [AppointmentController::class, 'store'])->middleware('throttle:portal-write')->name('appointments.store');
    Route::patch('/appointments/{appointment}', [AppointmentController::class, 'update'])->middleware(['admin.reauth', 'throttle:portal-write'])->name('appointments.update');

    Route::get('/support', [SupportController::class, 'index'])->name('support.index');
    Route::post('/support', [SupportController::class, 'store'])->middleware('throttle:portal-write')->name('support.store');
    Route::get('/support/{ticket}', [SupportController::class, 'show'])->name('support.show');
    Route::post('/support/{ticket}/reply', [SupportController::class, 'reply'])->middleware('throttle:portal-write')->name('support.reply');
    Route::post('/support/{ticket}/close', [SupportController::class, 'close'])->middleware('throttle:portal-write')->name('support.close');
    Route::patch('/support/{ticket}/triage', [SupportController::class, 'triage'])->middleware('throttle:portal-write')->name('support.triage');
    Route::get('/support/{ticket}/attachments/{attachment}', [SupportController::class, 'attachment'])->whereNumber('attachment')->middleware(['admin.reauth', 'throttle:pdf'])->name('support.attachment');

    Route::get('/privacy-center', [PrivacyController::class, 'index'])->name('privacy-center.index');
    Route::post('/privacy-center', [PrivacyController::class, 'store'])->middleware('throttle:portal-write')->name('privacy-center.store');
    Route::post('/privacy-center/consent', [PrivacyController::class, 'consent'])->middleware('throttle:portal-write')->name('privacy-center.consent');
    Route::get('/privacy-center/export', [PrivacyController::class, 'export'])->middleware('throttle:pdf')->name('privacy-center.export');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('/notifications/{id}', [NotificationController::class, 'read'])->name('notifications.read');

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');

    Route::post('/profile/photo', [ProfilePhotoController::class, 'store'])->middleware('throttle:portal-write')->name('profile.photo.store');
    Route::delete('/profile/photo', [ProfilePhotoController::class, 'destroy'])->name('profile.photo.destroy');
    Route::get('/profile/photo/{user}', [ProfilePhotoController::class, 'show'])->name('profile.photo.show');

    Route::get('/reports', [ReportController::class, 'index'])->middleware('role:admin,notary')->name('reports.index');
    Route::get('/reports/export.csv', [ReportController::class, 'csv'])->middleware(['role:admin,notary', 'admin.reauth'])->name('reports.csv');
    Route::get('/reports/export.pdf', [ReportController::class, 'pdf'])->middleware(['role:admin,notary', 'admin.reauth', 'throttle:pdf'])->name('reports.pdf');
});
