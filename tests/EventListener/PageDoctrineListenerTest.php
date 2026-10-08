<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\EventListener;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\UnitOfWork;
use Emporiqa\SyliusPlugin\EventListener\PageDoctrineListener;
use Emporiqa\SyliusPlugin\Model\PageInterface;
use Emporiqa\SyliusPlugin\Service\ChannelMappingResolver;
use Emporiqa\SyliusPlugin\Service\PageFormatter;
use Emporiqa\SyliusPlugin\Service\PageUrlResolverInterface;
use Emporiqa\SyliusPlugin\Service\WebhookEventQueue;
use Emporiqa\SyliusPlugin\Service\WebhookSenderInterface;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;

class ListenerTestPage implements PageInterface
{
    /** @var Collection<int, ListenerTestPageTranslation> */
    private Collection $translations;

    public bool $enabled = true;

    public function __construct(private ?int $id)
    {
        $this->translations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** What Doctrine does to a generated id once the row is deleted. */
    public function deleted(): void
    {
        $this->id = null;
    }

    public function translate(string $locale, string $title, string $content): ListenerTestPageTranslation
    {
        $translation = new ListenerTestPageTranslation($this, $locale, $title, $content);
        $this->translations->set($locale, $translation);

        return $translation;
    }
}

class ListenerTestPageTranslation
{
    public function __construct(
        private ?object $translatable,
        private string $locale,
        public string $title,
        public string $content,
    ) {}

    public function getTranslatable(): ?object
    {
        return $this->translatable;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getContent(): string
    {
        return $this->content;
    }
}

class PageDoctrineListenerTest extends TestCase
{
    /** @var list<list<array>> */
    private array $sent = [];

    private WebhookEventQueue $queue;

    private UnitOfWork $unitOfWork;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $sender = $this->createMock(WebhookSenderInterface::class);
        $sender->method('sendBatch')->willReturnCallback(function (array $events): bool {
            $this->sent[] = $events;

            return true;
        });
        $this->queue = new WebhookEventQueue($sender);
        $this->unitOfWork = $this->createMock(UnitOfWork::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('getUnitOfWork')->willReturn($this->unitOfWork);
        $this->unitOfWork->method('getScheduledEntityDeletions')->willReturnCallback(fn (): array => $this->scheduledDeletions);
        $this->entityManager->method('contains')->willReturnCallback(fn (object $o): bool => !in_array($o, $this->notManaged, true));
    }

    /** @var list<object> what Doctrine has scheduled for deletion in the flush */
    private array $scheduledDeletions = [];

    /** @var list<object> pages the flushing manager does not manage */
    private array $notManaged = [];

    private ?PageDoctrineListener $listener = null;

    private function listener(): PageDoctrineListener
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('default');
        $channels = $this->createMock(ChannelRepositoryInterface::class);
        $channels->method('findAll')->willReturn([$channel]);
        $urls = $this->createMock(PageUrlResolverInterface::class);
        $urls->method('resolveUrl')->willReturn('https://shop.example.com/page');
        $formatter = new PageFormatter($urls, new ChannelMappingResolver($channels), ['en_US', 'de_DE']);

        return $this->listener = new PageDoctrineListener($this->queue, $formatter, [ListenerTestPage::class]);
    }

    /** Doctrine's onFlush and postFlush, then the queue's flush at the end of the request. */
    private function flushed(): array
    {
        $this->listener?->onFlush(new OnFlushEventArgs($this->entityManager));
        $this->listener?->postFlush(new PostFlushEventArgs($this->entityManager));
        $this->queue->flush();

        return array_merge([], ...$this->sent);
    }

