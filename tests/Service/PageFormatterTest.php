<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Service;

use Doctrine\Common\Collections\ArrayCollection;
use Emporiqa\SyliusPlugin\Model\PageInterface;
use Emporiqa\SyliusPlugin\Service\ChannelMappingResolver;
use Emporiqa\SyliusPlugin\Service\PageFormatter;
use Emporiqa\SyliusPlugin\Service\PageUrlResolverInterface;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Model\ChannelsAwareInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;

class PageFormatterTest extends TestCase
{
    private PageUrlResolverInterface $urlResolver;

    protected function setUp(): void
    {
        $this->urlResolver = $this->createMock(PageUrlResolverInterface::class);
        $this->urlResolver->method('resolveUrl')->willReturn('https://shop.example.com/page');
    }

    private function createPage(array $translations): PageInterface
    {
        $translationObjects = [];
        foreach ($translations as $locale => $data) {
            $t = new class($locale, $data['title'], $data['content'] ?? '') {
                public function __construct(
                    private string $locale,
                    private string $title,
                    private string $content,
                ) {}
                public function getLocale(): string { return $this->locale; }
                public function getTitle(): string { return $this->title; }
                public function getContent(): string { return $this->content; }
            };
            $translationObjects[] = $t;
        }

        $page = $this->createMock(PageInterface::class);
        $page->method('getId')->willReturn(1);
        $page->method('getTranslations')->willReturn(new ArrayCollection($translationObjects));

        return $page;
    }

    public function testFormatSingleChannelDefaultKey(): void
    {
        $ch = $this->createMock(ChannelInterface::class);
        $ch->method('getCode')->willReturn('default');

        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findAll')->willReturn([$ch]);

        $formatter = new PageFormatter(
            $this->urlResolver,
            new ChannelMappingResolver($channelRepo),
            ['en_US'],
        );

        $page = $this->createPage(['en_US' => ['title' => 'Shipping', 'content' => 'Free shipping']]);
        $events = $formatter->format($page);

        $this->assertCount(1, $events);
        $this->assertSame('page.updated', $events[0]['type']);
        $this->assertSame('page-1', $events[0]['data']['identification_number']);
        $this->assertSame(['default'], $events[0]['data']['channels']);
        $this->assertSame('Shipping', $events[0]['data']['titles']['default']['en_US']);
        $this->assertSame('Free shipping', $events[0]['data']['contents']['default']['en_US']);
    }

    public function testFormatWithExplicitChannelMapping(): void
    {
        $ch1 = $this->createMock(ChannelInterface::class);
        $ch1->method('getCode')->willReturn('WEB');
        $ch2 = $this->createMock(ChannelInterface::class);
        $ch2->method('getCode')->willReturn('B2B');

        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findAll')->willReturn([$ch1, $ch2]);

        $formatter = new PageFormatter(
            $this->urlResolver,
            new ChannelMappingResolver($channelRepo),
            ['en_US'],
        );

        $page = $this->createPage(['en_US' => ['title' => 'FAQ', 'content' => 'Questions']]);
        $events = $formatter->format($page);

        $this->assertCount(1, $events);
        $channels = $events[0]['data']['channels'];
        $this->assertContains('WEB', $channels);
        $this->assertContains('B2B', $channels);
        $this->assertSame('FAQ', $events[0]['data']['titles']['WEB']['en_US']);
        $this->assertSame('FAQ', $events[0]['data']['titles']['B2B']['en_US']);
    }

    public function testFormatWithAutoDetectedChannels(): void
    {
        $ch1 = $this->createMock(ChannelInterface::class);
        $ch1->method('getCode')->willReturn('default');
        $ch2 = $this->createMock(ChannelInterface::class);
        $ch2->method('getCode')->willReturn('B2B');

        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findAll')->willReturn([$ch1, $ch2]);

        $formatter = new PageFormatter(
            $this->urlResolver,
            new ChannelMappingResolver($channelRepo),
            ['en_US'],
        );

        $page = $this->createPage(['en_US' => ['title' => 'Terms', 'content' => 'Terms content']]);
        $events = $formatter->format($page);

        $this->assertCount(1, $events);
        $this->assertSame(['default', 'B2B'], $events[0]['data']['channels']);
        $this->assertSame('Terms', $events[0]['data']['titles']['default']['en_US']);
        $this->assertSame('Terms', $events[0]['data']['titles']['B2B']['en_US']);
    }

