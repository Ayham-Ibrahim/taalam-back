<?php

namespace App\Http\Controllers;

use App\Http\Requests\Profile\UploadAvatarRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;

/** إجراءات المستخدم الحالي على حسابه الخاص، بصرف النظر عن الدور */
class ProfileController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function uploadAvatar(UploadAvatarRequest $request)
    {
        $user = $this->authService->updateAvatar($request->user(), $request->file('avatar'));

        return $this->success(new UserResource($user), 'تم تحديث الصورة الشخصية بنجاح');
    }
}
