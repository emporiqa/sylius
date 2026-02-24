<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\EventListener;

use Emporiqa\SyliusPlugin\Event\PreSyncEvent;
use Emporiqa\SyliusPlugin\Model\PageInterface;
use Emporiqa\SyliusPlugin\Service\PageFormatterInterface;
use Emporiqa\SyliusPlugin\Service\WebhookEventQueue;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
class PageDoctrineListener
{
    /** @param list<string> $pageEntityClasses */
    public function __construct(
        private WebhookEventQueue $webhookQueue,
        private PageFormatterInterface $formatter,
        private array $pageEntityClasses = [],
        private bool $syncEnabled = true,
        private ?LoggerInterface $logger = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {}

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$this->supports($entity)) {
            return;
        }

        /** @var PageInterface $entity */
        if ($this->isSyncCancelled($entity, 'create')) {
            return;
        }

        try {
            $events = $this->formatter->format($entity);
            foreach ($events as &$event) {
                $event['type'] = 'page.created';
            }
            unset($event);

            $this->webhookQueue->queue($events);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to queue page create webhook', [
                'page_id' => $entity->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$this->supports($entity)) {
            return;
        }

        /** @var PageInterface $entity */
        if ($this->isSyncCancelled($entity, 'update')) {
            return;
        }

        try {
            $events = $this->formatter->format($entity);
            $this->webhookQueue->queue($events);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to queue page update webhook', [
                'page_id' => $entity->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$this->supports($entity)) {
            return;
        }

        /** @var PageInterface $entity */
        if ($this->isSyncCancelled($entity, 'delete')) {
            return;
        }

        try {
            $events = $this->formatter->formatForDeletion($entity);
            $this->webhookQueue->queue($events);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to queue page delete webhook', [
                'page_id' => $entity->getId(),
                'error' => $e->getMessage(),
            ]);
        }
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
