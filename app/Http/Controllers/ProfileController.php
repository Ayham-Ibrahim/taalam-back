<?php

namespace App\Http\Controllers;

use App\Http\Requests\Profile\SyncTimezoneRequest;
use App\Http\Requests\Profile\UpdateMyProfileRequest;
use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Http\Requests\Profile\UploadAvatarRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\Request;

/** إجراءات المستخدم الحالي على حسابه الخاص، بصرف النظر عن الدور */
class ProfileController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function uploadAvatar(UploadAvatarRequest $request)
    {
        $user = $this->authService->updateAvatar($request->user(), $request->file('avatar'));

        return $this->success(new UserResource($user), 'تم تحديث الصورة الشخصية بنجاح');
    }

    public function deleteAvatar(Request $request)
    {
        $user = $this->authService->deleteAvatar($request->user());

        return $this->success(new UserResource($user), 'تم حذف الصورة الشخصية بنجاح');
    }

    public function updateProfile(UpdateMyProfileRequest $request)
    {
        $user = $this->authService->updateProfile($request->user(), $request->validated());

        return $this->success(new UserResource($user), 'تم تحديث البيانات بنجاح');
    }

    /** يُستدعى تلقائياً وبصمت من الفرونت في كل تحميل للتطبيق — بلا أثر إن كان المستخدم قد ثبَّت منطقته يدوياً */
    public function syncTimezone(SyncTimezoneRequest $request)
    {
        $user = $this->authService->syncTimezone($request->user(), $request->validated('timezone'));

        return $this->success(new UserResource($user));
    }

    public function updatePassword(UpdatePasswordRequest $request)
    {
        $this->authService->updatePassword(
            $request->user(),
            $request->validated('current_password'),
            $request->validated('password'),
        );

        return $this->success(null, 'تم تحديث كلمة المرور بنجاح');
    }
}
