<?php

namespace App\Infrastructure\Medical;

use Illuminate\Support\Facades\Http;

class OwidInsightsProvider
{
    public function getInsights(string $description, bool|string $verifyOption, ?string $domain = null): array
    {
        $emergency = $this->resolveEmergencyOwidInsights($description);
        if ($emergency !== []) {
            return $emergency;
        }

        $catalog = config('medical_triage.owid_catalog', []);
        if (! is_array($catalog) || $catalog === []) {
            return [];
        }

        $descriptionLc = mb_strtolower($description);
        $selected = [];

        foreach ($catalog as $metric) {
            if (! $this->isMetricInDomain($metric, $domain)) {
                continue;
            }

            $keywords = is_array($metric['keywords'] ?? null) ? $metric['keywords'] : [];
            $score = 0;
            foreach ($keywords as $keyword) {
                if (str_contains($descriptionLc, mb_strtolower((string) $keyword))) {
                    $score++;
                }
            }

            if ($score > 0) {
                $metric['_score'] = $score;
                $selected[] = $metric;
            }
        }

        if ($selected === []) {
            return [];
        }

        usort($selected, static fn (array $a, array $b): int => (($b['_score'] ?? 0) <=> ($a['_score'] ?? 0)));
        $selected = array_values(array_slice($selected, 0, 3));
        $result = [];

        foreach ($selected as $metric) {
            $result[] = $this->fetchOwidMetric(
                slug: (string) ($metric['slug'] ?? ''),
                title: (string) ($metric['title'] ?? 'OWID'),
                unit: (string) ($metric['unit'] ?? ''),
                verifyOption: $verifyOption,
                advice: isset($metric['advice']) ? (string) $metric['advice'] : null,
                why: isset($metric['why']) ? (string) $metric['why'] : null,
                today: isset($metric['today']) ? (string) $metric['today'] : null
            );
        }

        return array_values(array_slice($result, 0, 3));
    }

    protected function fetchOwidMetric(
        string $slug,
        string $title,
        string $unit,
        bool|string $verifyOption,
        ?string $advice = null,
        ?string $why = null,
        ?string $today = null
    ): array {
        $url = "https://ourworldindata.org/grapher/{$slug}.csv?csvType=full";
        $fallback = [
            'title' => $title,
            'value' => null,
            'value_text' => 'н/д',
            'unit' => $unit,
            'year' => null,
            'url' => "https://ourworldindata.org/grapher/{$slug}",
            'source' => 'OWID',
            'advice' => $advice,
            'why' => $why,
            'today' => $today,
        ];

        if ($slug === '') {
            return $fallback;
        }

        try {
            $response = Http::withOptions(['verify' => $verifyOption])
                ->timeout(20)
                ->get($url);

            if (! $response->ok()) {
                return $fallback;
            }

            $lines = preg_split('/\r\n|\r|\n/', trim($response->body()));
            if (! is_array($lines) || count($lines) < 2) {
                return $fallback;
            }

            $headers = str_getcsv((string) $lines[0]);
            $entityIndex = array_search('Entity', $headers, true);
            $yearIndex = array_search('Year', $headers, true);
            $dayIndex = array_search('Day', $headers, true);

            $valueIndex = null;
            foreach ($headers as $index => $header) {
                if (! in_array($header, ['Entity', 'Code', 'Year', 'Day'], true)) {
                    $valueIndex = $index;
                    break;
                }
            }

            if ($entityIndex === false || $valueIndex === null) {
                return $fallback;
            }

            $bestRow = null;
            foreach (array_slice($lines, 1) as $line) {
                $row = str_getcsv((string) $line);
                if (($row[$entityIndex] ?? '') !== 'World') {
                    continue;
                }

                $timePoint = $yearIndex !== false
                    ? (int) ($row[$yearIndex] ?? 0)
                    : (string) ($dayIndex !== false ? ($row[$dayIndex] ?? '') : '');

                if ($bestRow === null || $timePoint > $bestRow['time']) {
                    $bestRow = [
                        'time' => $timePoint,
                        'value' => $row[$valueIndex] ?? null,
                    ];
                }
            }

            if ($bestRow === null) {
                return $fallback;
            }

            $numeric = is_numeric($bestRow['value']) ? (float) $bestRow['value'] : null;
            $valueText = $numeric !== null
                ? rtrim(rtrim(number_format($numeric, 2, '.', ''), '0'), '.').' '.$unit
                : 'н/д';

            return [
                'title' => $title,
                'value' => $numeric,
                'value_text' => trim($valueText),
                'unit' => $unit,
                'year' => (string) $bestRow['time'],
                'url' => "https://ourworldindata.org/grapher/{$slug}",
                'source' => 'OWID',
                'advice' => $advice,
                'why' => $why,
                'today' => $today,
            ];
        } catch (\Throwable) {
            return $fallback;
        }
    }

