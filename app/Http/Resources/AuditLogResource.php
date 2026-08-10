<?php

namespace App\Http\Resources;

use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\AuditLog
 */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'targetLabel' => $this->resolveTargetLabel(),
            'adminName' => $this->user?->name,
            'createdAt' => $this->created_at,
        ];
    }

    /**
     * لا توجد صياغة موحدة لكل نموذج، لذا نعتمد محاولة عامة: اسم المستخدم
     * لمعلم/طالب، ثم أول حقل وصفي متوفر على النموذج، ثم fallback باسم
     * الصنف ورقم المعرّف.
     */
    private function resolveTargetLabel(): ?string
    {
        if (! $this->model_type || ! $this->model_id || ! class_exists($this->model_type)) {
            return $this->notes;
        }

        $model = $this->model_type::find($this->model_id);

        if (! $model) {
            return $this->notes ?? (class_basename($this->model_type)." #{$this->model_id}");
        }

        if ($model instanceof Teacher || $model instanceof Student) {
            $name = $model->loadMissing('user')->user?->name;

            if ($name) {
                return $name;
            }
        }

        foreach (['title', 'name_ar', 'name', 'reference', 'label_ar', 'key'] as $attribute) {
            if (! empty($model->{$attribute})) {
                return (string) $model->{$attribute};
            }
        }

        return class_basename($model)." #{$this->model_id}";
    }
}
