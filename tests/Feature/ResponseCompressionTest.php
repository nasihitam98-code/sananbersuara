<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ResponseCompressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Seperti pembaruan Livewire: JSON besar lewat grup middleware web.
        Route::middleware('web')->get('/_uji-json-besar', fn () => response()->json(['html' => str_repeat('<li>Nama warga</li>', 500)]));
        Route::middleware('web')->get('/_uji-json-kecil', fn () => response()->json(['ok' => true]));
    }

    public function test_large_json_is_gzipped_when_the_browser_accepts_it(): void
    {
        $response = $this->get('/_uji-json-besar', ['Accept-Encoding' => 'gzip, deflate, br'])
            ->assertOk()
            ->assertHeader('Content-Encoding', 'gzip');

        $this->assertStringContainsString('Accept-Encoding', (string) $response->headers->get('Vary'));
        $this->assertSame(['html' => str_repeat('<li>Nama warga</li>', 500)], json_decode(gzdecode($response->getContent()), true));
        $this->assertLessThan(1000, strlen($response->getContent()));
    }

    public function test_small_or_unaccepted_responses_are_left_as_they_are(): void
    {
        $this->get('/_uji-json-besar')->assertOk()->assertHeaderMissing('Content-Encoding')->assertJsonStructure(['html']);
        $this->get('/_uji-json-kecil', ['Accept-Encoding' => 'gzip'])->assertOk()->assertHeaderMissing('Content-Encoding')->assertExactJson(['ok' => true]);
    }
}
