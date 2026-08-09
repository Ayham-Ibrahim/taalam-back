<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\CreateStudentAccountRequest;
use App\Http\Requests\Student\UpdateStudentProfileRequest;
use App\Http\Resources\Student\StudentIndexResource;
use App\Http\Resources\Student\StudentProfileResource;
use App\Models\Student;
use App\Services\StudentService;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    public function __construct(private readonly StudentService $studentService) {}

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

    /** بحث/فهرسة الطلاب — يغذي منتقي الحجز اليدوي لدى الأدمن (StudentPolicy::viewAny) */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Student::class);

        $students = Student::query()
            ->with('user:id,name,email,phone')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search');
                $query->whereHas('user', function ($userQuery) use ($search) {
                    $userQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->paginate($students->through(fn (Student $student) => new StudentIndexResource($student)));
    }

    public function show(Student $student)
    {
        $this->authorize('view', $student);

        $student->load(['user:id,name,email,phone,avatar_path,whatsapp,gender', 'curriculum:id,name_ar', 'stage:id,name_ar', 'university:id,name_ar', 'major:id,name_ar', 'courseField:id,name_ar']);

        return $this->success(new StudentProfileResource($student));
    }
}
