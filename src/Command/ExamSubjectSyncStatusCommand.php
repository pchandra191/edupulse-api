<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\ExaminationModule\ExaminationService;
use App\Service\ExamSessionModule\ExamSessionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:exam-subjects:sync-status',
    description: 'Automatically transition exam subjects based on examDate/startTime/endTime',
)]
class ExamSubjectSyncStatusCommand extends Command
{
    public function __construct(
        private readonly ExaminationService $examinationService,
        private readonly ExamSessionService $examSessionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('This command automatically transitions exam subjects:' . PHP_EOL
                . '- SCHEDULED subjects become ONGOING when start datetime is reached' . PHP_EOL
                . '- ONGOING subjects become COMPLETED when end datetime is reached' . PHP_EOL
                . '- Linked exam sessions are synchronized via the existing session lifecycle');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Exam Subject Status Sync');

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $io->info(sprintf('Processing at: %s', $now->format('Y-m-d H:i:s e')));

        try {
            $subjectResult = $this->examinationService->syncExamSubjectStatuses($now);
            $sessionResult = $this->examSessionService->syncSessionStatuses($now);

            $totalSubjectTransitions = $subjectResult['started'] + $subjectResult['completed'];
            $totalSessionTransitions = $sessionResult['activated'] + $sessionResult['closed'];

            $io->success(sprintf(
                'Exam subjects: %d transitions (%d started, %d completed). Exam sessions: %d transitions (%d activated, %d closed)',
                $totalSubjectTransitions,
                $subjectResult['started'],
                $subjectResult['completed'],
                $totalSessionTransitions,
                $sessionResult['activated'],
                $sessionResult['closed']
            ));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Failed to sync exam subject statuses: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
