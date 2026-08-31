<?php

namespace App\Http\Controllers;

use App\Http\Resources\FavoriteResource;
use App\Models\Course;
use App\Models\Favorite;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function index(Request $request)
    {
        $student = $request->user()->loadMissing('student')->student;

        $favorites = Favorite::where('student_id', $student?->id ?? 0)
            // معلم مفضَّل يفقد توثيقه لاحقاً (تعليق، رفض...) يجب أن يختفي من
            // المفضلة تماماً — نفس معيار الظهور العام المطبَّق في كل مكان آخر
            // (TeacherSearchController، TeacherPolicy::viewPublicProfile). سطر
            // المفضلة نفسه يبقى في القاعدة فيعود ظاهراً تلقائياً لو اعتُمد
            // المعلم مجدداً، دون أن يحتاج الطالب لإعادة الإضافة.
            ->where(function ($query) {
                $query->where('favoritable_type', Course::class)
                    ->orWhereHasMorph('favoritable', [Teacher::class], fn ($q) => $q->where('status', 'verified'));
            })
            ->with(['favoritable' => function (MorphTo $morphTo) {
                $morphTo->morphWith([
                    Teacher::class => ['user:id,name,avatar_path', 'subjects:id,name_ar'],
                    Course::class => ['teacher.user:id,name,avatar_path', 'subject:id,name_ar'],
                ]);
            }])
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->paginate($favorites->through(fn (Favorite $favorite) => new FavoriteResource($favorite)));
    }

    public function toggleTeacher(Request $request, Teacher $teacher)
    {
        return $this->toggle($request, $teacher);
    }

    public function toggleCourse(Request $request, Course $course)
    {
        return $this->toggle($request, $course);
    }

    private function toggle(Request $request, Model $favoritable)
    {
        $student = $request->user()->loadMissing('student')->student;

        abort_if(! $student, 403, 'الطلاب فقط يمكنهم إضافة المفضلة');

        $existing = Favorite::where('student_id', $student->id)
            ->where('favoritable_type', $favoritable::class)
            ->where('favoritable_id', $favoritable->id)
            ->first();

        if ($existing) {
            $existing->delete();

            return $this->success(['favorited' => false], 'تمت الإزالة من المفضلة');
        }

        Favorite::create([
            'student_id' => $student->id,
            'favoritable_type' => $favoritable::class,
            'favoritable_id' => $favoritable->id,
        ]);

        return $this->success(['favorited' => true], 'تمت الإضافة للمفضلة', 201);
    }
}
