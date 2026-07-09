<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Command;

use Emporiqa\SyliusPlugin\Command\AbstractSyncCommand;
use Emporiqa\SyliusPlugin\Service\WebhookSenderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * In-memory webhook sender that records every batch and fails batches
 * containing the configured event types. sync.start always succeeds
 * unless listed in $failingTypes.
 */
class RecordingWebhookSender implements WebhookSenderInterface
{
    /** @var array<array<array{type: string, data: array}>> */
    public array $batches = [];

    /** @param string[] $failingTypes */
    public function __construct(private array $failingTypes = []) {}

    public function send(string $event, array $data): bool
    {
        return $this->sendBatch([['type' => $event, 'data' => $data]]);
    }

    public function sendBatch(array $events): bool
    {
        $this->batches[] = $events;

        foreach ($events as $event) {
            if (in_array($event['type'], $this->failingTypes, true)) {
                return false;
            }
        }

        return true;
    }

    public function testConnection(): array
    {
        return ['success' => true];
    }

    public function sendDryRun(array $events): array
    {
        return ['success' => true];
    }

    public function getLastError(): ?string
    {
        return null;
    }

    public function buildFriendlyError(array $result): string
    {
        return 'error';
    }

    /** @return string[] All event types sent, in order */
    public function sentTypes(): array
    {
        $types = [];
        foreach ($this->batches as $batch) {
            foreach ($batch as $event) {
                $types[] = $event['type'];
            }
        }

        return $types;
    }
}

class FixtureSyncCommand extends AbstractSyncCommand
{
    /**
     * @param object[] $entities
     * @param array[]|null $formattedEvents Events returned per entity; null = throw on format
     */
    public function __construct(
        WebhookSenderInterface $webhookSender,
        private array $entities,
        private ?array $formattedEvents = null,
    ) {
        parent::__construct($webhookSender);
        $this->setName('emporiqa:sync:fixtures');
    }

    protected function getEntityLabel(): string
    {
        return 'Products';
    }

    protected function getEntityName(): string
    {
        return 'products';
    }

    protected function fetchEntities(): iterable
    {
        return $this->entities;
    }

    protected function getTotalCount(): int
    {
        return count($this->entities);
    }

    protected function formatEntity(object $entity): array
    {
        if ($this->formattedEvents === null) {
            throw new \RuntimeException('Formatting failed');
        }

        return $this->formattedEvents;
    }
}

class AbstractSyncCommandTest extends TestCase
{
    /** @return object[] */
    private function entities(int $count): array
    {
        return array_fill(0, $count, new \stdClass());
    }

    private function productEvent(): array
    {
        return ['type' => 'product.updated', 'data' => ['identification_number' => 'product-1']];
    }

    public function testCompletesSessionWhenAllBatchesSucceed(): void
    {
        $sender = new RecordingWebhookSender();
        $command = new FixtureSyncCommand($sender, $this->entities(3), [$this->productEvent()]);

        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertContains('sync.start', $sender->sentTypes());
        $this->assertContains('sync.complete', $sender->sentTypes());
    }

    public function testSkipsCompletionWhenAnyBatchFails(): void
    {
        $sender = new RecordingWebhookSender(['product.updated']);
        $command = new FixtureSyncCommand($sender, $this->entities(3), [$this->productEvent()]);

        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertContains('sync.start', $sender->sentTypes());
        $this->assertNotContains('sync.complete', $sender->sentTypes());
        $this->assertStringContainsString('NOT removed from Emporiqa', $tester->getDisplay());
    }

    public function testSkipsCompletionWhenFormattingFailsForAllEntities(): void
    {
        $sender = new RecordingWebhookSender();
        $command = new FixtureSyncCommand($sender, $this->entities(2), null);

        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertNotContains('sync.complete', $sender->sentTypes());
    }

    public function testSkipsCompletionWhenNothingWasSynced(): void
    {
        $sender = new RecordingWebhookSender();
        $command = new FixtureSyncCommand($sender, $this->entities(2), []);

        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertNotContains('sync.complete', $sender->sentTypes());
    }

    public function testFailedBatchesStillReportedWithoutSession(): void
    {
        $sender = new RecordingWebhookSender(['product.updated']);
        $command = new FixtureSyncCommand($sender, $this->entities(1), [$this->productEvent()]);

        $tester = new CommandTester($command);
        $tester->execute(['--no-session' => true]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertNotContains('sync.start', $sender->sentTypes());
        $this->assertNotContains('sync.complete', $sender->sentTypes());
    }

    public function testDryRunSendsNothing(): void
    {
        $sender = new RecordingWebhookSender();
        $command = new FixtureSyncCommand($sender, $this->entities(2), [$this->productEvent()]);

        $tester = new CommandTester($command);
        $tester->execute(['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame([], $sender->batches);
    }
}
