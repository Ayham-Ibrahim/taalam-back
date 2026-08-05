<?php

namespace App\Services;

use App\Jobs\RecalculateRankingJob;
use App\Models\Review;
use App\Models\Teacher;
use Illuminate\Support\Carbon;

/**
 * ranking_score يُخزَّن على teachers.ranking_score دائماً — لا يُعاد حسابه عند
 * القراءة أبداً (متطلب أداء صريح). الأوزان تأتي من settings وليست ثابتة بالكود.
 */
class RankingService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function recalculate(Teacher $teacher): void
    {
        $ratingComponent = $teacher->rating_avg > 0 ? ((float) $teacher->rating_avg / 5) * 100 : 0.0;
        $completionComponent = (float) $teacher->completion_rate;
        $profileComponent = (float) $teacher->profile_completeness;

        $responseComponent = $teacher->avg_response_minutes !== null
            ? max(0, 100 - min((int) $teacher->avg_response_minutes, 100))
            : 0.0;

        $lastReviewAt = Review::where('teacher_id', $teacher->id)->where('is_hidden', false)->max('created_at');
        $recencyComponent = 0.0;

        if ($lastReviewAt) {
            $daysSince = Carbon::parse($lastReviewAt)->diffInDays(now());
            $recencyComponent = max(0, 100 - min($daysSince, 100));
        }

        $weights = [
            'rating' => (float) $this->settings->get('ranking_weight_rating', 30),
            'completion' => (float) $this->settings->get('ranking_weight_completion', 25),
            'response' => (float) $this->settings->get('ranking_weight_response', 20),
            'profile' => (float) $this->settings->get('ranking_weight_profile', 15),
            'recency' => (float) $this->settings->get('ranking_weight_recency', 10),
        ];

        $score = (
            $ratingComponent * $weights['rating']
            + $completionComponent * $weights['completion']
            + $responseComponent * $weights['response']
            + $profileComponent * $weights['profile']
            + $recencyComponent * $weights['recency']
        ) / 100;

        $teacher->update(['ranking_score' => round($score, 2)]);
    }

    /**
     * يوزّع إعادة الحساب على طابور واحد لكل معلم بدل حساب متزامن جماعي ثقيل.
     */
    public function recalculateAllVerified(): void
    {
        Teacher::where('status', 'verified')
            ->select('id')
            ->chunkById(100, function ($teachers) {
                foreach ($teachers as $teacher) {
                    RecalculateRankingJob::dispatch($teacher->id);
                }
            });
    }
}