    public function testFormatSingleAutoDetectedChannelUsesDefault(): void
    {
        $ch1 = $this->createMock(ChannelInterface::class);
        $ch1->method('getCode')->willReturn('default');

        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findAll')->willReturn([$ch1]);

        $formatter = new PageFormatter(
            $this->urlResolver,
            new ChannelMappingResolver($channelRepo),
            ['en_US'],
        );

        $page = $this->createPage(['en_US' => ['title' => 'About', 'content' => 'About us']]);
        $events = $formatter->format($page);

        $this->assertSame(['default'], $events[0]['data']['channels']);
    }

    public function testFormatMultiLanguage(): void
    {
        $ch = $this->createMock(ChannelInterface::class);
        $ch->method('getCode')->willReturn('default');

        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findAll')->willReturn([$ch]);

        $formatter = new PageFormatter(
            $this->urlResolver,
            new ChannelMappingResolver($channelRepo),
            ['en_US', 'de_DE'],
        );

        $page = $this->createPage([
            'en_US' => ['title' => 'Shipping', 'content' => 'Free shipping'],
            'de_DE' => ['title' => 'Versand', 'content' => 'Kostenloser Versand'],
        ]);
        $events = $formatter->format($page);

        $this->assertSame('Shipping', $events[0]['data']['titles']['default']['en_US']);
        $this->assertSame('Versand', $events[0]['data']['titles']['default']['de_DE']);
    }

    public function testFormatPageWithNoTranslationsReturnsEmpty(): void
    {
        $formatter = new PageFormatter($this->urlResolver, new ChannelMappingResolver(), ['en_US']);

        $page = $this->createMock(PageInterface::class);
        $page->method('getId')->willReturn(1);
        $page->method('getTranslations')->willReturn(new ArrayCollection());

        $events = $formatter->format($page);

        $this->assertEmpty($events);
    }

    public function testFormatForDeletion(): void
    {
        $formatter = new PageFormatter($this->urlResolver, new ChannelMappingResolver(), ['en_US']);

        $page = $this->createMock(PageInterface::class);
        $page->method('getId')->willReturn(42);

        $events = $formatter->formatForDeletion($page);

        $this->assertCount(1, $events);
        $this->assertSame('page.deleted', $events[0]['type']);
        $this->assertSame('page-42', $events[0]['data']['identification_number']);
    }

    public function testFormatStripsHtmlFromContent(): void
    {
        $ch = $this->createMock(ChannelInterface::class);
        $ch->method('getCode')->willReturn('default');

        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findAll')->willReturn([$ch]);

        $formatter = new PageFormatter($this->urlResolver, new ChannelMappingResolver($channelRepo), ['en_US']);

        $page = $this->createPage([
            'en_US' => ['title' => 'Policy', 'content' => '<p>No <strong>returns</strong></p>'],
        ]);
        $events = $formatter->format($page);

        $this->assertSame('No returns', $events[0]['data']['contents']['default']['en_US']);
    }

    public function testFormatSkipsLocalesWithMissingTitle(): void
    {
        $ch = $this->createMock(ChannelInterface::class);
        $ch->method('getCode')->willReturn('default');

        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findAll')->willReturn([$ch]);

        $formatter = new PageFormatter($this->urlResolver, new ChannelMappingResolver($channelRepo), ['en_US', 'de_DE']);

        $page = $this->createPage([
            'en_US' => ['title' => 'Help', 'content' => 'Help content'],
        ]);
        $events = $formatter->format($page);

        $this->assertArrayHasKey('en_US', $events[0]['data']['titles']['default']);
        $this->assertArrayNotHasKey('de_DE', $events[0]['data']['titles']['default']);
    }

