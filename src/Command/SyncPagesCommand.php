<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Emporiqa\SyliusPlugin\Service\PageFormatterInterface;
use Emporiqa\SyliusPlugin\Service\WebhookEventQueue;
use Emporiqa\SyliusPlugin\Service\WebhookSenderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'emporiqa:sync:pages',
    description: 'Sync all static pages to Emporiqa',
)]
class SyncPagesCommand extends AbstractSyncCommand
{
    /** @param list<string> $pageEntityClasses */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PageFormatterInterface $formatter,
        private array $pageEntityClasses,
        WebhookSenderInterface $webhookSender,
        ?LoggerInterface $logger = null,
        ?WebhookEventQueue $webhookQueue = null,
        string $webhookUrl = '',
    ) {
        parent::__construct($webhookSender, $logger, $webhookQueue, $webhookUrl);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (empty($this->pageEntityClasses)) {
            $io = new SymfonyStyle($input, $output);
            $io->warning('Page sync not configured. Set emporiqa.page_entity_classes to enable.');
            return self::SUCCESS;
        }

        return parent::execute($input, $output);
    }

    protected function getEntityLabel(): string
    {
        return 'Pages';
    }

    protected function getEntityName(): string
    {
        return 'pages';
    }

    protected function fetchEntities(): iterable
    {
        foreach ($this->pageEntityClasses as $class) {
            $query = $this->pagesQuery($class)->getQuery();

            foreach ($query->toIterable() as $entity) {
                yield $entity;
                $this->entityManager->detach($entity);
            }
        }
    }

    protected function getTotalCount(): int
    {
        $total = 0;

        foreach ($this->pageEntityClasses as $class) {
            $total += (int) $this->pagesQuery($class)
                ->select('COUNT(p.id)')
                ->getQuery()
                ->getSingleScalarResult();
        }

        return $total;
    }

    /**
     * A page entity with an `enabled` field: the enabled ones only. The
     * session's end removes the others from Emporiqa.
     *
     * @param class-string $class
     */
    private function pagesQuery(string $class): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()->select('p')->from($class, 'p');
        if ($this->entityManager->getClassMetadata($class)->hasField('enabled')) {
            $query->andWhere('p.enabled = :enabled')->setParameter('enabled', true);
        }

        return $query;
    }

    protected function formatEntity(object $entity): array
    {
        return $this->formatter->format($entity);
    }
}
