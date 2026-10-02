<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Analysis\AnalysisProcessingRecoveryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:analysis:recover-stale',
    description: 'Finds and recovers analyses stuck in PROCESSING status due to worker crashes or timeouts',
)]
class RecoverStaleAnalysisCommand extends Command
{
    public function __construct(
        private readonly AnalysisProcessingRecoveryService $recoveryService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('This command finds analyses stuck in PROCESSING status for longer than the stale threshold and transitions them to FAILED so they can be retried.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Stale Analysis Recovery');

        $recoveredCount = $this->recoveryService->recoverStaleProcessing(AnalysisProcessingRecoveryService::STALE_PROCESSING_THRESHOLD);

        if ($recoveredCount === 0) {
            $io->success('No stale analyses found. All PROCESSING analyses are up to date.');
            return Command::SUCCESS;
        }

        $io->success(sprintf('Recovered %d stale analysis(es) from PROCESSING to FAILED.', $recoveredCount));

        $io->note('These analyses can now be retried via the retry endpoint.');

        return Command::SUCCESS;
    }
}
