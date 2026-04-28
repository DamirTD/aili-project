<?php

namespace Tests\Feature;

use App\Infrastructure\Medical\MedicalSourcesProvider;
use App\Infrastructure\Medical\OwidInsightsProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MedicalRelevanceTest extends TestCase
{
    public function test_respiratory_domain_does_not_pull_cardiology_owid_metrics(): void
    {
        Http::fake([
            'ourworldindata.org/*' => Http::response("Entity,Year,Value\nWorld,2024,12.3\n", 200),
        ]);

        $provider = new OwidInsightsProvider();
        $insights = $provider->getInsights('Температура, кашель, боль в горле и насморк', true, 'respiratory');

        $titles = array_map(static fn (array $item): string => (string) ($item['title'] ?? ''), $insights);

        $this->assertNotContains('Сердечно-сосудистые риски', $titles);
    }

    public function test_neutral_domain_returns_no_owid_insights_when_no_matches(): void
    {
        Http::fake([
            'ourworldindata.org/*' => Http::response("Entity,Year,Value\nWorld,2024,12.3\n", 200),
        ]);

        $provider = new OwidInsightsProvider();
        $insights = $provider->getInsights('Небольшой дискомфорт без четких симптомов', true, 'neutral');

        $this->assertSame([], $insights);
    }

    public function test_medical_sources_provider_filters_untrusted_domains(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<searchResults>
  <list>
    <document>
      <content name="title">Trusted source</content>
      <content name="url">https://medlineplus.gov/flu.html</content>
      <content name="FullSummary">Trusted summary</content>
    </document>
    <document>
      <content name="title">Untrusted source</content>
      <content name="url">https://example.com/random</content>
      <content name="FullSummary">Untrusted summary</content>
    </document>
  </list>
</searchResults>
XML;

        Http::fake([
            'wsearch.nlm.nih.gov/*' => Http::response($xml, 200),
            'clinicaltables.nlm.nih.gov/*' => Http::response('[0,[],null,[]]', 200),
            'clinicaltrials.gov/api/v2/studies*' => Http::response('{"studies":[]}', 200),
            'ghoapi.azureedge.net/api/*' => Http::response('{"value":[]}', 200),
        ]);

        $provider = new MedicalSourcesProvider();
        $sources = $provider->getSources('кашель и насморк', true, 'respiratory');
        $urls = array_map(static fn (array $item): string => (string) ($item['url'] ?? ''), $sources);

        $this->assertTrue(
            collect($urls)->contains(
                static fn (string $url): bool => str_contains($url, 'https://medlineplus.gov/flu.html')
            )
        );
        $this->assertFalse(
            collect($urls)->contains(
                static fn (string $url): bool => str_contains($url, 'https://example.com/random')
            )
        );
    }

    public function test_medical_sources_provider_collects_clinical_tables_results_for_russian_query(): void
    {
        $emptyMedline = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<searchResults>
  <list></list>
</searchResults>
XML;

        $clinicalTablesJson = '[2,["C123","C124"],null,["Chest infection","Acute cough"]]';

        Http::fake([
            'wsearch.nlm.nih.gov/*' => Http::response($emptyMedline, 200),
            'clinicaltables.nlm.nih.gov/*' => Http::response($clinicalTablesJson, 200),
            'clinicaltrials.gov/api/v2/studies*' => Http::response('{"studies":[]}', 200),
            'ghoapi.azureedge.net/api/*' => Http::response('{"value":[]}', 200),
        ]);

        $provider = new MedicalSourcesProvider();
        $sources = $provider->getSources('Кашель и температура 2 дня', true, 'respiratory');

        $urls = array_map(static fn (array $item): string => (string) ($item['url'] ?? ''), $sources);
        $this->assertTrue(
            collect($urls)->contains(
                static fn (string $url): bool => str_contains($url, 'https://medlineplus.gov/search/?query=Chest+infection')
            )
        );
    }

    public function test_medical_sources_provider_returns_empty_sources_when_external_services_fail(): void
    {
        Http::fake([
            '*' => Http::response('', 500),
        ]);

        $provider = new MedicalSourcesProvider();
        $sources = $provider->getSources('Кашель и боль в горле', true, 'respiratory');

        $this->assertSame([], $sources);
    }

    public function test_build_search_queries_translates_russian_terms_to_english(): void
    {
        $provider = new MedicalSourcesProvider();
        $method = new \ReflectionMethod(MedicalSourcesProvider::class, 'buildSearchQueries');
        $method->setAccessible(true);
        $queries = $method->invoke($provider, 'Кашель и насморк', 'respiratory');

        $this->assertIsArray($queries);
        $enQuery = (string) ($queries['en_query'] ?? '');
        $this->assertTrue(str_contains($enQuery, 'cough') || str_contains($enQuery, 'runny nose'));
    }

    public function test_medical_sources_provider_uses_openfda_for_known_drug_terms(): void
    {
        $labelJson = '{"results":[{"indications_and_usage":["Pain and fever"],"warnings":["Stomach bleeding warning"]}]}';
        $eventJson = '{"results":[{"term":"NAUSEA"},{"term":"RASH"},{"term":"DIZZINESS"}]}';
        $emptyMedline = '<?xml version="1.0" encoding="UTF-8"?><searchResults><list></list></searchResults>';

        Http::fake([
            'api.fda.gov/drug/label.json*' => Http::response($labelJson, 200),
            'api.fda.gov/drug/event.json*' => Http::response($eventJson, 200),
            'wsearch.nlm.nih.gov/*' => Http::response($emptyMedline, 200),
            'clinicaltables.nlm.nih.gov/*' => Http::response('[0,[],null,[]]', 200),
            'clinicaltrials.gov/api/v2/studies*' => Http::response('{"studies":[]}', 200),
            'ghoapi.azureedge.net/api/*' => Http::response('{"value":[]}', 200),
        ]);

        $provider = new MedicalSourcesProvider();
        $sources = $provider->getSources('После ибупрофена появилась сыпь и тошнота', true, 'gastro');

        $this->assertTrue(
            collect($sources)->contains(
                static fn (array $source): bool => str_contains((string) ($source['url'] ?? ''), 'open.fda.gov/apis/drug/label/')
            )
        );
        $this->assertTrue(
            collect($sources)->contains(
                static fn (array $source): bool => str_contains((string) ($source['url'] ?? ''), 'open.fda.gov/apis/drug/event/')
            )
        );
    }

    public function test_medical_sources_provider_collects_clinical_trials_sources(): void
    {
        $emptyMedline = '<?xml version="1.0" encoding="UTF-8"?><searchResults><list></list></searchResults>';
        $clinicalTrials = <<<'JSON'
{
  "studies": [
    {
      "protocolSection": {
        "identificationModule": {
          "nctId": "NCT01234567",
          "briefTitle": "Respiratory Symptoms Monitoring Study"
        },
        "statusModule": {
          "overallStatus": "RECRUITING"
        },
        "conditionsModule": {
          "conditions": ["Cough", "Fever"]
        }
      }
    }
  ]
}
JSON;

        Http::fake([
            'wsearch.nlm.nih.gov/*' => Http::response($emptyMedline, 200),
            'clinicaltables.nlm.nih.gov/*' => Http::response('[0,[],null,[]]', 200),
            'clinicaltrials.gov/api/v2/studies*' => Http::response($clinicalTrials, 200),
            'ghoapi.azureedge.net/api/*' => Http::response('{"value":[]}', 200),
        ]);

        $provider = new MedicalSourcesProvider();
        $sources = $provider->getSources('Кашель и температура', true, 'respiratory');

        $this->assertTrue(
            collect($sources)->contains(
                static fn (array $source): bool => str_contains((string) ($source['url'] ?? ''), 'clinicaltrials.gov/study/NCT01234567')
            )
        );
    }

    public function test_medical_sources_provider_collects_who_gho_source(): void
    {
        $emptyMedline = '<?xml version="1.0" encoding="UTF-8"?><searchResults><list></list></searchResults>';
        $whoJson = '{"value":[{"SpatialDim":"World","TimeDim":2022,"NumericValue":17.5}]}';

        Http::fake([
            'wsearch.nlm.nih.gov/*' => Http::response($emptyMedline, 200),
            'clinicaltables.nlm.nih.gov/*' => Http::response('[0,[],null,[]]', 200),
            'clinicaltrials.gov/api/v2/studies*' => Http::response('{"studies":[]}', 200),
            'ghoapi.azureedge.net/api/*' => Http::response($whoJson, 200),
        ]);

        $provider = new MedicalSourcesProvider();
        $sources = $provider->getSources('Боль в груди и одышка', true, 'cardiology');

        $this->assertTrue(
            collect($sources)->contains(
                static fn (array $source): bool => str_contains((string) ($source['title'] ?? ''), 'WHO GHO indicator:')
            )
        );
    }
}