    public function testFormatChannelsAwarePageUsesPageChannels(): void
    {
        $ch1 = $this->createMock(ChannelInterface::class);
        $ch1->method('getCode')->willReturn('WEB');

        $formatter = new PageFormatter(
            $this->urlResolver,
            new ChannelMappingResolver(),
            ['en_US'],
        );

        $page = $this->createChannelsAwarePage(
            ['en_US' => ['title' => 'FAQ', 'content' => 'Questions']],
            [$ch1],
        );
        $events = $formatter->format($page);

        $this->assertCount(1, $events);
        $this->assertSame(['WEB'], $events[0]['data']['channels']);
        $this->assertSame('FAQ', $events[0]['data']['titles']['WEB']['en_US']);
        $this->assertArrayNotHasKey('B2B', $events[0]['data']['titles']);
    }

    public function testFormatChannelsAwarePageWithNoChannelsReturnsEmpty(): void
    {
        $formatter = new PageFormatter(
            $this->urlResolver,
            new ChannelMappingResolver(),
            ['en_US'],
        );

        $page = $this->createChannelsAwarePage(
            ['en_US' => ['title' => 'FAQ', 'content' => 'Questions']],
            [],
        );
        $events = $formatter->format($page);

        $this->assertEmpty($events);
    }

    public function testFormatChannelsAwarePageWithMultipleChannels(): void
    {
        $ch1 = $this->createMock(ChannelInterface::class);
        $ch1->method('getCode')->willReturn('WEB');
        $ch2 = $this->createMock(ChannelInterface::class);
        $ch2->method('getCode')->willReturn('B2B');

        $formatter = new PageFormatter(
            $this->urlResolver,
            new ChannelMappingResolver(),
            ['en_US'],
        );

        $page = $this->createChannelsAwarePage(
            ['en_US' => ['title' => 'Terms', 'content' => 'Terms content']],
            [$ch1, $ch2],
        );
        $events = $formatter->format($page);

        $this->assertCount(1, $events);
        $this->assertSame(['WEB', 'B2B'], $events[0]['data']['channels']);
        $this->assertSame('Terms', $events[0]['data']['titles']['WEB']['en_US']);
        $this->assertSame('Terms', $events[0]['data']['titles']['B2B']['en_US']);
    }

    public function testFormatChannelsAwarePageWithAutoDetection(): void
    {
        $ch1 = $this->createMock(ChannelInterface::class);
        $ch1->method('getCode')->willReturn('default');
        $ch2 = $this->createMock(ChannelInterface::class);
        $ch2->method('getCode')->willReturn('B2B');

        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findAll')->willReturn([$ch1, $ch2]);

        $formatter = new PageFormatter(
            $this->urlResolver,
            new ChannelMappingResolver($channelRepo),
            ['en_US'],
        );

        // Page only assigned to the B2B channel
        $page = $this->createChannelsAwarePage(
            ['en_US' => ['title' => 'B2B Only', 'content' => 'Wholesale']],
            [$ch2],
        );
        $events = $formatter->format($page);

        $this->assertCount(1, $events);
        $this->assertSame(['B2B'], $events[0]['data']['channels']);
        $this->assertArrayNotHasKey('default', $events[0]['data']['titles']);
    }

    private function createChannelsAwarePage(array $translations, array $channels): PageInterface
    {
        $translationObjects = [];
        foreach ($translations as $locale => $data) {
            $t = new class($locale, $data['title'], $data['content'] ?? '') {
                public function __construct(
                    private string $locale,
                    private string $title,
                    private string $content,
                ) {}
                public function getLocale(): string { return $this->locale; }
                public function getTitle(): string { return $this->title; }
                public function getContent(): string { return $this->content; }
            };
            $translationObjects[] = $t;
        }

        $page = $this->createMock(ChannelsAwarePageInterface::class);
        $page->method('getId')->willReturn(1);
        $page->method('getTranslations')->willReturn(new ArrayCollection($translationObjects));
        $page->method('getChannels')->willReturn(new ArrayCollection($channels));

        return $page;
    }
}

interface ChannelsAwarePageInterface extends PageInterface, ChannelsAwareInterface
{
}
