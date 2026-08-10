<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingRequest;
use App\Models\Setting;
use App\Services\SettingsService;

class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settingsService) {}

    public function index()
    {
        $this->authorize('viewAny', Setting::class);

        $settings = Setting::all()->map(fn (Setting $setting) => [
            'key' => $setting->key,
            'category' => $setting->group,
            'label' => $setting->label_ar,
            'description' => $setting->description,
            'type' => $setting->type,
            'value' => $setting->castValue(),
            'isEditable' => $setting->is_editable,
        ]);

        return $this->success($settings);
    }

    public function update(UpdateSettingRequest $request, string $key)
    {
        $setting = $this->settingsService->set($key, $request->validated('value'));

        return $this->success([
            'key' => $setting->key,
            'category' => $setting->group,
            'label' => $setting->label_ar,
            'description' => $setting->description,
            'type' => $setting->type,
            'value' => $setting->castValue(),
            'isEditable' => $setting->is_editable,
        ], 'تم تحديث الإعداد بنجاح');
    }
}
