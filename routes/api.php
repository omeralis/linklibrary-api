<?php

use App\Http\Controllers\Api\V1\AccessController;
use App\Http\Controllers\Api\V1\Admin\AdminAuditLogController;
use App\Http\Controllers\Api\V1\Admin\AdminCategoryController;
use App\Http\Controllers\Api\V1\Admin\AdminSecurityController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\LinkController;
use App\Http\Controllers\Api\V1\LinkSecurityController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PublicController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\TagController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1  (prefix: /api/v1 — configured in bootstrap/app.php)
|--------------------------------------------------------------------------
| Every route under "auth:sanctum" also passes through "active" (suspended accounts are refused).
| Owner-only operations are additionally scoped in the controllers (non-owners receive 404).
*/

// ---------------------------------------------------------------- Authentication (guests)
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:password-reset');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset');
    Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed:relative', 'throttle:verification'])
        ->name('verification.verify');
});

// ---------------------------------------------------------------- Public (no authentication)
Route::prefix('public')->middleware('throttle:public')->group(function () {
    Route::get('links', [PublicController::class, 'links']);
    Route::get('features', [PublicController::class, 'features']);
    Route::get('links/{publicId}', [PublicController::class, 'link']);
    Route::get('categories', [PublicController::class, 'categories']);
    Route::get('tags', [PublicController::class, 'tags']);
    Route::get('users/{username}', [PublicController::class, 'user']);
    Route::get('users/{username}/links', [PublicController::class, 'userLinks']);
    Route::get('users/{username}/collections', [PublicController::class, 'userCollections']);
    Route::get('collections/{publicId}', [PublicController::class, 'collection']);
    Route::get('search', [SearchController::class, 'public'])->middleware('throttle:search');
});

// ---------------------------------------------------------------- Authenticated
Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
    // Auth session
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/email/verification-notification', [EmailVerificationController::class, 'resend'])->middleware('throttle:verification');

    // Profile & account
    Route::get('me', [ProfileController::class, 'show']);
    Route::patch('me', [ProfileController::class, 'update']);
    Route::patch('me/password', [ProfileController::class, 'updatePassword']);
    Route::post('me/avatar', [ProfileController::class, 'uploadAvatar']);
    Route::delete('me/avatar', [ProfileController::class, 'deleteAvatar']);
    Route::delete('me', [ProfileController::class, 'destroy']);
    Route::get('me/sessions', [SessionController::class, 'index']);
    Route::delete('me/sessions/{sessionId}', [SessionController::class, 'destroy']);
    Route::post('me/sessions/revoke-others', [SessionController::class, 'revokeOthers']);
    Route::get('me/tokens', [SessionController::class, 'tokens']);
    Route::delete('me/tokens/{tokenId}', [SessionController::class, 'destroyToken'])->whereNumber('tokenId');
    Route::get('me/invitations', [InvitationController::class, 'received']);
    Route::post('me/invitations/{invitationId}/accept', [InvitationController::class, 'acceptById'])->middleware('throttle:invitation-accept');

    // Saved links
    Route::get('links', [LinkController::class, 'index']);
    Route::post('links', [LinkController::class, 'store'])->middleware('throttle:link-create');
    Route::get('links/{publicId}', [LinkController::class, 'show']);
    Route::patch('links/{publicId}', [LinkController::class, 'update']);
    Route::delete('links/{publicId}', [LinkController::class, 'destroy']);
    Route::post('links/{publicId}/restore', [LinkController::class, 'restore']);
    Route::post('links/{publicId}/favorite', [LinkController::class, 'favorite']);
    Route::patch('links/{publicId}/visibility', [LinkController::class, 'visibility']);
    Route::patch('links/{publicId}/sharing-settings', [LinkController::class, 'sharingSettings']);
    Route::post('links/{publicId}/tags', [LinkController::class, 'addTags']);
    Route::delete('links/{publicId}/tags/{tagSlug}', [LinkController::class, 'removeTag']);
    Route::post('links/{publicId}/cover', [LinkController::class, 'uploadCover']);
    Route::delete('links/{publicId}/cover', [LinkController::class, 'deleteCover']);
    Route::post('links/{publicId}/reanalyze', [LinkController::class, 'reanalyze'])->middleware('throttle:reanalyze');
    Route::get('links/{publicId}/processing-status', [LinkController::class, 'processingStatus']);
    Route::get('links/{publicId}/open', [LinkController::class, 'open']);

    // Link security
    Route::get('links/{publicId}/security', [LinkSecurityController::class, 'show']);
    Route::post('links/{publicId}/security/rescan', [LinkSecurityController::class, 'rescan'])->middleware('throttle:rescan');
    Route::post('links/{publicId}/security/appeal', [LinkSecurityController::class, 'appeal'])->middleware('throttle:appeals');

    // Invitations & access
    Route::post('links/{publicId}/invitations', [InvitationController::class, 'store'])->middleware(['verified', 'throttle:invitations']);
    Route::get('links/{publicId}/invitations', [InvitationController::class, 'index']);
    Route::delete('links/{publicId}/invitations/{invitationId}', [InvitationController::class, 'destroy']);
    Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])->middleware('throttle:invitation-accept');
    Route::get('links/{publicId}/access', [AccessController::class, 'index']);
    Route::delete('links/{publicId}/access/{grantId}', [AccessController::class, 'destroy']);
    Route::get('shared-with-me', [AccessController::class, 'sharedWithMe']);

    // Collections
    Route::get('collections', [CollectionController::class, 'index']);
    Route::post('collections', [CollectionController::class, 'store']);
    Route::get('collections/{publicId}', [CollectionController::class, 'show']);
    Route::patch('collections/{publicId}', [CollectionController::class, 'update']);
    Route::delete('collections/{publicId}', [CollectionController::class, 'destroy']);
    Route::post('collections/{publicId}/links', [CollectionController::class, 'addLinks']);
    Route::delete('collections/{publicId}/links/{savedLinkPublicId}', [CollectionController::class, 'removeLink']);
    Route::patch('collections/{publicId}/reorder', [CollectionController::class, 'reorder']);

    // Taxonomy
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('tags', [TagController::class, 'index']);

    // Search
    Route::get('search', [SearchController::class, 'index'])->middleware('throttle:search');

    // Notifications
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::patch('notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);

    // Administration (role-gated)
    Route::prefix('admin')->middleware('can:admin')->group(function () {
        Route::get('security/appeals', [AdminSecurityController::class, 'appeals']);
        Route::post('security/appeals/{appealId}/resolve', [AdminSecurityController::class, 'resolveAppeal']);
        Route::patch('security/links/{linkId}', [AdminSecurityController::class, 'setVerdict'])->whereNumber('linkId');
        Route::get('categories', [AdminCategoryController::class, 'index']);
        Route::post('categories', [AdminCategoryController::class, 'store']);
        Route::patch('categories/{slug}', [AdminCategoryController::class, 'update']);
        Route::delete('categories/{slug}', [AdminCategoryController::class, 'destroy']);
        Route::get('audit-logs', [AdminAuditLogController::class, 'index']);
    });
});
