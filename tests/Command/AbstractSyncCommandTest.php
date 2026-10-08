<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Command;

use Emporiqa\SyliusPlugin\Command\AbstractSyncCommand;
use Emporiqa\SyliusPlugin\Service\WebhookEventQueue;
use Emporiqa\SyliusPlugin\Tests\Service\FakeCurrentState;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
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
    public function __construct(private array $failingTypes = [], private ?string $lastError = null) {}

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
        return $this->lastError;
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
        ?WebhookEventQueue $webhookQueue = null,
        string $webhookUrl = '',
    ) {
        parent::__construct($webhookSender, null, $webhookQueue, $webhookUrl);
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

    /**
     * The links printed after a sync point at the Emporiqa host the shop is
     * connected to, not always emporiqa.com.
     */
    public function testTheResultsLinkUsesTheConfiguredHost(): void
    {
        $command = new FixtureSyncCommand(new RecordingWebhookSender(), $this->entities(1), [$this->productEvent()], null, 'https://test.emporiqa.com/webhooks/sync/');
        $tester = new CommandTester($command);
        $tester->execute([]);

        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        $this->assertStringContainsString('https://test.emporiqa.com/platform/products/', $display);
        $this->assertStringNotContainsString('https://emporiqa.com/', $display);
    }

    public function testPlatformUrl(): void
    {
        $this->assertSame('https://emporiqa.com/platform/pages/', AbstractSyncCommand::platformUrl('https://emporiqa.com/webhooks/sync/', 'platform/pages/'));
        $this->assertSame('http://localhost:8000/platform/pages/', AbstractSyncCommand::platformUrl('http://localhost:8000/webhooks/sync/', 'platform/pages/'));
        $this->assertSame('https://emporiqa.com/platform/pages/', AbstractSyncCommand::platformUrl('', 'platform/pages/'));
        $this->assertSame('https://eu.example.com/platform/', AbstractSyncCommand::platformUrl('ftp://eu.example.com/x', '/platform/'));
    }

    /**
     * Changes kept from an earlier failed send are older than what a
     * completed sync just sent; they are forgotten, the other kind kept.
     */
    public function testACompletedSyncForgetsKeptEventsOfItsKind(): void
    {
        $failing = new RecordingWebhookSender(['product.updated', 'page.updated']);
        $queue = new WebhookEventQueue($failing, null, new ArrayAdapter(), new FakeCurrentState([]));
        $queue->queue([$this->productEvent(), ['type' => 'page.updated', 'data' => ['identification_number' => 'page-1']]]);
        $queue->flush();
        $this->assertSame(2, $queue->retainedCount());

        $command = new FixtureSyncCommand(new RecordingWebhookSender(), $this->entities(1), [$this->productEvent()], $queue);
        (new CommandTester($command))->execute([]);

        $this->assertSame(1, $queue->retainedCount());
    }

    public function testASyncThatFailedKeepsTheKeptEvents(): void
    {
        $queue = new WebhookEventQueue(new RecordingWebhookSender(['product.updated']), null, new ArrayAdapter(), new FakeCurrentState([]));
        $queue->queue([$this->productEvent()]);
        $queue->flush();

        $command = new FixtureSyncCommand(new RecordingWebhookSender(['product.updated']), $this->entities(1), [$this->productEvent()], $queue);
        (new CommandTester($command))->execute([]);

        $this->assertSame(1, $queue->retainedCount());
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

    /**
     * Refused at sync.start (an interrupted sync's session is still open):
     * the reason is shown, items are still sent, and no sync.complete goes
     * out, so nothing is deleted on Emporiqa's side.
     */
    public function testARefusedSessionSaysWhyAndDeletesNothing(): void
    {
        $sender = new RecordingWebhookSender(['sync.start'], 'Sync session already active');
        $command = new FixtureSyncCommand($sender, $this->entities(2), [$this->productEvent()]);

        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertStringContainsString('Sync session already active', preg_replace('/\s+/', ' ', $tester->getDisplay()));
        $this->assertContains('product.updated', $sender->sentTypes());
        $this->assertNotContains('sync.complete', $sender->sentTypes());
    }
}
