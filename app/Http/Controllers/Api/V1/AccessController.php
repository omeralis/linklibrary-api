<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\GrantStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SharedLinksRequest;
use App\Http\Resources\GrantResource;
use App\Http\Resources\SavedLinkResource;
use App\Models\LinkAccessGrant;
use App\Models\User;
use App\Services\Search\LinkQueryService;
use App\Services\Sharing\AccessGraphService;
use App\Services\Sharing\InvitationService;
use App\Services\Sharing\LinkAccessResolver;
use App\Services\Sharing\LinkViewContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccessController extends Controller
{
    public function __construct(
        private readonly LinkAccessResolver $resolver,
        private readonly AccessGraphService $graph,
        private readonly InvitationService $invitations,
        private readonly LinkQueryService $queries,
    ) {}

    /**
     * Owner: every grant (the whole access graph, with effective flags).
     * Viewer: only the grants they issued themselves.
     */
    public function index(Request $request, string $publicId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $context = $this->resolver->resolve($publicId, $user);
        if ($context->role === LinkViewContext::PUBLIC) {
            throw ApiException::notFound();
        }
        $savedLink = $context->savedLink;

        $grants = LinkAccessGrant::query()->with(['grantee', 'grantor'])
            ->where('saved_link_id', $savedLink->id)
            ->orderBy('depth')->orderBy('id')
            ->get();

        // Effective validity computed over the whole (bounded) graph of this link.
        $byId = $grants->keyBy('id');
        $valid = [];
        foreach ($grants as $g) {
            $parent = $g->parent_grant_id !== null ? $byId->get($g->parent_grant_id) : null;
            $valid[$g->id] = $g->status === GrantStatus::ACTIVE && (
                ($g->parent_grant_id === null && $g->grantor_user_id === $savedLink->user_id)
                || ($parent !== null && ($valid[$parent->id] ?? false) && $parent->grantee_user_id === $g->grantor_user_id)
            );
            $g->setAttribute('effective', $valid[$g->id]);
        }
        GrantResource::$publicIds = $grants->pluck('public_id', 'id')->all();

        $visible = $context->isOwner() ? $grants : $grants->where('grantor_user_id', $user->id)->values();

        return ApiResponse::success(GrantResource::collection($visible)->resolve($request), meta: [
            'allow_resharing' => $savedLink->allow_resharing,
            'viewer_count' => collect($grants)->filter(fn ($g) => $valid[$g->id])->pluck('grantee_user_id')->unique()->count(),
        ]);
    }

    /**
     * Revoke a grant. Owner: any grant. Grantor: grants they issued. Grantee: their own grant (leave).
     * Dependent grants are revoked with it; independent paths are unaffected.
     */
    public function destroy(Request $request, string $publicId, string $grantId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $context = $this->resolver->resolve($publicId, $user);
        if ($context->role === LinkViewContext::PUBLIC || ! LinkAccessGrant::isValidPublicId($grantId)) {
            throw ApiException::notFound();
        }

        $grant = LinkAccessGrant::query()->where('saved_link_id', $context->savedLink->id)->where('public_id', $grantId)->first();
        if ($grant === null) {
            throw ApiException::notFound();
        }

        $allowed = $context->isOwner() || $grant->grantor_user_id === $user->id || $grant->grantee_user_id === $user->id;
        if (! $allowed) {
            throw ApiException::notFound();
        }

        $affected = $this->graph->revokeGrant($grant, $user, $grant->grantee_user_id === $user->id && ! $context->isOwner() ? 'LEFT' : 'REVOKED');
        $this->invitations->notifyRevoked($context->savedLink, array_values(array_diff($affected, [$user->id])));

        return ApiResponse::success(['revoked_users' => count($affected)], 'api.sharing.access_revoked');
    }

    public function sharedWithMe(SharedLinksRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $page = $this->queries->sharedWithMe($user, $request->validated(), ApiResponse::perPage());

        return ApiResponse::paginated($page, fn ($savedLink) => SavedLinkResource::forRole($savedLink, LinkViewContext::VIEWER));
    }
}
