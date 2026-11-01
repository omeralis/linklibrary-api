<?php

namespace App\Services\Discovery;

use App\Models\SavedLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Records at most one view per (link, viewer fingerprint, day). The fingerprint is an HMAC of the
 * user id (or IP + user agent for guests), so raw identifiers are never stored.
 */
class ViewRecorder
{
    public function record(SavedLink $savedLink, Request $request): void
    {
        $user = $request->user();
        if ($user !== null && $user->id === $savedLink->user_id) {
            return;
        }

        $identity = $user !== null ? 'u:'.$user->id : 'g:'.$request->ip().'|'.substr((string) $request->userAgent(), 0, 200);

        try {
            DB::table('link_views')->insertOrIgnore([
                'saved_link_id' => $savedLink->id,
                'viewer_hash' => hash_hmac('sha256', $identity, (string) config('app.key')),
                'viewed_on' => now()->toDateString(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
