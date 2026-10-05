<?php

namespace App\Http\Controllers\Accountant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accountant\CreateAccountantRequest;
use App\Services\AccountantService;

class AccountantController extends Controller
{
    public function __construct(private readonly AccountantService $accountantService) {}

    /** الأدمن فقط يُنشئ حسابات المحاسبين (CreateAccountantRequest::authorize) */
    public function store(CreateAccountantRequest $request)
    {
        $user = $this->accountantService->createByAdmin($request->validated());

        return $this->success(['id' => $user->id, 'name' => $user->name, 'email' => $user->email], 'تم إنشاء حساب المحاسب بنجاح', 201);
    }
}
