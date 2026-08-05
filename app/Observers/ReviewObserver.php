<?php

namespace App\Observers;

use App\Jobs\RecalculateRankingJob;
use App\Models\Review;
use App\Models\Teacher;

/**
 * teachers.rating_avg و reviews_count محسوبان دائماً هنا — لا يُعاد حسابهما عند
 * القراءة أبداً (متطلب أداء صريح). التقييمات المخفية (is_hidden) لا تُحتسب.
 */
class ReviewObserver
{
    public function created(Review $review): void
    {
        $this->recalculate($review->teacher_id);
    }

    public function updated(Review $review): void
    {
        $this->recalculate($review->teacher_id);
    }

    public function deleted(Review $review): void
    {
        $this->recalculate($review->teacher_id);
    }

    private function recalculate(int $teacherId): void
    {
        $stats = Review::where('teacher_id', $teacherId)
            ->where('is_hidden', false)
            ->selectRaw('COUNT(*) as review_count, COALESCE(AVG(rating), 0) as average_rating')
            ->first();

        Teacher::whereKey($teacherId)->update([
            'reviews_count' => $stats->review_count,
            'rating_avg' => round((float) $stats->average_rating, 2),
        ]);

        RecalculateRankingJob::dispatch($teacherId);
    }
}
