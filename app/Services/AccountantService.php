<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\AccountCreatedByAdmin;
use Illuminate\Support\Facades\DB;

/** إنشاء حساب محاسب يضع الأدمن كلمة مروره مباشرة — يوازي StudentService::createByAdmin. */
class AccountantService
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function createByAdmin(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'role' => 'accountant',
                'password' => $data['password'],
                'is_active' => true,
            ]);

            $this->notifications->send(
                $user,
                new AccountCreatedByAdmin('accountant', $data['password']),
                'accountant.account_created',
            );

            return $user;
        });
    }
}
