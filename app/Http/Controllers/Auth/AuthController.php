<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterStudentRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function registerStudent(RegisterStudentRequest $request)
    {
        $result = $this->authService->registerStudent($request->validated());

        return $this->success($result, 'تم إنشاء الحساب بنجاح', 201);
    }

    public function login(LoginRequest $request)
    {
        $result = $this->authService->login(
            $request->validated('email'),
            $request->validated('password'),
        );

        return $this->success([
            'user' => new UserResource($result['user']),
            'token' => $result['token'],
        ], 'تم تسجيل الدخول بنجاح');
    }

    public function logout(Request $request)
    {
        $this->authService->logout($request->user());

        return $this->success(null, 'تم تسجيل الخروج بنجاح');
    }

    public function me(Request $request)
    {
        $user = $this->authService->loadRoleRelation($request->user());

        return $this->success(new UserResource($user));
    }
}
