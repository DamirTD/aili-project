<?php

namespace Tests\Feature;

use App\Application\Diagnosis\Queries\AnalyzeDiagnosisHandler;
use App\Application\Diagnosis\Queries\AnalyzeDiagnosisQuery;
use App\Application\Diagnosis\Support\DiagnosisResponseMapper;
use App\Infrastructure\AI\GroqClient;
use App\Infrastructure\Medical\MedicalSourcesProvider;
use App\Infrastructure\Medical\OwidInsightsProvider;
use Illuminate\Http\Client\Response;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AnalyzeDiagnosisHandlerFallbackTest extends TestCase
{
    public function test_it_uses_deepseek_when_groq_text_fails(): void
    {
        config([
            'services.groq_ai.api_key' => 'groq-key',
            'services.groq_ai.model' => 'groq-model',
            'services.groq_ai.base_url' => 'https://api.groq.com/openai/v1',
            'services.groq_ai.verify_ssl' => true,
            'services.deepseek.api_key' => 'deepseek-key',
            'services.deepseek.model' => 'deepseek-chat',
            'services.deepseek.base_url' => 'https://api.deepseek.com/v1',
            'services.deepseek.verify_ssl' => true,
        ]);

        $groqFailedResponse = Mockery::mock(Response::class);
        $groqFailedResponse->shouldReceive('failed')->once()->andReturn(true);
        $groqFailedResponse->shouldReceive('body')->zeroOrMoreTimes()->andReturn('groq failed');

        $deepSeekSuccessResponse = Mockery::mock(Response::class);
        $deepSeekSuccessResponse->shouldReceive('failed')->once()->andReturn(false);
        $deepSeekSuccessResponse->shouldReceive('json')->once()->andReturn([
            'choices' => [
                ['message' => ['content' => '{"diagnosis":"Fallback diagnosis","confidence":"средняя"}']],
            ],
        ]);

        $groqClient = Mockery::mock(GroqClient::class);
        $groqClient->shouldReceive('text')->once()->andReturn($groqFailedResponse);
        $groqClient->shouldReceive('deepSeekText')->once()->andReturn($deepSeekSuccessResponse);

        $sourcesProvider = Mockery::mock(MedicalSourcesProvider::class);
        $sourcesProvider->shouldReceive('getSources')->once()->andReturn([]);

        $owidProvider = Mockery::mock(OwidInsightsProvider::class);
        $owidProvider->shouldReceive('getInsights')->once()->andReturn([]);

        $handler = new AnalyzeDiagnosisHandler(
            groqClient: $groqClient,
            sourcesProvider: $sourcesProvider,
            owidInsightsProvider: $owidProvider,
            responseMapper: new DiagnosisResponseMapper()
        );

        $result = $handler(new AnalyzeDiagnosisQuery(
            description: 'Температура и кашель',
            image: null,
            age: 31,
            gender: 'male'
        ));

        $this->assertSame('Fallback diagnosis', $result['diagnosis']);
    }

    public function test_it_throws_when_groq_and_deepseek_fail(): void
    {
        config([
            'services.groq_ai.api_key' => 'groq-key',
            'services.groq_ai.model' => 'groq-model',
            'services.groq_ai.base_url' => 'https://api.groq.com/openai/v1',
            'services.groq_ai.verify_ssl' => true,
            'services.deepseek.api_key' => 'deepseek-key',
            'services.deepseek.model' => 'deepseek-chat',
            'services.deepseek.base_url' => 'https://api.deepseek.com/v1',
            'services.deepseek.verify_ssl' => true,
        ]);

        $groqFailedResponse = Mockery::mock(Response::class);
        $groqFailedResponse->shouldReceive('failed')->once()->andReturn(true);

        $deepSeekFailedResponse = Mockery::mock(Response::class);
        $deepSeekFailedResponse->shouldReceive('failed')->once()->andReturn(true);
        $deepSeekFailedResponse->shouldReceive('body')->once()->andReturn('deepseek failed');

        $groqClient = Mockery::mock(GroqClient::class);
        $groqClient->shouldReceive('text')->once()->andReturn($groqFailedResponse);
        $groqClient->shouldReceive('deepSeekText')->once()->andReturn($deepSeekFailedResponse);

        $sourcesProvider = Mockery::mock(MedicalSourcesProvider::class);
        $sourcesProvider->shouldReceive('getSources')->once()->andReturn([]);

        $owidProvider = Mockery::mock(OwidInsightsProvider::class);
        $owidProvider->shouldReceive('getInsights')->once()->andReturn([]);

        $handler = new AnalyzeDiagnosisHandler(
            groqClient: $groqClient,
            sourcesProvider: $sourcesProvider,
            owidInsightsProvider: $owidProvider,
            responseMapper: new DiagnosisResponseMapper()
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ошибка Groq/DeepSeek:');

        $handler(new AnalyzeDiagnosisQuery(
            description: 'Температура и кашель',
            image: null,
            age: null,
            gender: null
        ));
    }
}

