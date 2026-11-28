<?php

namespace App\Http\Requests\Chat;

use App\Models\Conversation;
use Illuminate\Foundation\Http\FormRequest;

/** زر "مراسلة" في صفحات الحجز/الكورس/قائمة الطلاب: يجلب المحادثة القائمة مع ذلك المستخدم */
class FindConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Conversation::class);
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
