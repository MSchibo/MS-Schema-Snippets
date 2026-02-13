<?php
declare(strict_types=1);

namespace MyVendor\SiteRichSnippets\Service;

    final class JsonLdRenderer
    {
        public function render(array $jsonLd): string
    {
        if ($jsonLd === []) {
            return '';
        }

        $jsonLd = $this->sanitizeJsonLd($jsonLd);

        $json = json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            return '';
        }

        return '<script type="application/ld+json">' . $json . '</script>';
    }

    private function sanitizeJsonLd(array $data): array
    {
        // @graph-Fall: alle Items sanitizen
        if (!empty($data['@graph']) && is_array($data['@graph'])) {
            foreach ($data['@graph'] as $i => $item) {
                if (is_array($item)) {
                    $data['@graph'][$i] = $this->sanitizeSingleItem($item);
                }
            }
            return $data;
        }

        // Single-Item-Fall
        return $this->sanitizeSingleItem($data);
    }

    private function sanitizeSingleItem(array $data): array
    {
        // FAQ: mainEntity[*].acceptedAnswer.text
        if (($data['@type'] ?? '') === 'FAQPage' && !empty($data['mainEntity']) && is_array($data['mainEntity'])) {
            foreach ($data['mainEntity'] as $i => $entity) {
                if (is_array($entity) && !empty($entity['acceptedAnswer']) && is_array($entity['acceptedAnswer'])) {
                    if (isset($entity['acceptedAnswer']['text']) && is_string($entity['acceptedAnswer']['text'])) {
                        $data['mainEntity'][$i]['acceptedAnswer']['text'] = $this->cleanText($entity['acceptedAnswer']['text']);
                    }
                }
            }
        }

        // HowTo: step[*].text
        if (($data['@type'] ?? '') === 'HowTo' && !empty($data['step']) && is_array($data['step'])) {
            foreach ($data['step'] as $i => $step) {
                if (is_array($step) && isset($step['text']) && is_string($step['text'])) {
                    $data['step'][$i]['text'] = $this->cleanText($step['text']);
                }
            }
        }

        foreach (['description', 'text'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = $this->cleanText($data[$field]);
            }
        }

        return $data;
    }
}