    /**
     * Editing only one language's text changes the translation row alone;
     * Doctrine reports no change on the page, so the page must still be sent.
     */
    public function testEditingOnlyATranslationSendsThePage(): void
    {
        $page = new ListenerTestPage(7);
        $page->translate('en_US', 'Returns', '30 days');
        $german = $page->translate('de_DE', 'Rückgabe', '30 Tage');
        $german->content = '60 Tage';

        $this->listener()->postUpdate(new PostUpdateEventArgs($german, $this->entityManager));
        $events = $this->flushed();

        $this->assertCount(1, $events);
        $this->assertSame('page.updated', $events[0]['type']);
        $this->assertSame('page-7', $events[0]['data']['identification_number']);
        $this->assertSame('60 Tage', $events[0]['data']['contents']['default']['de_DE']);
    }

    public function testAddingALanguageToAPageSendsThePage(): void
    {
        $page = new ListenerTestPage(7);
        $page->translate('en_US', 'Returns', '30 days');
        $listener = $this->listener();
        $listener->postFlush(new PostFlushEventArgs($this->entityManager));
        $german = $page->translate('de_DE', 'Rückgabe', '30 Tage');

        $listener->postPersist(new PostPersistEventArgs($german, $this->entityManager));
        $events = $this->flushed();

        $this->assertSame('page.updated', $events[0]['type']);
        $this->assertSame('Rückgabe', $events[0]['data']['titles']['default']['de_DE']);
    }

    /**
     * A new page's translations are inserted after it, in the same flush,
     * and are already in its created event: they must not add an update
     * (which a PreSyncEvent listener that cancelled the create would not see).
     */
    public function testANewPagesTranslationsAddNothingToItsCreatedEvent(): void
    {
        $page = new ListenerTestPage(8);
        $english = $page->translate('en_US', 'FAQ', 'Answers');
        $listener = $this->listener();

        $listener->postPersist(new PostPersistEventArgs($page, $this->entityManager));
        $listener->postPersist(new PostPersistEventArgs($english, $this->entityManager));
        $events = $this->flushed();

        $this->assertSame(['page.created'], array_column($events, 'type'));
    }

    public function testRemovingALanguageFromAPageThatStaysSendsThePage(): void
    {
        $page = new ListenerTestPage(7);
        $page->translate('en_US', 'Returns', '30 days');
        $german = $page->translate('de_DE', 'Rückgabe', '30 Tage');
        $page->getTranslations()->remove('de_DE');
        $this->unitOfWork->method('isScheduledForDelete')->willReturn(false);

        $this->listener()->preRemove(new PreRemoveEventArgs($german, $this->entityManager));
        $events = $this->flushed();

        $this->assertSame('page.updated', $events[0]['type']);
        $this->assertSame(['en_US'], array_keys($events[0]['data']['titles']['default']));
    }

    public function testDeletingAPageSendsOnlyItsDeletion(): void
    {
        $page = new ListenerTestPage(7);
        $english = $page->translate('en_US', 'Returns', '30 days');
        $listener = $this->listener();

        // Doctrine cascades to the translations before the page is scheduled.
        $this->unitOfWork->method('isScheduledForDelete')->willReturn(false);
        $listener->preRemove(new PreRemoveEventArgs($english, $this->entityManager));
        $listener->preRemove(new PreRemoveEventArgs($page, $this->entityManager));
        $this->scheduledDeletions = [$page];
        $page->deleted();
        $events = $this->flushed();

        $this->assertSame([['type' => 'page.deleted', 'data' => ['identification_number' => 'page-7']]], $events);
    }

    public function testDisablingAPageSendsItsDeletion(): void
    {
        $page = new ListenerTestPage(7);
        $page->translate('en_US', 'Returns', '30 days');
        $page->enabled = false;

        $this->listener()->postUpdate(new PostUpdateEventArgs($page, $this->entityManager));
        $events = $this->flushed();

        $this->assertSame([['type' => 'page.deleted', 'data' => ['identification_number' => 'page-7']]], $events);
    }

    public function testCreatingADisabledPageIsNotSentAsCreated(): void
    {
        $page = new ListenerTestPage(9);
        $page->translate('en_US', 'Draft', 'Soon');
        $page->enabled = false;

        $this->listener()->postPersist(new PostPersistEventArgs($page, $this->entityManager));

        $this->assertSame(['page.deleted'], array_column($this->flushed(), 'type'));
    }

