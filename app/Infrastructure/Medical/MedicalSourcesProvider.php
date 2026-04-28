<?php

namespace App\Infrastructure\Medical;

use Illuminate\Support\Facades\Http;

class MedicalSourcesProvider
{
    public function getSources(string $description, bool|string $verifyOption, ?string $domain = null): array
    {
        $queries = $this->buildSearchQueries($description, $domain);
        $sources = array_merge(
            $this->collectOpenFdaSources($description, $verifyOption),
            $this->collectClinicalTrialsSources($queries['en_query'], $verifyOption),
            $this->collectWhoGhoSources($domain, $verifyOption),
            $this->collectMedicalSources($queries['en_query'], $verifyOption),
            $this->collectClinicalTablesSources($queries['en_query'], $verifyOption)
        );

        $normalized = $this->normalizeSources($sources, $description, $domain, $queries['en_query']);
        return $normalized;
    }

    protected function collectMedicalSources(string $queryText, bool|string $verifyOption): array
    {
        $query = urlencode(mb_substr($queryText, 0, 120));
        $sources = [];

        try {
            $medline = Http::withOptions(['verify' => $verifyOption])
                ->timeout((int) config('medical_sources.source_fetch.timeout_seconds', 20))
                ->get("https://wsearch.nlm.nih.gov/ws/query?db=healthTopics&term={$query}&retmax=4");

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
                                'source_domain' => 'nlm.nih.gov',
                                'language' => 'en',
                            ];
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // Ignore source retrieval errors.
        }

