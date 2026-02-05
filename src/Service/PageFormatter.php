<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Service;

use Emporiqa\SyliusPlugin\Model\PageInterface;
use Emporiqa\SyliusPlugin\Trait\TranslationHelperTrait;
use Psr\Log\LoggerInterface;

class PageFormatter implements PageFormatterInterface
{
    use TranslationHelperTrait;

    public function __construct(
        private PageUrlResolverInterface $urlResolver,
        private array $enabledLanguages = [],
        private ?LoggerInterface $logger = null,
    ) {}

    public function format(PageInterface $page): array
    {
        $events = [];

        try {
            $translations = $page->getTranslations();
        } catch (\Throwable) {
            $this->logger?->warning('Failed to load translations for page', ['page_id' => $page->getId()]);
            return $events;
        }

        if ($translations === null || $translations->isEmpty()) {
            $this->logger?->debug('Page has no translations', ['page_id' => $page->getId()]);
            return $events;
        }

        foreach ($this->enabledLanguages as $locale) {
            $events = array_merge($events, $this->formatForLanguage($page, $locale));
        }

        return $events;
    }

    public function formatForLanguage(PageInterface $page, string $locale): array
    {
        $events = [];

        try {
            $translations = $page->getTranslations();
        } catch (\Throwable) {
            return $events;
        }

        if ($translations === null || $translations->isEmpty()) {
            return $events;
        }

        $translation = $this->findTranslationForLocale($translations, $locale);
        if (!$translation || !$translation->getTitle()) {
            $this->logger?->debug('Page translation missing for locale', [
                'page_id' => $page->getId(),
                'locale' => $locale,
            ]);
            return $events;
        }

        $events[] = [
            'type' => 'page.updated',
            'data' => [
                'identification_number' => 'page-' . $page->getId(),
                'name' => $translation->getTitle(),
                'link' => $this->urlResolver->resolveUrl($page, $locale),
                'description' => strip_tags($translation->getContent() ?? ''),
                'language' => $this->getShortLanguageCode($locale),
            ],
        ];

        return $events;
    }

    public function formatForDeletion(PageInterface $page): array
    {
        $events = [];

        foreach ($this->enabledLanguages as $locale) {
            $events[] = [
                'type' => 'page.deleted',
                'data' => [
                    'identification_number' => 'page-' . $page->getId(),
                    'language' => $this->getShortLanguageCode($locale),
                ],
            ];
        }

        return $events;
    }
}
