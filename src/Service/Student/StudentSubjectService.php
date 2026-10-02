<?php
declare(strict_types=1);

namespace App\Service\Student;

use App\DTO\Student\StudentSubjectOptionDTO;
use App\Entity\Institute;
use App\Entity\Subject;
use App\Entity\User;
use App\Repository\AnalysisRepository;
use App\Repository\SubjectRepository;
use function array_key_exists;
use function mb_strtolower;
use function usort;

readonly class StudentSubjectService
{
    private const SOURCE_INSTITUTE = 'INSTITUTE';
    private const SOURCE_HISTORY = 'STUDENT_HISTORY';
    private const SOURCE_BOTH = 'INSTITUTE_AND_HISTORY';

    public function __construct(
        private SubjectRepository $subjectRepository,
        private AnalysisRepository $analysisRepository,
    ) {
    }

    /**
     * Builds the selectable subject options for the authenticated student.
     *
     * - For a student attached to an institute, institute (active) subjects are
     *   included via the existing subject repository, alongside the student's own
     *   historical subjects. The result is always scoped to the supplied student.
     * - For an independent student (no institute), only the student's historical
     *   subjects are returned.
     *
     * Duplicates are collapsed by lowercased name. When a subject appears in both
     * the institute source and the student history, the canonical institute id/name
     * is preferred and the source is reported as INSTITUTE_AND_HISTORY.
     *
     * @return list<StudentSubjectOptionDTO> ordered alphabetically by name.
     */
    public function getStudentSubjects(User $student): array
    {
        $seen = [];
        $this->registerInstituteSubjects($student, $seen);
        $this->registerStudentHistory($student, $seen);

        $options = [];
        foreach ($seen as $entry) {
            $options[] = new StudentSubjectOptionDTO(
                id: $entry['id'],
                name: $entry['name'],
                source: $entry['source'],
            );
        }

        usort(
            $options,
            static fn (StudentSubjectOptionDTO $a, StudentSubjectOptionDTO $b): int
                => mb_strtolower($a->name) <=> mb_strtolower($b->name)
        );

        return $options;
    }

    /**
     * @param array<string, array{id: string, name: string, source: string}> $seen
     */
    private function registerInstituteSubjects(User $student, array &$seen): void
    {
        $institute = $student->getInstitute();

        if (!$institute instanceof Institute) {
            return;
        }

        foreach ($this->subjectRepository->findActiveByInstitute($institute->getId()) as $subject) {
            $this->addInstituteEntry($seen, $subject->getName(), (string) $subject->getId());
        }
    }

    /**
     * @param array<string, array{id: string, name: string, source: string}> $seen
     */
    private function registerStudentHistory(User $student, array &$seen): void
    {
        foreach ($this->analysisRepository->findSubjectHistoryForStudent($student) as $analysis) {
            $subjectEntity = $analysis->getSubject();
            $subjectName = $this->normalizeName($analysis->getSubjectName());

            if ($subjectEntity instanceof Subject) {
                $entityName = $this->normalizeName($subjectEntity->getName());
                if ($entityName !== '') {
                    $this->addHistoryEntry($seen, $entityName, (string) $subjectEntity->getId());
                }
                continue;
            }

            if ($subjectName !== '') {
                $this->addHistoryEntry($seen, $subjectName, $subjectName);
            }
        }
    }

    /**
     * @param array<string, array{id: string, name: string, source: string}> $seen
     */
    private function addInstituteEntry(array &$seen, string $name, string $id): void
    {
        $key = mb_strtolower($name);

        if (!array_key_exists($key, $seen)) {
            $seen[$key] = ['id' => $id, 'name' => $name, 'source' => self::SOURCE_INSTITUTE];
            return;
        }

        $entry = &$seen[$key];
        $entry['id'] = $id;
        $entry['name'] = $name;
        $entry['source'] = self::SOURCE_INSTITUTE;
        unset($entry);
    }

    /**
     * @param array<string, array{id: string, name: string, source: string}> $seen
     */
    private function addHistoryEntry(array &$seen, string $name, string $id): void
    {
        $key = mb_strtolower($name);

        if (!array_key_exists($key, $seen)) {
            $seen[$key] = ['id' => $id, 'name' => $name, 'source' => self::SOURCE_HISTORY];
            return;
        }

        $entry = &$seen[$key];
        if ($entry['source'] === self::SOURCE_INSTITUTE) {
            $entry['source'] = self::SOURCE_BOTH;
        }
        unset($entry);
    }

    private function normalizeName(?string $name): string
    {
        return trim($name ?? '');
    }
}
