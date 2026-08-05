<?php

namespace App\Http\Controllers;

use App\Http\Requests\Taxonomy\TaxonomyItemRequest;
use App\Models\CourseField;
use App\Models\Curriculum;
use App\Models\Language;
use App\Models\Major;
use App\Models\Stage;
use App\Models\Subject;
use App\Models\University;
use Illuminate\Support\Facades\Cache;

class TaxonomyController extends Controller
{
    private const MODELS = [
        'curricula' => Curriculum::class,
        'stages' => Stage::class,
        'subjects' => Subject::class,
        'universities' => University::class,
        'majors' => Major::class,
        'course_fields' => CourseField::class,
        'languages' => Language::class,
    ];

    // الجداول التي لا تملك عمود sort_order (universities/majors/languages)
    private const HAS_SORT_ORDER = [
        'curricula' => true,
        'stages' => true,
        'subjects' => true,
        'universities' => false,
        'majors' => false,
        'course_fields' => true,
        'languages' => false,
    ];

    private const CACHE_TTL = 3600;

    public function index(string $type)
    {
        $modelClass = $this->resolveModel($type);
        $this->authorize('viewAny', $modelClass);

        $items = Cache::remember($this->cacheKey($type), self::CACHE_TTL, function () use ($modelClass, $type) {
            $query = $modelClass::query();

            if (self::HAS_SORT_ORDER[$type]) {
                $query->orderBy('sort_order');
            }

            return $query->orderBy('id')->get();
        });

        return $this->success($items);
    }

    public function store(TaxonomyItemRequest $request, string $type)
    {
        $modelClass = $this->resolveModel($type);
        $this->authorize('create', $modelClass);

        $item = $modelClass::create($request->validated());

        Cache::forget($this->cacheKey($type));

        return $this->success($item, 'تم الإنشاء بنجاح', 201);
    }

    public function update(TaxonomyItemRequest $request, string $type, int $id)
    {
        $modelClass = $this->resolveModel($type);
        $this->authorize('update', $modelClass);

        $item = $modelClass::findOrFail($id);
        $item->update($request->validated());

        Cache::forget($this->cacheKey($type));

        return $this->success($item, 'تم التحديث بنجاح');
    }

    public function destroy(string $type, int $id)
    {
        $modelClass = $this->resolveModel($type);
        $this->authorize('delete', $modelClass);

        $modelClass::findOrFail($id)->delete();

        Cache::forget($this->cacheKey($type));

        return $this->success(null, 'تم الحذف بنجاح');
    }

    private function resolveModel(string $type): string
    {
        abort_unless(isset(self::MODELS[$type]), 404, 'نوع التصنيف غير معروف');

        return self::MODELS[$type];
    }

    private function cacheKey(string $type): string
    {
        return "taxonomy:{$type}";
    }
}
