<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Emporiqa\SyliusPlugin\Model\PageInterface;
use Emporiqa\SyliusPlugin\Trait\TranslationHelperTrait;
use Psr\Log\LoggerInterface;
use Sylius\Component\Channel\Model\ChannelsAwareInterface;

class PageFormatter implements PageFormatterInterface
{
    use TranslationHelperTrait;

    public function __construct(
        private PageUrlResolverInterface $urlResolver,
        private ChannelMappingResolver $channelMappingResolver,
        private array $enabledLanguages = [],
        private ?LoggerInterface $logger = null,
    ) {}

    public function format(PageInterface $page): array
    {
        try {
            $translations = $page->getTranslations();
        } catch (\Throwable) {
            $this->logger?->warning('Failed to load translations for page', ['page_id' => $page->getId()]);
            return [];
        }

        if ($translations === null || $translations->isEmpty()) {
            $this->logger?->debug('Page has no translations', ['page_id' => $page->getId()]);
            return [];
        }

        $channelKeys = $this->getChannelKeysForPage($page);
        if (empty($channelKeys)) {
            $this->logger?->debug('Page has no channels, skipping', ['page_id' => $page->getId()]);
            return [];
        }

        $titles = [];
        $contents = [];
        $links = [];

        foreach ($channelKeys as $empChannelKey) {
            foreach ($this->enabledLanguages as $locale) {
                $translation = $this->findTranslationForLocale($translations, $locale);
                if (!$translation || !$translation->getTitle()) {
                    $this->logger?->debug('Page translation missing for locale', [
                        'page_id' => $page->getId(),
                        'locale' => $locale,
                    ]);
                    continue;
                }

                $lang = $locale;

                $titles[$empChannelKey][$lang] = $translation->getTitle();
                $contents[$empChannelKey][$lang] = strip_tags($translation->getContent() ?? '');
                $links[$empChannelKey][$lang] = $this->urlResolver->resolveUrl($page, $locale);
            }
        }

        if (empty($titles)) {
            return [];
        }

        return [
            [
                'type' => 'page.updated',
                'data' => [
                    'identification_number' => 'page-' . $page->getId(),
                    'channels' => $channelKeys,
                    'titles' => $titles,
                    'contents' => $contents,
                    'links' => $links,
                ],
            ],
        ];
    }

    private function getChannelKeysForPage(PageInterface $page): array
    {
        if (!$page instanceof ChannelsAwareInterface) {
            return $this->channelMappingResolver->getAllKeys();
        }

        $channels = $page->getChannels();
        if ($channels->isEmpty()) {
            return [];
        }

        $keys = [];
        foreach ($channels as $channel) {
            $empKey = $this->channelMappingResolver->resolveKey($channel);
            if (!in_array($empKey, $keys, true)) {
                $keys[] = $empKey;
            }
        }

        return $keys;
    }

    public function formatForDeletion(PageInterface $page): array
    {
        return [
            [
                'type' => 'page.deleted',
                'data' => [
                    'identification_number' => 'page-' . $page->getId(),
                ],
            ],
        ];
    }
}
