<?php

namespace App\Http\Requests\Coupon;

use App\Http\Requests\Coupon\Concerns\ValidatesMultiPackageCouponCap;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** كوبون أدمن متعدد الباقات — الأدمن فقط، باقات المعلمين ودورات المراكز التدريبية (لا دعم أكاديمي) */
class CreateMultiPackageCouponRequest extends FormRequest
{
    use ValidatesMultiPackageCouponCap;

    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'min:4', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('coupons', 'code')],
            'package_ids' => ['nullable', 'array', 'required_without:course_ids'],
            'package_ids.*' => ['integer', 'distinct', Rule::exists('packages', 'id')],
            'course_ids' => ['nullable', 'array', 'required_without:package_ids'],
            'course_ids.*' => ['integer', 'distinct', Rule::exists('courses', 'id')],
            'discount_type' => ['required', Rule::in(['percent', 'fixed'])],
            'discount_value' => ['required', 'numeric', 'min:0.01'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function messages(): array
    {
        return [
            'package_ids.required_without' => 'اختر باقة أو دورة واحدة على الأقل.',
            'course_ids.required_without' => 'اختر باقة أو دورة واحدة على الأقل.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $packageIds = $this->input('package_ids') ?? [];
            $courseIds = $this->input('course_ids') ?? [];

            if (count($packageIds) + count($courseIds) === 0) {
                $validator->errors()->add('package_ids', 'اختر باقة أو دورة واحدة على الأقل.');

                return;
            }

            $this->validateCap(
                $validator,
                $packageIds,
                $courseIds,
                $this->input('discount_type'),
                (float) $this->input('discount_value'),
            );
        });
    }
}
