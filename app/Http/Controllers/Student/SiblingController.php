<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\AddSiblingRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\SiblingAccountService;
use Illuminate\Http\Request;

/** حسابات الإخوة — راجع SiblingAccountService */
class SiblingController extends Controller
{
    public function __construct(private readonly SiblingAccountService $siblings) {}

    /** كل حسابات العائلة (الأساسي + الإخوة) لمبدّل الحسابات في الواجهة */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->isStudent(), 403);

        $family = $this->siblings->family($user)->map(fn (User $member) => [
            ...(new UserResource($member))->resolve($request),
            'is_current' => $member->id === $user->id,
        ]);

        return $this->success($family);
    }

    public function store(AddSiblingRequest $request)
    {
        $sibling = $this->siblings->add($request->user(), $request->validated());

        return $this->success(new UserResource($sibling), 'تمت إضافة الأخ بنجاح', 201);
    }

    /** الواجهة تستبدل التوكن المحفوظ بالتوكن المُعاد — التوكن السابق يُبطَل */
    public function switch(Request $request, User $user)
    {
        abort_unless($request->user()->isStudent(), 403);

        $result = $this->siblings->switchTo($request->user(), $user);

        return $this->success([
            'user' => new UserResource($result['user']),
            'token' => $result['token'],
        ], 'تم التبديل إلى حساب '.$result['user']->name);
    }
}
