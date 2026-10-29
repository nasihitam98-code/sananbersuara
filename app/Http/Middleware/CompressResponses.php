<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gzip untuk respons JSON besar (pembaruan Livewire, mis. Meja Izin tiap 3 detik). Server web di VPS hanya memampatkan
 * HTML, sehingga satu pembaruan Meja Izin terkirim ±580 KB apa adanya (±340 MB/jam lewat hotspot). Dimampatkan di sini
 * menjadi beberapa puluh KB. Respons yang sudah dimampatkan, file, dan stream dilewati.
 */
class CompressResponses
{
    private const MIN_BYTES = 2048;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldCompress($request, $response)) {
            return $response;
        }

        $compressed = gzencode((string) $response->getContent(), 6);

        if ($compressed === false) {
            return $response;
        }

        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', (string) strlen($compressed));
        $response->setVary(array_values(array_unique([...$response->getVary(), 'Accept-Encoding'])));

        return $response;
    }

    private function shouldCompress(Request $request, Response $response): bool
    {
        return ! $response instanceof BinaryFileResponse
            && ! $response instanceof StreamedResponse
            && $response->headers->get('Content-Encoding') === null
            && str_contains((string) $request->header('Accept-Encoding'), 'gzip')
            && str_contains((string) $response->headers->get('Content-Type'), 'json')
            && strlen((string) $response->getContent()) >= self::MIN_BYTES;
    }
}
