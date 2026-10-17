<?php

namespace App\Http\Controllers\AcademicSupporter;

use App\Http\Controllers\Controller;
use App\Http\Resources\AcademicSupporter\PublicAcademicSupporterResource;
use App\Models\AcademicSupporter;
use Illuminate\Http\Request;

/**
 * تصفّح/بروفايل الداعم الأكاديمي — حصراً للطالب الجامعي من داخل لوحته (قرار
 * صريح: "لا يظهر هذا الكارد للعامة"). التحقق من النوع هنا أيضاً (لا فقط إخفاء
 * التاب بالواجهة) — دفاع متعدد الطبقات كبقية أجزاء هذا النظام.
 */
class AcademicSupporterSearchController extends Controller
{
    public function index(Request $request)
    {
        $this->assertUniversityStudent($request);

        $supporters = AcademicSupporter::query()
            ->select(['id', 'user_id', 'bio', 'qualification', 'experience_years'])
            ->where('status', 'active')
            ->when($request->filled('subject_id'), function ($q) use ($request) {
                $q->whereHas('subjects', fn ($sq) => $sq->where('subjects.id', $request->integer('subject_id')));
            })
            ->with(['user:id,name,avatar_path', 'subjects:id,name_ar'])
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->paginate($supporters->through(fn (AcademicSupporter $s) => new PublicAcademicSupporterResource($s)));
    }

    public function show(Request $request, AcademicSupporter $academicSupporter)
    {
        $this->assertUniversityStudent($request);

        abort_unless($academicSupporter->status === 'active', 404);

        $academicSupporter->load([
            'user:id,name,avatar_path',
            'subjects:id,name_ar',
            'languages:id,code,name_ar',
            'galleryItems',
            'supportPackages' => fn ($q) => $q->where('status', 'active')->with('subject:id,name_ar'),
        ]);

        return $this->success(new PublicAcademicSupporterResource($academicSupporter));
    }

    private function assertUniversityStudent(Request $request): void
    {
        $user = $request->user();
        $student = $user?->isStudent() ? $user->loadMissing('student')->student : null;

        abort_unless($student?->education_type === 'university', 403, 'هذا القسم مخصص للطلاب الجامعيين فقط');
    }
}
