<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'emporiqa:sync:all',
    description: 'Sync all products and pages to Emporiqa',
)]
class SyncAllCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('batch-size', 'b', InputOption::VALUE_REQUIRED, 'Number of items per batch', 50)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not actually send webhooks')
            ->addOption('no-session', null, InputOption::VALUE_NONE, 'Skip sync session (sync.start/sync.complete events)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batchSize = $input->getOption('batch-size');
        $dryRun = $input->getOption('dry-run');
        $noSession = $input->getOption('no-session');

        $io->title('Syncing All Data to Emporiqa');

        $application = $this->getApplication();
        if (!$application) {
            $io->error('Could not get application instance');
            return Command::FAILURE;
        }

        $io->section('Syncing Products');
        $productsCommand = $application->find('emporiqa:sync:products');
        $productsInput = new ArrayInput([
            '--batch-size' => $batchSize,
            '--dry-run' => $dryRun,
            '--no-session' => $noSession,
        ]);
        $productsResult = $productsCommand->run($productsInput, $output);

        $io->newLine();
        $io->section('Syncing Pages');
        $pagesCommand = $application->find('emporiqa:sync:pages');
        $pagesInput = new ArrayInput([
            '--batch-size' => $batchSize,
            '--dry-run' => $dryRun,
            '--no-session' => $noSession,
        ]);
        $pagesResult = $pagesCommand->run($pagesInput, $output);

        $io->newLine();

        if ($productsResult === Command::SUCCESS && $pagesResult === Command::SUCCESS) {
            $io->success('All data synced successfully!');
            return Command::SUCCESS;
        }

        $io->warning('Sync completed with some errors');
        return Command::FAILURE;
    }
}
