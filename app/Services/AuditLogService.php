<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * الكاتب الوحيد لسجل التدقيق — كل الخدمات تمر من هنا (مباشرة أو عبر LogsAuditEvents)
 * حتى يبقى شكل السجل (ip/user_agent/user_id) موحداً في كل مكان.
 */
class AuditLogService
{
    public function log(
        string $action,
        ?Model $model = null,
        array $oldValues = [],
        array $newValues = [],
        ?string $notes = null,
        ?int $userId = null,
    ): AuditLog {
        $request = request();

        return AuditLog::create([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'model_type' => $model ? $model::class : null,
            'model_id' => $model?->getKey(),
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'notes' => $notes,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }
}
