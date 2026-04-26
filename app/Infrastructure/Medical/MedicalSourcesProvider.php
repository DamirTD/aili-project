<?php

namespace App\Infrastructure\Medical;

use Illuminate\Support\Facades\Http;

class MedicalSourcesProvider
{
    public function getSources(string $description, bool|string $verifyOption): array
    {
        $sources = $this->collectMedicalSources($description, $verifyOption);
        $normalized = $this->normalizeSources($sources);

        if ($normalized === []) {
            $normalized = $this->buildFallbackSources($description);
        }

        return $normalized;
    }

    protected function collectMedicalSources(string $description, bool|string $verifyOption): array
    {
        $query = urlencode(mb_substr($description, 0, 120));
        $sources = [];

        try {
            $medline = Http::withOptions(['verify' => $verifyOption])
                ->timeout(20)
                ->get("https://wsearch.nlm.nih.gov/ws/query?db=healthTopics&term={$query}&retmax=3");

            if ($medline->ok()) {
                $xml = @simplexml_load_string($medline->body());
                if ($xml && isset($xml->list->document)) {
                    foreach ($xml->list->document as $document) {
                        $title = '';
                        $url = '';
                        $snippet = '';
                        foreach ($document->content as $content) {
                            $name = (string) $content['name'];
                            $value = trim((string) $content);
                            if ($name === 'title') {
                                $title = $value;
                            } elseif ($name === 'url') {
                                $url = $value;
                            } elseif ($name === 'FullSummary') {
                                $snippet = mb_substr(strip_tags($value), 0, 240);
                            }
                        }
                        if ($title && $url) {
                            $sources[] = [
                                'title' => $title,
                                'url' => $url,
                                'snippet' => $snippet,
                            ];
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // Ignore source retrieval errors.
        }

        try {
            $wiki = Http::withOptions(['verify' => $verifyOption])
                ->timeout(20)
                ->get("https://ru.wikipedia.org/w/api.php?action=opensearch&search={$query}&limit=3&namespace=0&format=json");

            if ($wiki->ok()) {
                $data = $wiki->json();
                $titles = $data[1] ?? [];
                $snippets = $data[2] ?? [];
                $urls = $data[3] ?? [];
                foreach ($titles as $i => $title) {
                    $url = $urls[$i] ?? null;
                    if (! $url) {
                        continue;
                    }
                    $sources[] = [
                        'title' => (string) $title,
                        'url' => (string) $url,
                        'snippet' => (string) ($snippets[$i] ?? ''),
                    ];
                }
            }
        } catch (\Throwable) {
            // Ignore source retrieval errors.
        }

        return array_values(array_slice($sources, 0, 6));
    }

    protected function normalizeSources(array $sources): array
    {
        $result = [];
        $seen = [];

        foreach ($sources as $source) {
            $title = trim((string) ($source['title'] ?? ''));
            $url = trim((string) ($source['url'] ?? ''));
            $snippet = trim((string) ($source['snippet'] ?? ''));

            if ($title === '' || $url === '' || ! str_starts_with($url, 'http')) {
                continue;
            }

            if (isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $result[] = [
                'title' => $title,
                'url' => $url,
                'snippet' => mb_substr($snippet, 0, 240),
            ];
        }

        return array_values(array_slice($result, 0, 6));
    }

    protected function buildFallbackSources(string $description): array
    {
        $query = urlencode(mb_substr(trim($description), 0, 120));
        $templates = config('medical_context.fallback_sources', []);

        if (! is_array($templates) || $templates === []) {
            return [];
        }

        $result = [];
        foreach ($templates as $item) {
            $urlTemplate = (string) ($item['url'] ?? '');
            $url = str_replace('{query}', $query, $urlTemplate);
            if ($url === '') {
                continue;
            }

            $result[] = [
                'title' => (string) ($item['title'] ?? 'Источник'),
                'url' => $url,
                'snippet' => (string) ($item['snippet'] ?? ''),
            ];
        }

        return array_values(array_slice($result, 0, 6));
    }
}

