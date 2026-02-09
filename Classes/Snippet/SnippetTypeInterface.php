<?php
declare(strict_types=1);

namespace MyVendor\SiteRichSnippets\Snippet;

interface SnippetTypeInterface
{
    /**
     * Technischer Name, z.B. "faq" oder "courseList".
     */
    public function getIdentifier(): string;

    /**
     * Lesbarer Name für Backend / Debug.
     */
    public function getLabel(): string;

    /**
     * Prüfen, ob der Typ auf dieser Seite überhaupt Sinn macht.
     * (z.B. nur wenn FAQ-Daten vorhanden sind).
     */
    public function isEnabledForPage(array $pageRow, array $analyzedData, array $settings = []): bool;

    /**
     * JSON-LD Array für diesen Typ bauen (ohne @context/@graph).
     * Leeres Array = kein Snippet.
     */
    public function build(array $pageRow, array $analyzedData, array $settings = []): array;
}
