<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Command;

use Doctrine\ORM\EntityManagerInterface;
use Emporiqa\SyliusPlugin\Service\PageFormatterInterface;
use Emporiqa\SyliusPlugin\Service\WebhookSenderInterface;
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
    ) {
        parent::__construct($webhookSender);
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
            $query = $this->entityManager
                ->getRepository($class)
                ->createQueryBuilder('p')
                ->getQuery();

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
            $total += (int) $this->entityManager
                ->getRepository($class)
                ->createQueryBuilder('p')
                ->select('COUNT(p.id)')
                ->getQuery()
                ->getSingleScalarResult();
        }

        return $total;
    }

    protected function formatEntity(object $entity): array
    {
        return $this->formatter->format($entity);
    }
}
