<?php

namespace App\Http\Controllers\Student;

use App\Exports\StudentsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Student\AdminResetStudentPasswordRequest;
use App\Http\Requests\Student\AdminUpdateStudentAccountRequest;
use App\Http\Requests\Student\CreateStudentAccountRequest;
use App\Http\Requests\Student\UpdateStudentProfileRequest;
use App\Http\Requests\Student\UploadStudentAvatarRequest;
use App\Http\Resources\Student\StudentIndexResource;
use App\Http\Resources\Student\StudentProfileResource;
use App\Models\Student;
use App\Services\AuthService;
use App\Services\StudentService;
use App\Support\StudentLocationRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class StudentController extends Controller
{
    public function __construct(
        private readonly StudentService $studentService,
        private readonly AuthService $authService,
    ) {}

    /** الأدمن يضع كلمة المرور مباشرة — يوازي TeacherController::createAccount */
    public function store(CreateStudentAccountRequest $request)
    {
        $student = $this->studentService->createByAdmin($request->validated(), $request->user());

        return $this->success($student, 'تم إنشاء حساب الطالب بنجاح', 201);
    }

    /** إكمال الملف الشخصي بعد أول دخول (StudentPolicy::update — الطالب نفسه فقط) */
    public function update(UpdateStudentProfileRequest $request, Student $student)
    {
        $student = $this->studentService->completeProfile($student, $request->validated());

        return $this->success(new StudentProfileResource($student), 'تم تحديث الملف الشخصي بنجاح');
    }

    /**
     * الطالب يحدد دولته وولايته وحدهما — PUT /students/{id} يتطلب كل الحقول الأكاديمية،
     * وهذا لنافذة تطلب الموقع فقط ممن أكمل ملفه قبل إضافته.
     */
    public function updateMyLocation(Request $request)
    {
        $student = $request->user()->student;
        abort_unless($student, 403);

        $data = $request->validate(
            StudentLocationRules::rules($request->input('country_id'), requireCountry: true),
            StudentLocationRules::messages(),
        );

        $student->update(['country_id' => $data['country_id'], 'region_id' => $data['region_id'] ?? null]);

        return $this->success($student->only(['country_id', 'region_id']), 'تم حفظ الموقع');
    }

    /** بحث/فهرسة الطلاب — يغذي منتقي الحجز اليدوي لدى الأدمن (StudentPolicy::viewAny) */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Student::class);

        $students = $this->filteredQuery($request)
            ->with(['user:id,name,email,phone,avatar_path,is_active,created_at', 'locationCountry:id,name_ar', 'region:id,name_ar', 'creator:id,name,role'])
            ->paginate($request->integer('per_page', 20));

        return $this->paginate($students->through(fn (Student $student) => new StudentIndexResource($student)));
    }

    /** نفس قائمة index (بفلاترها) كملف Excel كامل بلا صفحات — بالأعمدة المختارة (columns[]) أو كلها */
    public function export(Request $request)
    {
        $this->authorize('viewAny', Student::class);

        $columns = $request->validate(StudentsExport::columns()->rules())['columns'] ?? null;

        return Excel::download(new StudentsExport($this->filteredQuery($request), $columns), 'students.xlsx');
    }

    /** الأعمدة المتاحة للتصدير — تعرضها الواجهة للاختيار */
    public function exportColumns()
    {
        $this->authorize('viewAny', Student::class);

        return $this->success(StudentsExport::columns()->options());
    }

    /** فلاتر صفحة إدارة الطلاب — مشتركة بين القائمة والتصدير كي يطابق الملف ما يراه الأدمن */
    private function filteredQuery(Request $request): Builder
    {
        return Student::query()
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search');
                $query->whereHas('user', function ($userQuery) use ($search) {
                    $userQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('education_type'), fn ($q) => $q->where('students.education_type', $request->string('education_type')->value()))
            ->when($request->filled('country_id'), fn ($q) => $q->where('students.country_id', $request->integer('country_id')))
            ->when($request->filled('region_id'), fn ($q) => $q->where('students.region_id', $request->integer('region_id')))
            ->latest('students.created_at');
    }

    public function show(Student $student)
    {
        $this->authorize('view', $student);

        $student->load(['user:id,name,email,phone,avatar_path,whatsapp,gender', 'curriculum:id,name_ar', 'locationCountry:id,name_ar', 'region:id,name_ar', 'stage:id,name_ar', 'university:id,name_ar', 'major:id,name_ar', 'courseField:id,name_ar']);

        return $this->success(new StudentProfileResource($student));
    }

    /** الأدمن يعدّل اسم/بريد/هاتف الطالب — منفصل عن update الذي يخص الملف الأكاديمي */
    public function updateAccount(AdminUpdateStudentAccountRequest $request, Student $student)
    {
        $student = $this->studentService->updateAccountByAdmin($student, $request->validated(), $request->user());

        return $this->success(new StudentProfileResource($student), 'تم تحديث بيانات الطالب بنجاح');
    }

    /** الأدمن يعيد تعيين كلمة مرور طالب مباشرة — بلا حاجة لمعرفة القديمة، ترسَل الجديدة بالبريد */
    public function resetPassword(AdminResetStudentPasswordRequest $request, Student $student)
    {
        $this->studentService->resetPasswordByAdmin($student, $request->validated('password'), $request->user());

        return $this->success(null, 'تم تغيير كلمة مرور الطالب بنجاح، وأُرسلت له عبر البريد الإلكتروني');
    }

    /** الأدمن يرفع صورة نيابة عن طالب — يوازي TeacherController::uploadAvatar تماماً */
    public function uploadAvatar(UploadStudentAvatarRequest $request, Student $student)
    {
        $student->loadMissing('user');
        $this->authService->updateAvatar($student->user, $request->file('avatar'));

        return $this->success(new StudentProfileResource($student->fresh('user')), 'تم تحديث صورة الطالب بنجاح');
    }

    public function deleteAvatar(Student $student)
    {
        $this->authorize('update', $student);

        $student->loadMissing('user');
        $this->authService->deleteAvatar($student->user);

        return $this->success(new StudentProfileResource($student->fresh('user')), 'تم حذف صورة الطالب بنجاح');
    }
}
