<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Command;

use Doctrine\ORM\EntityManagerInterface;
use Emporiqa\SyliusPlugin\Service\ProductFormatterInterface;
use Emporiqa\SyliusPlugin\Service\WebhookEventQueue;
use Emporiqa\SyliusPlugin\Service\WebhookSenderInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(
    name: 'emporiqa:sync:products',
    description: 'Sync all products to Emporiqa',
)]
class SyncProductsCommand extends AbstractSyncCommand
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private ProductFormatterInterface $formatter,
        private EntityManagerInterface $entityManager,
        WebhookSenderInterface $webhookSender,
        ?LoggerInterface $logger = null,
        ?WebhookEventQueue $webhookQueue = null,
        string $webhookUrl = '',
    ) {
        parent::__construct($webhookSender, $logger, $webhookQueue, $webhookUrl);
    }

    protected function getEntityLabel(): string
    {
        return 'Products';
    }

    protected function getEntityName(): string
    {
        return 'products';
    }

    /**
     * Enabled products only: a disabled one has no page in the shop, and the
     * session's end removes it from Emporiqa.
     */
    protected function fetchEntities(): iterable
    {
        $query = $this->productRepository
            ->createQueryBuilder('p')
            ->andWhere('p.enabled = :enabled')
            ->setParameter('enabled', true)
            ->getQuery();

        foreach ($query->toIterable() as $product) {
            yield $product;
            $this->entityManager->detach($product);
        }
    }

    protected function getTotalCount(): int
    {
        return (int) $this->productRepository
            ->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.enabled = :enabled')
            ->setParameter('enabled', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    protected function formatEntity(object $entity): array
    {
        return $this->formatter->format($entity);
    }
}
