<?php

namespace App\Http\Controllers\Session;

use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Models\SessionQualityRating;
use App\Services\SessionQualityRatingService;
use Illuminate\Http\Request;

/**
 * تقييم جودة الجلسة: الطالب يفتح صفحة التقييم ويرسله، والأدمن وحده يرى التقييمات.
 */
class SessionQualityRatingController extends Controller
{
    public function __construct(private readonly SessionQualityRatingService $service) {}

    public function show(Request $request, string $token)
    {
        return $this->success($this->service->forStudent($this->sessionByToken($token), $this->currentStudent($request)));
    }

    public function store(Request $request, string $token)
    {
        $session = $this->sessionByToken($token);

        $data = $request->validate([
            ...collect(SessionQualityRating::CRITERIA)->mapWithKeys(fn ($c) => [$c => ['required', 'integer', 'min:1', 'max:5']])->all(),
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->service->submit($session, $this->currentStudent($request), $data);

        return $this->success(null, 'شكراً لك، تم إرسال تقييمك');
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        $filters = $request->validate([
            'teacher_id' => ['nullable', 'integer'],
            'teacher_name' => ['nullable', 'string', 'max:150'],
            'student_name' => ['nullable', 'string', 'max:150'],
            'subject_id' => ['nullable', 'integer'],
            'sort' => ['nullable', 'in:latest,highest,lowest'],
        ]);

        return $this->paginateWithData(
            $this->service->adminList($filters, $request->integer('per_page', 20)),
            ['subjectOptions' => $this->service->subjectOptions()],
        );
    }

    /** الرابط برمز عشوائي لا برقم الجلسة — رمز غير موجود (أو أُبطل) = 404 */
    private function sessionByToken(string $token): ClassSession
    {
        abort_if(strlen($token) < 32, 404);

        return ClassSession::where('quality_rating_token', $token)->firstOrFail();
    }

    private function currentStudent(Request $request)
    {
        $user = $request->user();
        $student = $user->isStudent() ? $user->loadMissing('student')->student : null;

        abort_unless($student, 403, 'تقييم الجلسة متاح للطلاب فقط');

        return $student;
    }
}
