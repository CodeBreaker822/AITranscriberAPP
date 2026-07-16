<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DesktopLoadingControllerTest extends TestCase
{
    public function test_desktop_loading_page_renders_without_vite_assets(): void
    {
        $this->get('/desktop-loading')
            ->assertOk()
            ->assertSee('Starting AITranscriber')
            ->assertSee('/desktop-assets-ready', false)
            ->assertDontSee('@vite/client', false);
    }

    public function test_desktop_app_pages_keep_loader_until_javascript_initializes(): void
    {
        config(['app.desktop_dev' => true]);

        $this->get('/')
            ->assertOk()
            ->assertSee('data-desktop-startup-overlay', false)
            ->assertSee('data-desktop-startup-status', false);
    }

    public function test_desktop_assets_ready_waits_for_vite_in_desktop_dev(): void
    {
        config(['app.desktop_dev' => true]);

        Http::fake([
            'http://127.0.0.1:5173/@vite/client' => Http::response('', 200),
        ]);

        $this->getJson('/desktop-assets-ready')
            ->assertOk()
            ->assertJson(['ready' => true]);
    }

    public function test_desktop_assets_ready_keeps_loader_visible_until_vite_responds(): void
    {
        config(['app.desktop_dev' => true]);

        Http::fake(function (): never {
            throw new ConnectionException('Vite is not ready.');
        });

        $this->getJson('/desktop-assets-ready')
            ->assertStatus(503)
            ->assertJson(['ready' => false]);
    }
}
