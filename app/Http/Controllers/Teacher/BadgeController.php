<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\Verification\GrantBadgeRequest;
use App\Http\Requests\Verification\RevokeBadgeRequest;
use App\Models\Badge;
use App\Models\BadgeAward;
use App\Models\Teacher;
use App\Services\VerificationService;

class BadgeController extends Controller
{
    public function __construct(private readonly VerificationService $verificationService) {}

    public function index()
    {
        $this->authorize('viewAny', Badge::class);

        return $this->success(Badge::query()->orderBy('sort_order')->get());
    }

    public function grant(GrantBadgeRequest $request, Teacher $teacher)
    {
        $badge = Badge::findOrFail($request->validated('badge_id'));

        $award = $this->verificationService->grantBadge($teacher, $badge, $request->user());

        return $this->success($award, 'تم منح الشارة بنجاح', 201);
    }

    public function revoke(RevokeBadgeRequest $request, BadgeAward $badgeAward)
    {
        $award = $this->verificationService->revokeBadge($badgeAward, $request->user(), $request->validated('reason'));

        return $this->success($award, 'تم إلغاء الشارة');
    }
}
