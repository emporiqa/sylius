<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use Emporiqa\SyliusPlugin\Command\SyncPagesCommand;
use Emporiqa\SyliusPlugin\Service\PageFormatterInterface;
use Emporiqa\SyliusPlugin\Service\WebhookSenderInterface;
use PHPUnit\Framework\TestCase;

class SyncPagesCommandTest extends TestCase
{
    private function pagesQuery(bool $hasEnabledField): QueryBuilder
    {
        $metadata = $this->createStub(ClassMetadata::class);
        $metadata->method('hasField')->willReturnCallback(fn (string $field): bool => $hasEnabledField && $field === 'enabled');
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn($metadata);
        $em->method('createQueryBuilder')->willReturnCallback(fn (): QueryBuilder => new QueryBuilder($em));

        $command = new SyncPagesCommand(
            $em,
            $this->createStub(PageFormatterInterface::class),
            ['App\\Entity\\Page'],
            $this->createStub(WebhookSenderInterface::class),
        );

        return (new \ReflectionMethod($command, 'pagesQuery'))->invoke($command, 'App\\Entity\\Page');
    }

    /** A full sync sends enabled pages only; the session's end removes the disabled ones. */
    public function testAPageClassWithAnEnabledFieldSyncsEnabledPagesOnly(): void
    {
        $query = $this->pagesQuery(true);

        $this->assertStringContainsString('p.enabled = :enabled', $query->getDQL());
        $this->assertTrue($query->getParameter('enabled')?->getValue());
    }

    public function testAPageClassWithoutAnEnabledFieldSyncsEveryPage(): void
    {
        $query = $this->pagesQuery(false);

        $this->assertSame('SELECT p FROM App\\Entity\\Page p', $query->getDQL());
    }
}