    /**
     * Nothing reaches the queue before Doctrine commits: a page change is
     * sent at postFlush, as the page is then, once however often it changed.
     */
    public function testNothingIsQueuedBeforeTheFlushCommits(): void
    {
        $page = new ListenerTestPage(7);
        $english = $page->translate('en_US', 'Returns', '30 days');
        $listener = $this->listener();

        $listener->postUpdate(new PostUpdateEventArgs($page, $this->entityManager));
        $listener->postUpdate(new PostUpdateEventArgs($english, $this->entityManager));
        $this->assertFalse($this->queue->hasPending());

        $english->content = '45 days';
        $events = $this->flushed();

        $this->assertCount(1, $events);
        $this->assertSame('45 days', $events[0]['data']['contents']['default']['en_US']);
    }

    /** A delete Doctrine rolled back keeps the page's id: nothing is sent. */
    public function testADeleteThatDidNotCommitSendsNothing(): void
    {
        $page = new ListenerTestPage(7);
        $page->translate('en_US', 'Returns', '30 days');
        $listener = $this->listener();

        $listener->preRemove(new PreRemoveEventArgs($page, $this->entityManager));
        $this->flushed();

        $this->assertSame([], $this->sent);
    }

    /**
     * A failed flush closes its manager and never reaches postFlush; what it
     * buffered is not sent with a later flush of another manager.
     */
    public function testChangesLeftByAFailedFlushAreNotSentLater(): void
    {
        $page = new ListenerTestPage(7);
        $page->translate('en_US', 'Returns', 'uncommitted text');
        $listener = $this->listener();
        $listener->postUpdate(new PostUpdateEventArgs($page, $this->entityManager));
        $this->notManaged[] = $page;

        $this->flushed();

        $this->assertSame([], $this->sent);
    }

    /**
     * Review point 4: a delete rolled back by a failed flush leaves the
     * page's id nulled (Doctrine does not restore it). The next flush has
     * not scheduled it, so its buffered deletion is dropped, not sent.
     */
    public function testARolledBackDeleteIsNotSentWithTheNextFlush(): void
    {
        $page = new ListenerTestPage(7);
        $page->translate('en_US', 'Returns', '30 days');
        $listener = $this->listener();
        $listener->preRemove(new PreRemoveEventArgs($page, $this->entityManager));
        $page->deleted();

        $this->scheduledDeletions = [];
        $this->flushed();

        $this->assertSame([], $this->sent);
    }

    /** Between messages of a worker, and when a message fails, nothing buffered survives. */
    public function testResetClearsTheBuffers(): void
    {
        $page = new ListenerTestPage(7);
        $page->translate('en_US', 'Returns', '30 days');
        $listener = $this->listener();
        $listener->postUpdate(new PostUpdateEventArgs($page, $this->entityManager));
        $listener->preRemove(new PreRemoveEventArgs($page, $this->entityManager));
        $this->scheduledDeletions = [$page];

        $listener->reset();
        $this->flushed();

        $this->assertSame([], $this->sent);
    }

    public function testTheListenerIsResetWhenAnAsyncMessageFails(): void
    {
        $listeners = (new \ReflectionClass(PageDoctrineListener::class))->getAttributes(\Symfony\Component\EventDispatcher\Attribute\AsEventListener::class);

        $this->assertSame(\Symfony\Component\Messenger\Event\WorkerMessageFailedEvent::class, $listeners[0]->getArguments()['event']);
        $this->assertSame('reset', $listeners[0]->getArguments()['method']);
        $this->assertInstanceOf(\Symfony\Contracts\Service\ResetInterface::class, $this->listener());
    }

    public function testATranslationOfAnotherEntityIsIgnored(): void
    {
        $translation = new ListenerTestPageTranslation(new \stdClass(), 'en_US', 'Mug', 'Blue');

        $this->listener()->postUpdate(new PostUpdateEventArgs($translation, $this->entityManager));

        $this->assertFalse($this->queue->hasPending());
    }
}
