<?php

namespace App\Http\Controllers\Campaign;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterCampaignStudentRequest;
use App\Http\Resources\UserResource;
use App\Models\Campaign;
use App\Services\AuthService;
use App\Services\MetaConversionsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * الحملات الإعلانية: صفحة الهبوط والتسجيل من رابطها (عامّان)، وتحويل حساب الحملة لحساب
 * طبيعي، وإدارة الحملات للأدمن.
 */
class CampaignController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly MetaConversionsService $metaConversions,
    ) {}

    /** صفحة الهبوط تعرف هل الرابط صالح للتسجيل */
    public function show(string $slug)
    {
        $campaign = Campaign::where('slug', $slug)->firstOrFail();

        return $this->success([
            'name' => $campaign->name,
            'slug' => $campaign->slug,
            'is_open' => $campaign->isOpen(),
        ]);
    }

    public function register(RegisterCampaignStudentRequest $request, string $slug)
    {
        $campaign = Campaign::where('slug', $slug)->firstOrFail();

        $result = $this->authService->registerCampaignStudent($campaign, $request->validated());
        $this->metaConversions->trackStudentRegistration($result['user'], $request);

        return $this->success([
            'user' => new UserResource($result['user']),
            'token' => $result['token'],
        ], 'تم إنشاء الحساب بنجاح', 201);
    }

    public function convertAccount(Request $request)
    {
        $user = $this->authService->convertCampaignAccount($request->user());

        return $this->success(new UserResource($user), 'تم تحويل حسابك إلى حساب طبيعي');
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        return $this->success(Campaign::withCount('users')->latest()->get());
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate($this->rules());

        $campaign = Campaign::create([...$data, 'created_by' => $request->user()->id]);

        return $this->success($campaign, 'تم إنشاء الحملة', 201);
    }

    public function update(Request $request, Campaign $campaign)
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate($this->rules($campaign));

        $campaign->update($data);

        return $this->success($campaign->fresh(), 'تم تحديث الحملة');
    }

    private function rules(?Campaign $campaign = null): array
    {
        $required = $campaign ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:150'],
            'slug' => [$required, 'string', 'max:80', 'regex:/^[a-z0-9-]+$/', Rule::unique('campaigns', 'slug')->ignore($campaign?->id)],
            'is_active' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ];
    }
}