    protected function resolveEmergencyOwidInsights(string $description): array
    {
        $text = mb_strtolower($description);
        $profiles = config('medical_triage.owid_emergency_profiles', []);

        if (! is_array($profiles) || $profiles === []) {
            return [];
        }

        foreach ($profiles as $profile) {
            $keywords = is_array($profile['keywords'] ?? null) ? $profile['keywords'] : [];
            $minMatches = (int) ($profile['min_matches'] ?? 2);
            $cards = is_array($profile['cards'] ?? null) ? $profile['cards'] : [];

            if ($keywords === [] || $cards === []) {
                continue;
            }

            $matches = 0;
            foreach ($keywords as $keyword) {
                if (str_contains($text, mb_strtolower((string) $keyword))) {
                    $matches++;
                }
            }

            if ($matches < $minMatches) {
                continue;
            }

            $result = [];
            foreach ($cards as $card) {
                $result[] = [
                    'title' => (string) ($card['title'] ?? 'Экстренный совет'),
                    'advice' => (string) ($card['advice'] ?? 'Немедленно обратитесь за медицинской помощью.'),
                    'why' => (string) ($card['why'] ?? 'Наблюдаются потенциально опасные симптомы.'),
                    'today' => (string) ($card['today'] ?? 'Свяжитесь с экстренной службой и следуйте рекомендациям врача.'),
                    'url' => (string) ($card['url'] ?? 'https://ourworldindata.org/explorers/global-health'),
                    'source' => 'OWID',
                ];
            }

            return array_values(array_slice($result, 0, 3));
        }

        return [];
    }

    protected function isMetricInDomain(array $metric, ?string $domain): bool
    {
        if ($domain === null || $domain === '' || $domain === 'neutral') {
            return true;
        }

        $domains = is_array($metric['domains'] ?? null) ? $metric['domains'] : [];
        if ($domains === []) {
            return false;
        }

        foreach ($domains as $metricDomain) {
            if (mb_strtolower((string) $metricDomain) === mb_strtolower($domain)) {
                return true;
            }
        }

        return false;
    }

    protected function resolveNeutralInsights(): array
    {
        $cards = config('medical_triage.owid_neutral_cards', []);
        if (! is_array($cards) || $cards === []) {
            return [];
        }

        $result = [];
        foreach (array_slice($cards, 0, 3) as $card) {
            $result[] = [
                'title' => (string) ($card['title'] ?? 'Профилактика'),
                'advice' => (string) ($card['advice'] ?? 'Наблюдайте за симптомами и обратитесь к врачу при ухудшении.'),
                'why' => (string) ($card['why'] ?? 'Недостаточно признаков для специфического риск-профиля.'),
                'today' => (string) ($card['today'] ?? 'Фиксируйте симптомы и контролируйте базовые показатели.'),
                'url' => (string) ($card['url'] ?? 'https://ourworldindata.org/explorers/global-health'),
                'source' => 'OWID',
            ];
        }

        return $result;
    }
}

