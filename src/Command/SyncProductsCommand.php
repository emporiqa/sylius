<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Command;

use Doctrine\ORM\EntityManagerInterface;
use Emporiqa\SyliusPlugin\Service\ProductFormatterInterface;
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
    ) {
        parent::__construct($webhookSender, $logger);
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
        $query = $this->productRepository
            ->createQueryBuilder('p')
            ->select('p', 'v', 'ch', 't', 'img')
            ->leftJoin('p.variants', 'v')
            ->leftJoin('p.channels', 'ch')
            ->leftJoin('p.translations', 't')
            ->leftJoin('p.images', 'img')
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
            ->getQuery()
            ->getSingleScalarResult();
    }

    protected function formatEntity(object $entity): array
    {
        return $this->formatter->format($entity);
    }
}
