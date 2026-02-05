<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Trait;

use Doctrine\Common\Collections\Collection;

trait TranslationHelperTrait
{
    private function getShortLanguageCode(string $locale): string
    {
        return substr($locale, 0, 2);
    }

    /**
     * Finds a translation matching the given locale, falling back to
     * short-code matching (e.g. "en" matches "en_US").
     *
     * @return object|null The translation object, or null if none found
     */
    private function findTranslationForLocale(Collection $translations, string $locale): ?object
    {
        $shortLocale = substr($locale, 0, 2);

        foreach ($translations as $translation) {
            if (!method_exists($translation, 'getLocale')) {
                continue;
            }

            $translationLocale = $translation->getLocale();

            if (
                $translationLocale === $locale ||
                $translationLocale === $shortLocale ||
                str_starts_with($translationLocale, $shortLocale . '_')
            ) {
                return $translation;
            }
        }

        return null;
    }
}
