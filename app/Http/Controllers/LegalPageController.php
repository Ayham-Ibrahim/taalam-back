<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\UpdateLegalPageRequest;
use App\Models\LegalPage;

/** سياسة الخصوصية والشروط والأحكام — قراءة عامة، تعديل الأدمن فقط (راجع UpdateLegalPageRequest) */
class LegalPageController extends Controller
{
    public function show(LegalPage $legalPage)
    {
        return $this->success([
            'slug' => $legalPage->slug,
            'sections' => $legalPage->sections,
            'updatedAt' => $legalPage->updated_at,
        ]);
    }

    public function update(UpdateLegalPageRequest $request, LegalPage $legalPage)
    {
        $legalPage->update(['sections' => $request->validated('sections')]);

        return $this->success([
            'slug' => $legalPage->slug,
            'sections' => $legalPage->sections,
            'updatedAt' => $legalPage->updated_at,
        ], 'تم حفظ التعديلات بنجاح');
    }
}
