<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Command;

use Emporiqa\SyliusPlugin\Service\ProductFormatterInterface;
use Emporiqa\SyliusPlugin\Service\WebhookSenderInterface;
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
        WebhookSenderInterface $webhookSender,
        array $enabledLanguages,
    ) {
        parent::__construct($webhookSender, $enabledLanguages);
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
        return $this->productRepository->findAll();
    }

    protected function getTotalCount(): int
    {
        return (int) $this->productRepository
            ->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    protected function formatEntityForLanguage(object $entity, string $locale): array
    {
        return $this->formatter->formatForLanguage($entity, $locale);
    }
}
