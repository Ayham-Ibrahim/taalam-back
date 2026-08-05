<?php

namespace App\Traits;

use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;

/**
 * يُستخدم داخل Services لتسجيل إجراء حساس بحدث عمل واضح (package.approved، booking.manual_created...)
 * بدلاً من الاعتماد على تسجيل CRUD عام لا يحمل دلالة العملية.
 */
trait LogsAuditEvents
{
    protected function audit(
        string $action,
        ?Model $model = null,
        array $oldValues = [],
        array $newValues = [],
        ?string $notes = null,
        ?int $userId = null,
    ): AuditLog {
        return app(AuditLogService::class)->log($action, $model, $oldValues, $newValues, $notes, $userId);
    }
}
