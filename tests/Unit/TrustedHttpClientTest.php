<?php

namespace Tests\Unit;

use App\Services\Http\TrustedHttpClient;
use Tests\TestCase;

class TrustedHttpClientTest extends TestCase
{
    public function test_it_uses_the_bundled_ca_certificate_with_an_absolute_path(): void
    {
        $client = app(TrustedHttpClient::class);
        $path = $client->caBundlePath();

        $this->assertTrue(is_file($path));
        $this->assertSame(realpath(base_path('php/extras/ssl/cacert.pem')), realpath($path));
        $this->assertSame($path, $client->options()['verify']);
    }
}
