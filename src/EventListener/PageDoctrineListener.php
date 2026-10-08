<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\EventListener;

use Emporiqa\SyliusPlugin\Event\PreSyncEvent;
use Emporiqa\SyliusPlugin\Model\PageInterface;
use Emporiqa\SyliusPlugin\Service\PageFormatterInterface;
use Emporiqa\SyliusPlugin\Service\WebhookEventQueue;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Page changes reach the queue only once Doctrine has committed them
 * (postFlush): until then they are buffered here. A page change is sent as
 * the page is at postFlush, so a page saved several times in one flush is
 * built once.
 *
 * A failed flush rolls back and closes its entity manager, so its postFlush
 * never comes; whatever it left in the buffer is dropped later: on onFlush
 * a buffered deletion stays only while Doctrine has it scheduled (a page
 * removed since the last flush), at postFlush a changed page the flushing
 * manager does not manage is not sent, and a deletion goes only when
 * Doctrine nulled the page's (generated) id. The buffers are also cleared
 * when an async message fails and when the service is reset between
 * messages of a long-running worker. (onFlush does not clear the changed
 * pages: the locale removals Doctrine processes just before it are this
 * flush's.)
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
#[AsEventListener(event: WorkerMessageFailedEvent::class, method: 'reset')]
class PageDoctrineListener implements ResetInterface
{
    /**
     * Pages inserted by the flush running now. Their translations are
     * inserted after them and are already in the page's own created event.
     *
     * @var \WeakMap<PageInterface, true>
     */
    private \WeakMap $createdPages;

    /**
     * Pages changed in the flush running now, with the event type to send.
     *
     * @var \WeakMap<PageInterface, string>
     */
    private \WeakMap $changedPages;

    /** @var list<array{0: PageInterface, 1: list<array>}> deletions built while the ids still exist */
    private array $deletedPages = [];

    /** @param list<string> $pageEntityClasses */
    public function __construct(
        private WebhookEventQueue $webhookQueue,
        private PageFormatterInterface $formatter,
        private array $pageEntityClasses = [],
        private bool $syncEnabled = true,
        private ?LoggerInterface $logger = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {
        $this->createdPages = new \WeakMap();
        $this->changedPages = new \WeakMap();
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        $page = $this->pageOfTranslation($entity);
        if ($page !== null) {
            if (!isset($this->createdPages[$page])) {
                $this->markUpdated($page);
            }

            return;
        }
        if (!$this->supports($entity)) {
            return;
        }

        /** @var PageInterface $entity */
        $this->createdPages[$entity] = true;
        if (!$this->isSyncCancelled($entity, 'create')) {
            $this->changedPages[$entity] = 'page.created';
        }
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        $page = $this->pageOfTranslation($entity) ?? ($this->supports($entity) ? $entity : null);
        if ($page !== null) {
            $this->markUpdated($page);
        }
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        $page = $this->pageOfTranslation($entity);
        if ($page !== null) {
            // A locale removed from a page that stays; when the page itself is
            // being deleted its own preRemove builds the deletion.
            if (!$args->getObjectManager()->getUnitOfWork()->isScheduledForDelete($page)) {
                $this->markUpdated($page);
            }

            return;
        }
        if (!$this->supports($entity)) {
            return;
        }

        /** @var PageInterface $entity */
        if ($this->isSyncCancelled($entity, 'delete')) {
            return;
        }

        try {
            $this->deletedPages[] = [$entity, $this->formatter->formatForDeletion($entity)];
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to queue page delete webhook', [
                'page_id' => $entity->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $scheduled = $args->getObjectManager()->getUnitOfWork()->getScheduledEntityDeletions();
        $this->deletedPages = array_values(array_filter(
            $this->deletedPages,
            fn (array $entry): bool => in_array($entry[0], $scheduled, true),
        ));
    }

    public function reset(): void
    {
        $this->createdPages = new \WeakMap();
        $this->changedPages = new \WeakMap();
        $this->deletedPages = [];
    }

    public function postFlush(?PostFlushEventArgs $args = null): void
    {
        $manager = $args?->getObjectManager();
        $changed = $this->changedPages;
        $deleted = $this->deletedPages;
        $this->createdPages = new \WeakMap();
        $this->changedPages = new \WeakMap();
        $this->deletedPages = [];

        foreach ($deleted as [$page, $events]) {
            // Doctrine nulls a generated id once the row is deleted; a page
            // whose delete was rolled back keeps it and stays in Emporiqa.
            if ($page->getId() === null) {
                $this->webhookQueue->queue($events);
                unset($changed[$page]);
            }
        }

        foreach ($changed as $page => $type) {
            if ($page->getId() === null || ($manager !== null && !$manager->contains($page))) {
                continue;
            }

            try {
                $events = $this->formatter->format($page);
                if ($type === 'page.created') {
                    foreach ($events as &$event) {
                        if ($event['type'] === 'page.updated') {
                            $event['type'] = 'page.created';
                        }
                    }
                    unset($event);
                }
                $this->webhookQueue->queue($events);
            } catch (\Throwable $e) {
                $this->logger?->error('Failed to queue page webhook', [
                    'page_id' => $page->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function markUpdated(PageInterface $page): void
    {
        if ($page->getId() === null || isset($this->changedPages[$page]) || $this->isSyncCancelled($page, 'update')) {
            return;
        }
        $this->changedPages[$page] = 'page.updated';
    }

    /**
     * The supported page a translation entity belongs to: editing only a
     * page's text in one language changes the translation row alone, and
     * Doctrine then reports no change on the page.
     */
    private function pageOfTranslation(object $entity): ?PageInterface
    {
        if ($entity instanceof PageInterface || !method_exists($entity, 'getTranslatable')) {
            return null;
        }

        try {
            $translatable = $entity->getTranslatable();
        } catch (\Throwable) {
            return null;
        }

        return is_object($translatable) && $this->supports($translatable) ? $translatable : null;
    }

    private function supports(object $entity): bool
    {
        if (!$this->syncEnabled || empty($this->pageEntityClasses)) {
            return false;
        }

        if (!$entity instanceof PageInterface) {
            return false;
        }

        foreach ($this->pageEntityClasses as $class) {
            if (is_a($entity, $class)) {
                return true;
            }
        }

        return false;
    }

    private function isSyncCancelled(PageInterface $entity, string $operation): bool
    {
        if (!$this->eventDispatcher) {
            return false;
        }

        $event = new PreSyncEvent($entity, 'page', $operation);
        $this->eventDispatcher->dispatch($event, PreSyncEvent::NAME);

        return $event->isCancelled();
    }
}
