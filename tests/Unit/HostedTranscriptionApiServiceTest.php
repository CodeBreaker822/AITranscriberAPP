<?php

namespace Tests\Unit;

use App\Services\Config\AppSettingsService;
use App\Exceptions\SpeechToTextException;
use App\Exceptions\TranscriptPolisherException;
use App\Services\HostedApi\HostedTranscriptionApiService;
use App\Services\Transcripts\TranscriptPolisherService;
use App\Services\Speech\SpeechToTextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\ConnectionException;
use Tests\TestCase;

class HostedTranscriptionApiServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_checks_license_status_with_bearer_license_key(): void
    {
        config(['services.transcription_api.base_url' => 'https://dilgaims.site/api']);

        Http::fake([
            'https://dilgaims.site/api/license/status' => Http::response([
                'valid' => true,
                'active' => true,
                'expired' => false,
            ]),
        ]);

        $status = app(HostedTranscriptionApiService::class)->licenseStatus('license-123');

        $this->assertTrue($status['valid']);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://dilgaims.site/api/license/status'
                && $request->hasHeader('Authorization', 'Bearer license-123');
        });
    }

    public function test_connectivity_probe_does_not_send_the_license_key(): void
    {
        config(['services.transcription_api.base_url' => 'https://dilgaims.site/api']);
        app(AppSettingsService::class)->setLicenseKey('license-123');

        Http::fake([
            'https://dilgaims.site/api' => Http::response([], 404),
        ]);

        $this->assertTrue(app(HostedTranscriptionApiService::class)->serverIsReachable());

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://dilgaims.site/api'
                && ! $request->hasHeader('Authorization');
        });
    }

    public function test_it_requests_the_update_zip_with_bearer_license_key(): void
    {
        config(['services.transcription_api.base_url' => 'https://dilgaims.site/api']);
        app(AppSettingsService::class)->setLicenseKey('license-123');

        Http::fake([
            'https://dilgaims.site/api/transcribe/update/zipfile' => Http::response('zip bytes'),
        ]);

        $response = app(HostedTranscriptionApiService::class)->downloadUpdateArchive();

        $this->assertSame('zip bytes', $response->body());

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://dilgaims.site/api/transcribe/update/zipfile'
                && $request->hasHeader('Authorization', 'Bearer license-123');
        });
    }

    public function test_it_transcribes_audio_through_hosted_api(): void
    {
        config(['services.transcription_api.base_url' => 'https://dilgaims.site/api']);

        $settings = app(AppSettingsService::class);
        $settings->setLicenseKey('license-123');
        $settings->setLicenseStatus($this->licenseStatusPayload());
        $settings->setSpeechToTextProvider('deepgram');
        $settings->setSpeechToTextModel('nova-3');

        $audioPath = tempnam(sys_get_temp_dir(), 'aitranscriber-hosted-');
        file_put_contents($audioPath, 'fake audio bytes');

        Http::fake([
            'https://dilgaims.site/api/transcribe*' => Http::response([
                'text' => 'Hello from the server.',
                'timestamps' => [
                    ['text' => 'Hello', 'start' => 0, 'end' => 0.25],
                ],
                'provider' => 'deepgram',
                'model' => 'nova-3',
            ]),
        ]);

        try {
            $result = app(SpeechToTextService::class)->transcribe($audioPath, [
                'language_code' => 'en',
                'clip_index' => 7,
                'clip_start_ms' => 360000,
                'clip_end_ms' => 420000,
            ]);
        } finally {
            @unlink($audioPath);
        }

        $this->assertSame('Hello from the server.', $result['text']);
        $this->assertSame('Hello', $result['timestamps'][0]['text']);

        Http::assertSent(function (Request $request): bool {
            $parts = collect($request->data());

            return $request->method() === 'POST'
                && $request->url() === 'https://dilgaims.site/api/transcribe'
                && $request->hasHeader('Authorization', 'Bearer license-123')
                && $parts->contains(fn (array $part): bool => ($part['name'] ?? null) === 'audio')
                && $parts->contains(fn (array $part): bool => ($part['name'] ?? null) === 'provider' && ($part['contents'] ?? null) === 'deepgram')
                && $parts->contains(fn (array $part): bool => ($part['name'] ?? null) === 'model' && ($part['contents'] ?? null) === 'nova-3')
                && $parts->contains(fn (array $part): bool => ($part['name'] ?? null) === 'language_code' && ($part['contents'] ?? null) === 'en')
                && $parts->contains(fn (array $part): bool => ($part['name'] ?? null) === 'clip_index' && (int) ($part['contents'] ?? 0) === 7);
        });
    }

    public function test_it_transcribes_audio_batches_through_hosted_api(): void
    {
        config(['services.transcription_api.base_url' => 'https://dilgaims.site/api']);

        $settings = app(AppSettingsService::class);
        $settings->setLicenseKey('license-123');
        $settings->setLicenseStatus($this->licenseStatusPayload([
            'apis' => [
                'transcribe' => [
                    'allowed' => true,
                    'supports_batch' => true,
                    'max_batch_clips' => 20,
                    'max_batch_duration_ms' => 1200000,
                ],
            ],
        ]));
        $settings->setSpeechToTextProvider('deepgram');
        $settings->setSpeechToTextModel('nova-3');

        $firstPath = tempnam(sys_get_temp_dir(), 'aitranscriber-hosted-batch-a-');
        $secondPath = tempnam(sys_get_temp_dir(), 'aitranscriber-hosted-batch-b-');
        file_put_contents($firstPath, 'first fake audio bytes');
        file_put_contents($secondPath, 'second fake audio bytes');

        Http::fake([
            'https://dilgaims.site/api/transcribe*' => Http::response([
                'clips' => [
                    [
                        'queue_index' => 0,
                        'clip_index' => 1,
                        'clip_start_ms' => 0,
                        'clip_end_ms' => 300000,
                        'text' => 'First clip.',
                        'timestamps' => [['text' => 'First', 'start' => 0, 'end' => 0.5]],
                        'provider' => 'deepgram',
                        'model' => 'nova-3',
                    ],
                    [
                        'queue_index' => 1,
                        'clip_index' => 2,
                        'clip_start_ms' => 300000,
                        'clip_end_ms' => 600000,
                        'text' => 'Second clip.',
                        'timestamps' => [['text' => 'Second', 'start' => 300, 'end' => 300.5]],
                        'provider' => 'deepgram',
                        'model' => 'nova-3',
                    ],
                ],
            ]),
        ]);

        try {
            $result = app(SpeechToTextService::class)->transcribeBatch([
                ['audio' => $firstPath, 'clip_index' => 1, 'clip_start_ms' => 0, 'clip_end_ms' => 300000],
                ['audio' => $secondPath, 'clip_index' => 2, 'clip_start_ms' => 300000, 'clip_end_ms' => 600000],
            ], [
                'language_code' => 'en',
            ]);
        } finally {
            @unlink($firstPath);
            @unlink($secondPath);
        }

        $this->assertSame('First clip.', $result[0]['text']);
        $this->assertSame('Second clip.', $result[1]['text']);
        $this->assertSame(2, $result[1]['clip_index']);

        Http::assertSent(function (Request $request): bool {
            $parts = collect($request->data());

            return $request->method() === 'POST'
                && $request->url() === 'https://dilgaims.site/api/transcribe'
                && $request->hasHeader('Authorization', 'Bearer license-123')
                && $parts->where('name', 'audio[]')->count() === 2
                && $parts->contains(fn (array $part): bool => ($part['name'] ?? null) === 'provider' && ($part['contents'] ?? null) === 'deepgram')
                && $parts->contains(fn (array $part): bool => ($part['name'] ?? null) === 'model' && ($part['contents'] ?? null) === 'nova-3')
                && $parts->contains(fn (array $part): bool => ($part['name'] ?? null) === 'language_code' && ($part['contents'] ?? null) === ['en', 'en'])
                && $parts->contains(fn (array $part): bool => ($part['name'] ?? null) === 'clip_index' && ($part['contents'] ?? null) === [1, 2]);
        });
    }

    public function test_hosted_audio_uploads_use_streams_instead_of_loading_files_into_memory(): void
    {
        $root = dirname(__DIR__, 2);
        $client = file_get_contents($root.'/app/Services/HostedApi/HostedTranscriptionClient.php');
        $resolver = file_get_contents($root.'/app/Services/HostedApi/HostedAudioFileResolver.php');
        $limits = file_get_contents($root.'/app/Services/HostedApi/HostedTranscriptionLimitGuard.php');

        $this->assertStringContainsString("fopen(\$file['path'], 'rb')", $resolver);
        $this->assertStringContainsString('$this->assertUploadBytesAreAllowed([$file]);', $limits);
        $this->assertStringContainsString('$this->assertUploadBytesAreAllowed($files);', $limits);
        $this->assertStringContainsString('finally {', $client);
        $this->assertStringNotContainsString("file_get_contents(\$file['path'])", $client.$resolver);
    }

    public function test_hosted_api_clients_share_the_local_ssl_certificate_transport(): void
    {
        $root = dirname(__DIR__, 2);
        $apiClient = file_get_contents($root.'/app/Services/HostedApi/HostedApiClient.php');
        $licenseClient = file_get_contents($root.'/app/Services/HostedApi/HostedLicenseClient.php');
        $updateClient = file_get_contents($root.'/app/Services/HostedApi/HostedUpdateClient.php');
        $transcriptionClient = file_get_contents($root.'/app/Services/HostedApi/HostedTranscriptionClient.php');
        $jobClient = file_get_contents($root.'/app/Services/HostedApi/HostedTranscriptionJobClient.php');
        $polishingClient = file_get_contents($root.'/app/Services/HostedApi/HostedPolishingClient.php');
        $facade = file_get_contents($root.'/app/Services/HostedApi/HostedTranscriptionApiService.php');

        $this->assertStringContainsString('TrustedHttpClient', $apiClient);
        $this->assertStringContainsString('withOptions($this->http->options())', $apiClient);
        $this->assertStringContainsString('return $this->http->options($options);', $apiClient);
        $this->assertStringContainsString('withOptions($this->api->trustedOptions())', $licenseClient);
        $this->assertStringContainsString("withOptions(\$this->api->trustedOptions(['stream' => true]))", $updateClient);

        foreach ([$transcriptionClient, $jobClient, $polishingClient] as $client) {
            $this->assertStringContainsString('HostedApiClient $api', $client);
            $this->assertStringNotContainsString('Http::', $client);
        }

        $this->assertStringNotContainsString('Http::', $facade);
        $this->assertSame(
            str_replace('\\', '/', base_path('php/extras/ssl/cacert.pem')),
            str_replace('\\', '/', (string) config('services.http.ca_bundle')),
        );
        $this->assertFileExists(config('services.http.ca_bundle'));
    }

    public function test_it_rejects_single_audio_that_exceeds_the_server_reported_byte_limit_before_upload(): void
    {
        config(['services.transcription_api.base_url' => 'https://dilgaims.site/api']);

        $settings = app(AppSettingsService::class);
        $settings->setLicenseKey('license-123');
        $settings->setLicenseStatus($this->licenseStatusPayload([
            'apis' => [
                'transcribe' => [
                    'allowed' => true,
                    'max_file_bytes' => 4,
                ],
            ],
        ]));
        $settings->setSpeechToTextProvider('deepgram');
        $settings->setSpeechToTextModel('nova-3');

        $audioPath = tempnam(sys_get_temp_dir(), 'aitranscriber-hosted-too-large-bytes-');
        file_put_contents($audioPath, '12345');
        Http::fake();

        try {
            app(SpeechToTextService::class)->transcribe($audioPath, ['language_code' => 'en']);
            $this->fail('Expected hosted audio to be rejected before upload.');
        } catch (SpeechToTextException $exception) {
            $this->assertSame('Audio is too big.', $exception->getMessage());
            $this->assertSame(422, $exception->getCode());
        } finally {
            @unlink($audioPath);
        }

        Http::assertNothingSent();
    }

    public function test_it_rejects_audio_batch_that_exceeds_the_total_byte_limit_before_upload(): void
    {
        config(['services.transcription_api.base_url' => 'https://dilgaims.site/api']);

        $settings = app(AppSettingsService::class);
        $settings->setLicenseKey('license-123');
        $settings->setLicenseStatus($this->licenseStatusPayload([
            'apis' => [
                'transcribe' => [
                    'allowed' => true,
                    'supports_batch' => true,
                    'max_batch_clips' => 20,
                    'max_batch_duration_ms' => 1200000,
                    'max_batch_bytes' => 10,
                ],
            ],
        ]));
        $settings->setSpeechToTextProvider('deepgram');
        $settings->setSpeechToTextModel('nova-3');

        $firstPath = tempnam(sys_get_temp_dir(), 'aitranscriber-hosted-batch-bytes-a-');
        $secondPath = tempnam(sys_get_temp_dir(), 'aitranscriber-hosted-batch-bytes-b-');
        file_put_contents($firstPath, '123456');
        file_put_contents($secondPath, 'abcdef');
        Http::fake();

        try {
            app(SpeechToTextService::class)->transcribeBatch([
                ['audio' => $firstPath, 'clip_index' => 1, 'clip_start_ms' => 0, 'clip_end_ms' => 300000],
                ['audio' => $secondPath, 'clip_index' => 2, 'clip_start_ms' => 300000, 'clip_end_ms' => 600000],
            ], [
                'language_code' => 'en',
            ]);
            $this->fail('Expected hosted batch audio to be rejected before upload.');
        } catch (SpeechToTextException $exception) {
            $this->assertSame('Audio is too big.', $exception->getMessage());
            $this->assertSame(422, $exception->getCode());
        } finally {
            @unlink($firstPath);
            @unlink($secondPath);
        }

        Http::assertNothingSent();
    }

    public function test_it_rejects_audio_that_exceeds_the_server_reported_batch_limit(): void
    {
        config(['services.transcription_api.base_url' => 'https://dilgaims.site/api']);

        $settings = app(AppSettingsService::class);
        $settings->setLicenseKey('license-123');
        $settings->setLicenseStatus($this->licenseStatusPayload([
            'apis' => [
                'transcribe' => [
                    'allowed' => true,
                    'max_batch_duration_ms' => 1200000,
                ],
            ],
        ]));
        $settings->setSpeechToTextProvider('deepgram');
        $settings->setSpeechToTextModel('nova-3');

        $audioPath = tempnam(sys_get_temp_dir(), 'aitranscriber-hosted-too-big-');
        file_put_contents($audioPath, 'fake audio bytes');
        Http::fake();

        try {
            app(SpeechToTextService::class)->transcribe($audioPath, [
                'language_code' => 'en',
                'clip_index' => 1,
                'clip_start_ms' => 0,
                'clip_end_ms' => 1200001,
            ]);
            $this->fail('Expected oversized hosted audio to be rejected.');
        } catch (SpeechToTextException $exception) {
            $this->assertSame('Audio is too big.', $exception->getMessage());
            $this->assertSame(422, $exception->getCode());
        } finally {
            @unlink($audioPath);
        }

        Http::assertNothingSent();
    }

    public function test_late_timeline_single_clip_uses_clip_duration_for_server_limit(): void
    {
        config(['services.transcription_api.base_url' => 'https://dilgaims.site/api']);

        $settings = app(AppSettingsService::class);
        $settings->setLicenseKey('license-123');
        $settings->setLicenseStatus($this->licenseStatusPayload([
            'apis' => [
                'transcribe' => [
                    'allowed' => true,
                    'max_batch_duration_ms' => 1200000,
                ],
            ],
        ]));
        $settings->setSpeechToTextProvider('deepgram');
        $settings->setSpeechToTextModel('nova-3');

        $audioPath = tempnam(sys_get_temp_dir(), 'aitranscriber-hosted-late-');
        file_put_contents($audioPath, 'fake audio bytes');

        Http::fake([
            'https://dilgaims.site/api/transcribe*' => Http::response([
                'text' => 'Late timeline transcript.',
                'timestamps' => [],
                'provider' => 'deepgram',
                'model' => 'nova-3',
            ]),
        ]);

        try {
            $result = app(SpeechToTextService::class)->transcribe($audioPath, [
                'language_code' => 'en',
                'clip_index' => 20,
                'clip_start_ms' => 1200000,
                'clip_end_ms' => 1260000,
            ]);
        } finally {
            @unlink($audioPath);
        }

        $this->assertSame('Late timeline transcript.', $result['text']);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://dilgaims.site/api/transcribe';
        });
    }

    public function test_single_clip_with_invalid_timing_is_not_rejected_by_duration_limit(): void
    {
        config(['services.transcription_api.base_url' => 'https://dilgaims.site/api']);

        $settings = app(AppSettingsService::class);
        $settings->setLicenseKey('license-123');
        $settings->setLicenseStatus($this->licenseStatusPayload([
            'apis' => [
                'transcribe' => [
                    'allowed' => true,
                    'max_batch_duration_ms' => 1200000,
                ],
            ],
        ]));
        $settings->setSpeechToTextProvider('deepgram');
        $settings->setSpeechToTextModel('nova-3');

        $audioPath = tempnam(sys_get_temp_dir(), 'aitranscriber-hosted-invalid-time-');
        file_put_contents($audioPath, 'fake audio bytes');

        Http::fake([
            'https://dilgaims.site/api/transcribe*' => Http::response([
                'text' => 'Transcript without timing.',
                'timestamps' => [],
                'provider' => 'deepgram',
                'model' => 'nova-3',
            ]),
        ]);

        try {
            $result = app(SpeechToTextService::class)->transcribe($audioPath, [
                'language_code' => 'en',
                'clip_index' => 1,
                'clip_start_ms' => null,
                'clip_end_ms' => 'not-a-number',
            ]);
        } finally {
            @unlink($audioPath);
        }

        $this->assertSame('Transcript without timing.', $result['text']);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://dilgaims.site/api/transcribe';
        });
    }

    public function test_transcription_connection_details_are_logged_but_not_exposed(): void
    {
        config(['services.transcription_api.base_url' => 'https://dilgaims.site/api']);
        $settings = app(AppSettingsService::class);
        $settings->setLicenseKey('license-123');
        $settings->setLicenseStatus($this->licenseStatusPayload());
        $settings->setSpeechToTextProvider('deepgram');
        $settings->setSpeechToTextModel('nova-3');
        $audioPath = tempnam(sys_get_temp_dir(), 'aitranscriber-hosted-error-');
        file_put_contents($audioPath, 'fake audio bytes');
        $technicalError = 'SSL certificate problem: unable to get local issuer certificate';
        Http::fake(function () use ($technicalError): never {
            throw new ConnectionException($technicalError);
        });
        Log::spy();

        try {
            app(SpeechToTextService::class)->transcribe($audioPath, ['language_code' => 'en']);
            $this->fail('Expected hosted transcription to fail.');
        } catch (SpeechToTextException $exception) {
            $this->assertSame(
                'AITranscriber could not contact transcription server. Please try again shortly.',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString($technicalError, $exception->getMessage());
        } finally {
            @unlink($audioPath);
        }

        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context): bool => $message === 'Hosted transcription connection failed.'
                && $context['ca_bundle_exists'] === true
                && collect($context['exception_chain'])->contains(
                    fn (array $entry): bool => $entry['message'] === $technicalError
                )
        )->once();
    }

    public function test_it_polishes_transcript_chunks_through_hosted_api(): void
    {
        config(['services.transcription_api.base_url' => 'https://dilgaims.site/api']);

        app(AppSettingsService::class)->setLicenseKey('license-123');

        Http::fake([
            'https://dilgaims.site/api/polish' => Http::response([
                'chunks' => [
                    [
                        'audio_chunk_id' => 10,
                        'text' => 'We should begin now.',
                        'timestamps' => [],
                    ],
                ],
                'provider' => 'openai',
                'model' => 'gpt-4.1-mini',
            ]),
        ]);

        $result = app(TranscriptPolisherService::class)->polishChunks(
            [[
                'id' => 10,
                'clip_index' => 1,
                'range_label' => '00:00-01:00',
                'text' => 'uh we should begin now',
                'timestamps' => [],
            ]],
            ['instructions' => 'Fix grammar.'],
        );

        $this->assertSame(10, $result['chunks'][0]['audio_chunk_id']);
        $this->assertSame('We should begin now.', $result['chunks'][0]['text']);
        $this->assertSame('openai', $result['provider']);
        $this->assertSame('gpt-4.1-mini', $result['model']);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://dilgaims.site/api/polish'
                && $request->hasHeader('Authorization', 'Bearer license-123')
                && ($data['instruction'] ?? null) === 'Fix grammar.'
                && (int) ($data['chunks'][0]['audio_chunk_id'] ?? 0) === 10
                && ! array_key_exists('provider', $data)
                && ! array_key_exists('model', $data);
        });
    }

    public function test_empty_polish_uses_server_reported_current_polisher_metadata(): void
    {
        $settings = app(AppSettingsService::class);
        $settings->setLicenseStatus([
            'providers' => [
                'polishing' => [
                    [
                        'provider' => 'anthropic',
                        'configured' => true,
                        'enabled' => true,
                        'connected' => true,
                        'models' => [
                            ['id' => 'claude-sonnet-4'],
                        ],
                    ],
                ],
            ],
        ]);

        $result = app(TranscriptPolisherService::class)->polish('', [], [
            'instructions' => 'Fix grammar.',
        ]);

        $this->assertSame('', $result['text']);
        $this->assertSame('anthropic', $result['provider']);
        $this->assertSame('claude-sonnet-4', $result['model']);
    }

    public function test_successful_empty_polish_response_is_rejected(): void
    {
        config(['services.transcription_api.base_url' => 'https://dilgaims.site/api']);
        app(AppSettingsService::class)->setLicenseKey('license-123');
        Http::fake([
            'https://dilgaims.site/api/polish' => Http::response(['text' => ''], 200),
        ]);

        $this->expectException(TranscriptPolisherException::class);
        $this->expectExceptionMessage('successful response without polished text');

        app(TranscriptPolisherService::class)->polish('Text to polish.');
    }

    public function test_polish_failure_preserves_the_server_message_and_status(): void
    {
        config(['services.transcription_api.base_url' => 'https://dilgaims.site/api']);
        app(AppSettingsService::class)->setLicenseKey('license-123');
        Http::fake([
            'https://dilgaims.site/api/polish' => Http::response([
                'message' => 'Provider quota exhausted for this license.',
            ], 429),
        ]);

        try {
            app(TranscriptPolisherService::class)->polish('Text to polish.');
            $this->fail('Expected polishing to fail.');
        } catch (TranscriptPolisherException $exception) {
            $this->assertSame('Provider quota exhausted for this license.', $exception->getMessage());
            $this->assertSame(429, $exception->getCode());
        }
    }

    private function licenseStatusPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'providers' => [
                'transcription' => [
                    [
                        'provider' => 'deepgram',
                        'name' => 'Deepgram',
                        'configured' => true,
                        'enabled' => true,
                        'connected' => true,
                        'models' => [
                            [
                                'id' => 'nova-3',
                                'label' => 'Nova-3',
                                'default_language_code' => 'multi',
                                'languages' => [
                                    ['code' => 'multi', 'label' => 'Multilingual'],
                                    ['code' => 'en', 'label' => 'English'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], $overrides);
    }
}
