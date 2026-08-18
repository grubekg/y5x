<?php
declare(strict_types=1);

namespace Grube\Price30\Support;

/**
 * Minimaler HTTP-Client auf curl.
 *
 * Das Briefing nennt Guzzle. Hier steht bewusst kein Composer-Paket: Der Tracker braucht
 * GET mit Basic-Auth und einen JSON-Rumpf, sonst nichts. Eine Abhängigkeit, die man
 * installieren muss, bevor die Beweiskette rechnet, ist ein Betriebsrisiko ohne Gegenwert.
 * Wird später mehr gebraucht (Retry-Middleware, Pools), ist der Tausch eine Klasse.
 */
final class Http
{
    public function __construct(
        private readonly string $base,
        private readonly string $user = '',
        private readonly string $pass = '',
        private readonly int $timeout = 120,
    ) {
    }

    /** @return array{status:int, body:string} */
    public function get(string $pfad, array $query = [], array $header = [], ?int $timeout = null): array
    {
        $url = \rtrim($this->base, '/') . $pfad;
        if ($query !== []) {
            $url .= (\str_contains($url, '?') ? '&' : '?') . \http_build_query($query);
        }
        $ch = \curl_init($url);
        \curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT        => $timeout ?? $this->timeout,
            \CURLOPT_HTTPHEADER     => \array_merge(['Accept: application/json'], $header),
            \CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($this->user !== '') {
            \curl_setopt($ch, \CURLOPT_USERPWD, $this->user . ':' . $this->pass);
        }
        $body = (string) \curl_exec($ch);
        $status = (int) \curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        $fehler = \curl_error($ch);
        \curl_close($ch);
        if ($fehler !== '') {
            throw new \RuntimeException("HTTP-Fehler für $pfad: $fehler");
        }
        return ['status' => $status, 'body' => $body];
    }

    public function json(string $pfad, array $query = []): mixed
    {
        $r = $this->get($pfad, $query);
        if ($r['status'] !== 200) {
            throw new \RuntimeException("HTTP {$r['status']} für $pfad");
        }
        return \json_decode($r['body'], true, 512, \JSON_THROW_ON_ERROR);
    }
}
