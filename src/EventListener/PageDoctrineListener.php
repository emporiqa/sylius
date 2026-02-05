<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\EventListener;

use Emporiqa\SyliusPlugin\Model\PageInterface;
use Emporiqa\SyliusPlugin\Service\PageFormatterInterface;
use Emporiqa\SyliusPlugin\Service\WebhookSenderInterface;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
class PageDoctrineListener
{
    /** @param list<string> $pageEntityClasses */
    public function __construct(
        private WebhookSenderInterface $webhookSender,
        private PageFormatterInterface $formatter,
        private array $pageEntityClasses = [],
        private bool $syncEnabled = true,
        private ?LoggerInterface $logger = null,
    ) {}

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$this->supports($entity)) {
            return;
        }

        /** @var PageInterface $entity */
        $events = $this->formatter->format($entity);
        foreach ($events as &$event) {
            $event['type'] = 'page.created';
        }
        unset($event);

        try {
            $this->webhookSender->sendBatch($events);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to send page create webhook', [
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
        $events = $this->formatter->format($entity);

        try {
            $this->webhookSender->sendBatch($events);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to send page update webhook', [
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
        $events = $this->formatter->formatForDeletion($entity);

        try {
            $this->webhookSender->sendBatch($events);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to send page delete webhook', [
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
}
