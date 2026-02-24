<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Command;

use Emporiqa\SyliusPlugin\Service\WebhookSenderInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class AbstractSyncCommand extends Command
{
    public function __construct(
        protected WebhookSenderInterface $webhookSender,
        protected array $enabledLanguages,
    ) {
        parent::__construct();
    }

    abstract protected function getEntityLabel(): string;

    abstract protected function getEntityName(): string;

    abstract protected function fetchEntities(): iterable;

    abstract protected function getTotalCount(): int;

    abstract protected function formatEntityForLanguage(object $entity, string $locale): array;

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
        $batchSize = (int) $input->getOption('batch-size');
        $dryRun = $input->getOption('dry-run');
        $noSession = $input->getOption('no-session');

        if ($batchSize < 1) {
            $io->error('Batch size must be at least 1');
            return Command::FAILURE;
        }

        $entityLabel = $this->getEntityLabel();
        $entityName = $this->getEntityName();

        $io->title(sprintf('Syncing %s to Emporiqa', $entityLabel));

        if ($dryRun) {
            $io->note('Dry run mode - no webhooks will be sent');
        }

        try {
            $totalCount = $this->getTotalCount();
        } catch (\Exception $e) {
            $io->error(sprintf('Failed to fetch %s: %s', strtolower($entityLabel), $e->getMessage()));
            return Command::FAILURE;
        }

        $io->info(sprintf('Found %d %s to sync', $totalCount, strtolower($entityLabel)));

        if ($totalCount === 0) {
            $io->warning(sprintf('No %s found to sync', strtolower($entityLabel)));
            return Command::SUCCESS;
        }

        $overallSuccess = true;

        foreach ($this->enabledLanguages as $locale) {
            $language = substr($locale, 0, 2);
            $io->section(sprintf('Syncing %s for language: %s', strtolower($entityLabel), $language));

            $sessionId = null;
            if (!$noSession && !$dryRun) {
                $sessionId = $this->startSyncSession($entityName, $language, $io);
                if (!$sessionId) {
                    $io->warning(sprintf('Failed to start sync session for %s, continuing without session', $language));
                }
            }

            $progressBar = $io->createProgressBar($totalCount);
            $progressBar->start();

            $eventsBatch = [];
            $successCount = 0;
            $errorCount = 0;

            foreach ($this->fetchEntities() as $entity) {
                try {
                    $events = $this->formatEntityForLanguage($entity, $locale);
                } catch (\Throwable $e) {
                    $errorCount++;
                    $progressBar->advance();
                    continue;
                }

                if ($sessionId) {
                    foreach ($events as &$event) {
                        $event['data']['sync_session_id'] = $sessionId;
                    }
                    unset($event);
                }

                array_push($eventsBatch, ...$events);

                if (count($eventsBatch) >= $batchSize) {
                    if (!$dryRun) {
                        if ($this->webhookSender->sendBatch($eventsBatch)) {
                            $successCount += count($eventsBatch);
                        } else {
                            $errorCount += count($eventsBatch);
                        }
                    } else {
                        $successCount += count($eventsBatch);
                    }
                    $eventsBatch = [];
                }

                $progressBar->advance();
            }

            if (!empty($eventsBatch)) {
                if (!$dryRun) {
                    if ($this->webhookSender->sendBatch($eventsBatch)) {
                        $successCount += count($eventsBatch);
                    } else {
                        $errorCount += count($eventsBatch);
                    }
                } else {
                    $successCount += count($eventsBatch);
                }
            }

            $progressBar->finish();
            $io->newLine();

            if ($sessionId && !$dryRun) {
                $this->completeSyncSession($sessionId, $entityName, $language, $io);
            }

            $io->text(sprintf('  %d events sent, %d errors', $successCount, $errorCount));

            if ($errorCount > 0) {
                $overallSuccess = false;
            }
        }

        $io->newLine();
        if ($overallSuccess) {
            $io->success(sprintf('%s sync completed successfully!', $entityLabel));
            return Command::SUCCESS;
        }

        $io->warning(sprintf('%s sync completed with some errors', $entityLabel));
        return Command::FAILURE;
    }

    private function startSyncSession(string $entityName, string $language, SymfonyStyle $io): ?string
    {
        $sessionId = sprintf('%s-%s-%s', $entityName, $language, bin2hex(random_bytes(8)));

        $event = [
            'type' => 'sync.start',
            'data' => [
                'session_id' => $sessionId,
                'entity' => $entityName,
                'language' => $language,
            ],
        ];

        if ($this->webhookSender->sendBatch([$event])) {
            $io->text(sprintf('  Started sync session: %s', $sessionId));
            return $sessionId;
        }

        return null;
    }

    private function completeSyncSession(string $sessionId, string $entityName, string $language, SymfonyStyle $io): void
    {
        $event = [
            'type' => 'sync.complete',
            'data' => [
                'session_id' => $sessionId,
                'entity' => $entityName,
                'language' => $language,
            ],
        ];

        if ($this->webhookSender->sendBatch([$event])) {
            $io->text(sprintf('  Completed sync session: %s', $sessionId));
        } else {
            $io->warning(sprintf('  Failed to complete sync session: %s', $sessionId));
        }
    }
}
