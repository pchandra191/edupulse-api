<?php

declare(strict_types=1);

namespace App\DTO\StudentIntelligence;

readonly class AcademicIntelligenceResponseDTO
{
    public function __construct(
        public string $studentId,
        public DashboardPerformanceDTO $performance,
        public DashboardLearningHealthDTO $learningHealth,
        /** @var list<DashboardWeakestTopicDTO> */
        public array $weakestTopics,
        public DashboardTodaysGoalDTO $todaysGoal,
        public DashboardAICoachInsightDTO $aiCoachInsight,
        /** @var list<DashboardSubjectPerformanceDTO> */
        public array $subjectPerformance,
        /** @var list<DashboardUpcomingTestDTO> */
        public array $upcomingTests,
        public DashboardRecentTestDTO $recentTest,
        public DashboardStudyStreakDTO $studyStreak,
    ) {}
}
