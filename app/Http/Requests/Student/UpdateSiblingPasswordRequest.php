<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * الطالب الأساسي يضع كلمة مرور جديدة لأحد إخوته — بلا كلمة المرور القديمة.
 * التحقق من أن الأخ تابع لهذا الأساسي في SiblingAccountService::updatePassword.
 */
class UpdateSiblingPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user->isStudent() && ! $user->isSiblingAccount();
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    public function attributes(): array
    {
        return [
            'password' => 'كلمة المرور الجديدة',
        ];
    }
}
