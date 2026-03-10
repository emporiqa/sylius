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
        $pagesResult = Command::SUCCESS;

        try {
            $pagesCommand = $application->find('emporiqa:sync:pages');
            $io->section('Syncing Pages');
            $pagesInput = new ArrayInput([
                '--batch-size' => $batchSize,
                '--dry-run' => $dryRun,
                '--no-session' => $noSession,
            ]);
            $pagesResult = $pagesCommand->run($pagesInput, $output);
        } catch (\Symfony\Component\Console\Exception\CommandNotFoundException) {
            $io->note('Page sync not configured — skipping.');
        }

        $io->newLine();

        if ($productsResult === Command::SUCCESS && $pagesResult === Command::SUCCESS) {
            $io->success('All data synced successfully!');
            $io->note("Emporiqa will now process and enhance the synced data. This may take a few minutes depending on the volume.\nCheck the results at:\n  Products: https://emporiqa.com/platform/products/\n  Pages:    https://emporiqa.com/platform/pages/");
            return Command::SUCCESS;
        }

        $io->warning('Sync completed with some errors');
        $io->note('Successfully synced items will be processed and enhanced by Emporiqa. This may take a few minutes. You can check the results in the Products/Pages list in your Emporiqa dashboard.');
        return Command::FAILURE;
    }
}
