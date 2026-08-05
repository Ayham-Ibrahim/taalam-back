<?php

namespace App\Observers;

use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * تسجيل تلقائي عام لتغييرات نموذج بسيط لا يحتاج اسم حدث مخصص
 * (مثال: Setting → settings.updated). للأحداث ذات الدلالة الخاصة
 * (package.approved، booking.manual_created...) استخدم LogsAuditEvents داخل الـ Service.
 */
class AuditLogObserver
{
    public function __construct(private readonly AuditLogService $auditLogService) {}

    public function updated(Model $model): void
    {
        $changes = $model->getChanges();
        unset($changes['updated_at']);

        if (empty($changes)) {
            return;
        }

        $original = array_intersect_key($model->getOriginal(), $changes);

        $this->auditLogService->log(
            $this->actionName($model, 'updated'),
            $model,
            $original,
            $changes,
        );
    }

    public function deleted(Model $model): void
    {
        $this->auditLogService->log(
            $this->actionName($model, 'deleted'),
            $model,
            $model->getOriginal(),
        );
    }

    private function actionName(Model $model, string $event): string
    {
        return Str::snake(Str::pluralStudly(class_basename($model))).'.'.$event;
    }
}
