<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InvitationStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\InvitationRequest;
use App\Http\Resources\InvitationResource;
use App\Http\Resources\ReceivedInvitationResource;
use App\Http\Resources\SavedLinkResource;
use App\Models\LinkInvitation;
use App\Models\User;
use App\Services\Sharing\InvitationService;
use App\Services\Sharing\LinkAccessResolver;
use App\Services\Sharing\LinkViewContext;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvitationController extends Controller
{
    public function __construct(private readonly InvitationService $invitations, private readonly LinkAccessResolver $resolver) {}

    public function store(InvitationRequest $request, string $publicId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $context = $this->resolver->resolve($publicId, $user);
        if ($context->role === LinkViewContext::PUBLIC) {
            throw ApiException::notFound(); // public visibility never implies permission to invite
        }

        [$invitation] = $this->invitations->invite($context->savedLink, $user, $request->string('email')->toString());

        return ApiResponse::created(new InvitationResource($invitation->load('inviter')), 'api.sharing.invitation_sent');
    }

    public function index(Request $request, string $publicId): JsonResponse
    {
        $context = $this->resolver->resolve($publicId, $request->user());
        if ($context->role === LinkViewContext::PUBLIC) {
            throw ApiException::notFound();
        }

        $query = LinkInvitation::query()->with('inviter')->where('saved_link_id', $context->savedLink->id)->latest('id');
        if (! $context->isOwner()) {
            $query->where('inviter_user_id', $request->user()->id);
        }

        return ApiResponse::success(InvitationResource::collection($query->paginate(ApiResponse::perPage())));
    }

    public function destroy(Request $request, string $publicId, string $invitationId): JsonResponse
    {
        $context = $this->resolver->resolve($publicId, $request->user());
        if ($context->role === LinkViewContext::PUBLIC || ! LinkInvitation::isValidPublicId($invitationId)) {
            throw ApiException::notFound();
        }

        $invitation = LinkInvitation::query()
            ->where('saved_link_id', $context->savedLink->id)
            ->where('public_id', $invitationId)
            ->when(! $context->isOwner(), fn (Builder $q) => $q->where('inviter_user_id', $request->user()->id))
            ->first() ?? throw ApiException::notFound();

        $this->invitations->revoke($invitation, $request->user());

        return ApiResponse::message('api.sharing.invitation_revoked');
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $savedLink = $this->invitations->acceptByToken($token, $request->user());

        return $this->accepted($request, $savedLink->public_id);
    }

    public function acceptById(Request $request, string $invitationId): JsonResponse
    {
        $savedLink = $this->invitations->acceptById($invitationId, $request->user());

        return $this->accepted($request, $savedLink->public_id);
    }

    /** Pending invitations addressed to my verified e-mail address. */
    public function received(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user->hasVerifiedEmail()) {
            return ApiResponse::success([], meta: ['email_verified' => false]);
        }

        $page = LinkInvitation::query()->with('inviter')
            ->where('email', $user->email)
            ->where('status', InvitationStatus::PENDING->value)
            ->where('expires_at', '>', now())
            ->whereHas('savedLink', fn (Builder $q) => $q->whereNull('deleted_at'))
            ->latest('id')
            ->paginate(ApiResponse::perPage());

        return ApiResponse::success(ReceivedInvitationResource::collection($page));
    }

    private function accepted(Request $request, string $publicId): JsonResponse
    {
        $context = $this->resolver->resolve($publicId, $request->user());

        return ApiResponse::success(SavedLinkResource::fromContext($context), 'api.sharing.invitation_accepted');
    }
}