        return array_values(array_slice($sources, 0, (int) config('medical_sources.source_fetch.medline_limit', 4)));
    }

    protected function collectClinicalTablesSources(string $queryText, bool|string $verifyOption): array
    {
        $query = urlencode(mb_substr($queryText, 0, 80));
        $url = "https://clinicaltables.nlm.nih.gov/api/conditions/v3/search?terms={$query}&maxList=5";
        $sources = [];
        $seen = [];

        try {
            $response = Http::withOptions(['verify' => $verifyOption])
                ->timeout((int) config('medical_sources.source_fetch.timeout_seconds', 20))
                ->get($url);

            if (! $response->ok()) {
                return [];
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                return [];
            }

            $names = $payload[3] ?? [];
            if (! is_array($names) || $names === []) {
                return [];
            }

            foreach ($names as $name) {
                $title = trim((string) $name);
                if ($title === '') {
                    continue;
                }

                $titleSlug = urlencode($title);
                $url = "https://medlineplus.gov/search/?query={$titleSlug}";
                if (isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;

                $sources[] = [
                    'title' => "MedlinePlus: {$title}",
                    'url' => $url,
                    'snippet' => 'Материал из открытого API NLM ClinicalTables и MedlinePlus.',
                    'source_domain' => 'nlm.nih.gov',
                    'language' => 'en',
                ];
            }
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_slice($sources, 0, (int) config('medical_sources.source_fetch.clinicaltables_limit', 5)));
    }

    protected function collectOpenFdaSources(string $description, bool|string $verifyOption): array
    {
        if (! (bool) config('medical_sources.openfda.enabled', false)) {
            return [];
        }

        $baseUrl = rtrim((string) config('medical_sources.openfda.base_url', 'https://api.fda.gov'), '/');
        $terms = $this->buildOpenFdaDrugTerms($description);
        if ($terms === []) {
            return [];
        }

        $sources = [];
        foreach ($terms as $term) {
            $encodedTerm = urlencode('"'.$term.'"');
            $labelLimit = (int) config('medical_sources.openfda.label_limit', 1);
            $labelUrl = "{$baseUrl}/drug/label.json?search=openfda.generic_name:{$encodedTerm}&limit={$labelLimit}";

            try {
                $labelResponse = Http::withOptions(['verify' => $verifyOption])
                    ->timeout((int) config('medical_sources.source_fetch.timeout_seconds', 20))
                    ->get($labelUrl);

                if ($labelResponse->ok()) {
                    $payload = $labelResponse->json();
                    $entry = is_array($payload['results'][0] ?? null) ? $payload['results'][0] : [];
                    $usage = is_array($entry['indications_and_usage'] ?? null) ? (string) ($entry['indications_and_usage'][0] ?? '') : '';
                    $warnings = is_array($entry['warnings'] ?? null) ? (string) ($entry['warnings'][0] ?? '') : '';
                    $snippet = $this->summarizeOpenFdaLabel($usage, $warnings);
                    if ($snippet !== '') {
                        $sources[] = [
                            'title' => "openFDA label: {$term}",
                            'url' => 'https://open.fda.gov/apis/drug/label/',
                            'snippet' => $snippet,
                            'source_domain' => 'api.fda.gov',
                            'language' => 'en',
                        ];
                    }
                }
            } catch (\Throwable) {
                // Ignore openFDA label failures.
            }

            $eventLimit = (int) config('medical_sources.openfda.event_reactions_limit', 5);
            $eventUrl = "{$baseUrl}/drug/event.json?search=patient.drug.medicinalproduct:{$encodedTerm}&count=patient.reaction.reactionmeddrapt.exact";

            try {
                $eventResponse = Http::withOptions(['verify' => $verifyOption])
                    ->timeout((int) config('medical_sources.source_fetch.timeout_seconds', 20))
                    ->get($eventUrl);

                if ($eventResponse->ok()) {
                    $payload = $eventResponse->json();
                    $results = is_array($payload['results'] ?? null) ? $payload['results'] : [];
                    $topReactions = [];
                    foreach (array_slice($results, 0, $eventLimit) as $reaction) {
                        $name = trim((string) ($reaction['term'] ?? ''));
                        if ($name !== '') {
                            $topReactions[] = $this->translateReactionTerm($name);
                        }
                    }
                    if ($topReactions !== []) {
                        $sources[] = [
                            'title' => "openFDA safety: {$term}",
                            'url' => 'https://open.fda.gov/apis/drug/event/',
                            'snippet' => 'Часто встречающиеся реакции в отчетах: '.implode(', ', $topReactions).'.',
                            'source_domain' => 'api.fda.gov',
                            'language' => 'en',
                        ];
                    }
                }
            } catch (\Throwable) {
                // Ignore openFDA event failures.
            }
        }

        return array_values(array_slice($sources, 0, (int) config('medical_sources.source_fetch.max_output_sources', 6)));
    }

    protected function collectClinicalTrialsSources(string $queryText, bool|string $verifyOption): array
    {
        if (! (bool) config('medical_sources.clinicaltrials.enabled', false)) {
            return [];
        }

        $baseUrl = rtrim((string) config('medical_sources.clinicaltrials.base_url', 'https://clinicaltrials.gov/api/v2'), '/');
        $limit = max(1, (int) config('medical_sources.clinicaltrials.studies_limit', 3));
        $query = trim(mb_substr($queryText, 0, 120));
        if ($query === '') {
            return [];
        }

        $url = "{$baseUrl}/studies?query.term=".urlencode($query)."&pageSize={$limit}";

        try {
            $response = Http::withOptions(['verify' => $verifyOption])
                ->timeout((int) config('medical_sources.source_fetch.timeout_seconds', 20))
                ->get($url);

            if (! $response->ok()) {
                return [];
            }

            $payload = $response->json();
            $studies = is_array($payload['studies'] ?? null) ? $payload['studies'] : [];
            if ($studies === []) {
                return [];
            }

            $sources = [];
            foreach (array_slice($studies, 0, $limit) as $study) {
                $protocol = is_array($study['protocolSection'] ?? null) ? $study['protocolSection'] : [];
                $idModule = is_array($protocol['identificationModule'] ?? null) ? $protocol['identificationModule'] : [];
                $statusModule = is_array($protocol['statusModule'] ?? null) ? $protocol['statusModule'] : [];
                $conditionsModule = is_array($protocol['conditionsModule'] ?? null) ? $protocol['conditionsModule'] : [];

                $nctId = trim((string) ($idModule['nctId'] ?? ''));
                $title = trim((string) ($idModule['briefTitle'] ?? ''));
                if ($nctId === '' || $title === '') {
                    continue;
                }

                $conditions = is_array($conditionsModule['conditions'] ?? null) ? $conditionsModule['conditions'] : [];
                $conditionsText = $conditions !== [] ? implode(', ', array_slice(array_map('strval', $conditions), 0, 3)) : 'Без уточненных условий';
                $overallStatus = trim((string) ($statusModule['overallStatus'] ?? ''));
                $statusText = $overallStatus !== '' ? "Статус: {$overallStatus}. " : '';

                $sources[] = [
                    'title' => "ClinicalTrials: {$title}",
                    'url' => "https://clinicaltrials.gov/study/{$nctId}",
                    'snippet' => mb_substr($statusText."Условия исследования: {$conditionsText}.", 0, 240),
                    'source_domain' => 'clinicaltrials.gov',
                    'language' => 'en',
                ];
            }

            return $sources;
        } catch (\Throwable) {
            return [];
        }
    }

    protected function collectWhoGhoSources(?string $domain, bool|string $verifyOption): array
    {
        if (! (bool) config('medical_sources.who_gho.enabled', false)) {
            return [];
        }

        $baseUrl = rtrim((string) config('medical_sources.who_gho.base_url', 'https://ghoapi.azureedge.net/api'), '/');
        $indicators = config('medical_sources.who_gho.indicators', []);
        if (! is_array($indicators) || $indicators === []) {
            return [];
        }

        $domainKey = (string) ($domain ?? 'neutral');
        $indicatorCode = trim((string) ($indicators[$domainKey] ?? ($indicators['neutral'] ?? '')));
        if ($indicatorCode === '') {
            return [];
        }

        $url = "{$baseUrl}/{$indicatorCode}?\$top=1";
        try {
            $response = Http::withOptions(['verify' => $verifyOption])
                ->timeout((int) config('medical_sources.source_fetch.timeout_seconds', 20))
                ->get($url);

            if (! $response->ok()) {
                return [];
            }

            $payload = $response->json();
            $rows = is_array($payload['value'] ?? null) ? $payload['value'] : [];
            $row = is_array($rows[0] ?? null) ? $rows[0] : [];

            $numericValue = $this->extractFirstNumericValue($row);
            $country = trim((string) ($row['SpatialDim'] ?? 'World'));
            $year = trim((string) ($row['TimeDim'] ?? ''));
            $metricText = $numericValue !== null ? (string) $numericValue : 'н/д';
            $yearText = $year !== '' ? " за {$year} год" : '';

            return [[
                'title' => "WHO GHO indicator: {$indicatorCode}",
                'url' => "https://www.who.int/data/gho",
                'snippet' => mb_substr("Показатель {$indicatorCode} ({$country}){$yearText}: {$metricText}.", 0, 240),
                'source_domain' => 'who.int',
                'language' => 'en',
            ]];
        } catch (\Throwable) {
            return [];
        }
    }

    protected function extractFirstNumericValue(array $row): ?float
    {
        foreach ($row as $value) {
            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                return (float) $value;
            }
        }

        return null;
    }

    protected function normalizeSources(array $sources, string $description, ?string $domain, string $enQuery): array
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

            if (! $this->isTrustedDomain($url)) {
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
                'source_domain' => (string) ($source['source_domain'] ?? ((string) parse_url($url, PHP_URL_HOST))),
                'language' => (string) ($source['language'] ?? 'en'),
                '_score' => $this->scoreSourceRelevance($title.' '.$snippet, $description, $domain, $enQuery),
            ];
        }

        usort($result, static fn (array $a, array $b): int => ($b['_score'] <=> $a['_score']));

        return array_map(static function (array $item): array {
            unset($item['_score']);

            return $item;
        }, array_values(array_slice($result, 0, (int) config('medical_sources.source_fetch.max_output_sources', 6))));
    }

    protected function isTrustedDomain(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return false;
        }

        $trustedDomains = config('medical_sources.trusted_source_domains', []);
        if (! is_array($trustedDomains) || $trustedDomains === []) {
            return true;
        }

        foreach ($trustedDomains as $trustedDomain) {
            $trustedDomain = mb_strtolower(trim((string) $trustedDomain));
            if ($trustedDomain === '') {
                continue;
            }

            $hostLc = mb_strtolower($host);
            if ($hostLc === $trustedDomain || str_ends_with($hostLc, '.'.$trustedDomain)) {
                return true;
            }
        }

        return false;
    }

    protected function scoreSourceRelevance(string $sourceText, string $description, ?string $domain, string $enQuery): int
    {
        $score = 0;
        $haystack = mb_strtolower($sourceText);
        $descriptionLc = mb_strtolower($description);
        $englishQueryLc = mb_strtolower($enQuery);

        if ($domain !== null && $domain !== '') {
            $keywords = $this->getDomainKeywords($domain);
            foreach ($keywords as $keyword) {
                if ($keyword !== '' && str_contains($haystack, $keyword)) {
                    $score += 2;
                }
            }
        }

        foreach (preg_split('/\s+/u', $descriptionLc) ?: [] as $token) {
            $token = trim($token);
            if (mb_strlen($token) < 4) {
                continue;
            }
            if (str_contains($haystack, $token)) {
                $score++;
            }
        }

        foreach (preg_split('/\s+/u', $englishQueryLc) ?: [] as $token) {
            $token = trim($token);
            if (mb_strlen($token) < 3) {
                continue;
            }
            if (str_contains($haystack, $token)) {
                $score += 2;
            }
        }

        return $score;
    }

    protected function getDomainKeywords(string $domain): array
    {
        $domains = config('medical_triage.symptom_domains', []);
        $domainConfig = is_array($domains[$domain] ?? null) ? $domains[$domain] : [];

        $keywords = $domainConfig['positive_keywords'] ?? [];
        if (! is_array($keywords)) {
            return [];
        }

        return array_map(
            static fn (mixed $keyword): string => mb_strtolower((string) $keyword),
            $keywords
        );
    }

    protected function buildSearchQueries(string $description, ?string $domain): array
    {
        $text = mb_strtolower(trim($description));
        $dictionary = config('medical_sources.translation.ru_to_en', []);
        $terms = [];

        if (is_array($dictionary)) {
            foreach ($dictionary as $ruStem => $enTerm) {
                $ru = mb_strtolower(trim((string) $ruStem));
                $en = trim((string) $enTerm);
                if ($ru !== '' && $en !== '' && str_contains($text, $ru)) {
                    $terms[] = $en;
                }
            }
        }

        $domainTerms = config('medical_sources.translation.domain_terms', []);
        $domainKey = (string) ($domain ?? '');
        if ($domainKey !== '' && is_array($domainTerms[$domainKey] ?? null)) {
            foreach ($domainTerms[$domainKey] as $term) {
                $term = trim((string) $term);
                if ($term !== '') {
                    $terms[] = $term;
                }
            }
        }

        $terms = array_values(array_unique($terms));
        $query = $terms === [] ? $this->sanitizeSearchQuery($description) : implode(' ', array_slice($terms, 0, 8));

        return [
            'ru_original' => $description,
            'en_query' => mb_substr($query, 0, 120),
        ];
    }

    protected function sanitizeSearchQuery(string $text): string
    {
        $normalized = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $text) ?? '';
        $normalized = trim(preg_replace('/\s+/u', ' ', $normalized) ?? '');

        return $normalized === '' ? 'symptoms' : $normalized;
    }

    protected function buildOpenFdaDrugTerms(string $description): array
    {
        $text = mb_strtolower($description);
        $mapping = config('medical_sources.openfda.drug_terms_map', []);
        if (! is_array($mapping) || $mapping === []) {
            return [];
        }

        $terms = [];
        foreach ($mapping as $needle => $drugName) {
            $needle = mb_strtolower(trim((string) $needle));
            $drugName = trim((string) $drugName);
            if ($needle === '' || $drugName === '') {
                continue;
            }
            if (str_contains($text, $needle)) {
                $terms[] = mb_strtolower($drugName);
            }
        }

        return array_values(array_slice(array_unique($terms), 0, (int) config('medical_sources.openfda.drug_term_limit', 2)));
    }

    protected function summarizeOpenFdaLabel(string $usage, string $warnings): string
    {
        $parts = [];
        $usageLc = mb_strtolower($usage);
        $warningsLc = mb_strtolower($warnings);

        if ($usageLc !== '') {
            if (str_contains($usageLc, 'pain') || str_contains($usageLc, 'fever')) {
                $parts[] = 'Используется для уменьшения боли и/или температуры.';
            } elseif (str_contains($usageLc, 'allergy') || str_contains($usageLc, 'rash')) {
                $parts[] = 'Применение связано с симптомами аллергии/кожными проявлениями.';
            }
        }

        if ($warningsLc !== '') {
            if (str_contains($warningsLc, 'allergic reaction')) {
                $parts[] = 'Возможна тяжелая аллергическая реакция; при сыпи и отеке прекратите прием.';
            }
            if (str_contains($warningsLc, 'stomach bleeding')) {
                $parts[] = 'Есть риск желудочно-кишечного кровотечения, особенно при длительном приеме.';
            }
            if (str_contains($warningsLc, 'heart attack') || str_contains($warningsLc, 'stroke')) {
                $parts[] = 'НПВС могут повышать сердечно-сосудистые риски при превышении дозы/сроков.';
            }
            if (str_contains($warningsLc, 'pregnant')) {
                $parts[] = 'При беременности нужен отдельный контроль врача.';
            }
        }

        if ($parts === []) {
            $raw = $warnings !== '' ? $warnings : $usage;
            $raw = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
            return mb_substr($raw, 0, 220);
        }

        return implode(' ', array_slice(array_values(array_unique($parts)), 0, 3));
    }

    protected function translateReactionTerm(string $term): string
    {
        $map = [
            'DRUG INEFFECTIVE' => 'Недостаточный эффект препарата',
            'PAIN' => 'Боль',
            'FATIGUE' => 'Утомляемость',
            'NAUSEA' => 'Тошнота',
            'HEADACHE' => 'Головная боль',
            'DYSPNOEA' => 'Одышка',
            'VOMITING' => 'Рвота',
            'DIARRHOEA' => 'Диарея',
            'RASH' => 'Сыпь',
            'DIZZINESS' => 'Головокружение',
            'PRURITUS' => 'Кожный зуд',
            'HYPERSENSITIVITY' => 'Гиперчувствительность',
            'DRUG HYPERSENSITIVITY' => 'Лекарственная гиперчувствительность',
            'CHEST PAIN' => 'Боль в груди',
            'COUGH' => 'Кашель',
            'URTICARIA' => 'Крапивница',
            'ANGIOEDEMA' => 'Ангиоотек',
            'ABDOMINAL PAIN' => 'Боль в животе',
            'ABDOMINAL PAIN UPPER' => 'Боль в верхней части живота',
        ];

        $upper = strtoupper(trim($term));
        return $map[$upper] ?? mb_convert_case(mb_strtolower($term), MB_CASE_TITLE, 'UTF-8');
    }

}

