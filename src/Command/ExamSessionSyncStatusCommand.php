<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\ExamSessionModule\ExamSessionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:exam-sessions:sync-status',
    description: 'Automatically transition exam sessions based on availableFrom/availableTo dates',
)]
class ExamSessionSyncStatusCommand extends Command
{
    public function __construct(
        private readonly ExamSessionService $examSessionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('This command automatically transitions exam sessions:' . PHP_EOL
                . '- PUBLISHED sessions become ACTIVE when availableFrom is reached' . PHP_EOL
                . '- ACTIVE sessions become CLOSED when availableTo is crossed' . PHP_EOL
                . '- Overdue PUBLISHED sessions (availableTo <= now) become CLOSED');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Exam Session Status Sync');

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $io->info(sprintf('Processing at: %s', $now->format('Y-m-d H:i:s e')));

        try {
            $result = $this->examSessionService->syncSessionStatuses($now);

            $io->success(sprintf(
                'Processed %d transitions: %d activated, %d closed',
                $result['activated'] + $result['closed'],
                $result['activated'],
                $result['closed']
            ));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Failed to sync exam session statuses: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
