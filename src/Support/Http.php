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
    /** Zeitpunkt der letzten Anfrage — Grundlage der Drosselung. */
    private float $zuletzt = 0.0;

    public function __construct(
        private readonly string $base,
        private readonly string $user = '',
        private readonly string $pass = '',
        private readonly int $timeout = 120,
        /**
         * Anfragen je Minute. 0 = keine Drosselung.
         *
         * **Der Shop begrenzt auf 800 Anfragen in 2 Minuten je IP UND User-Agent**
         * (`/admin/rate-limiting/status`, gemessen 18.08.2026; `UserAgentMode` ist aktiv).
         * Das sind 400/min. Ein ungedrosselter Lauf schafft rund 600/min und läge
         * darüber.
         *
         * Das wiegt hier schwerer als sonst: Die ausgehende IP `176.9.21.74` gehört dem
         * ganzen Webspace. Eine Sperre träfe nicht nur dieses Werkzeug, sondern jedes
         * andere Projekt, das denselben Shop anspricht.
         */
        private readonly int $proMinute = 0,
        /**
         * Eigener User-Agent. Ohne ihn liefe unser Verkehr unter „curl" — im Protokoll
         * des Shops nicht von irgendetwas anderem zu unterscheiden. Wer eine fremde
         * Schnittstelle in Anspruch nimmt, sollte erkennbar sein.
         */
        private readonly string $userAgent = 'y5x-Preisschreiber (+grube.tools)',
    ) {
    }

    /** Vor jeder Anfrage: warten, bis der Takt es erlaubt. */
    private function takt(): void
    {
        if ($this->proMinute <= 0) {
            return;
        }
        $mindestabstand = 60.0 / $this->proMinute;
        $vergangen = \microtime(true) - $this->zuletzt;
        if ($this->zuletzt > 0.0 && $vergangen < $mindestabstand) {
            \usleep((int) \round(($mindestabstand - $vergangen) * 1_000_000));
        }
        $this->zuletzt = \microtime(true);
    }

    /** @return array{status:int, body:string} */
    public function get(string $pfad, array $query = [], array $header = [], ?int $timeout = null): array
    {
        $url = \rtrim($this->base, '/') . $pfad;
        if ($query !== []) {
            $url .= (\str_contains($url, '?') ? '&' : '?') . \http_build_query($query);
        }
        $this->takt();
        $ch = \curl_init($url);
        \curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT        => $timeout ?? $this->timeout,
            \CURLOPT_HTTPHEADER     => \array_merge(['Accept: application/json'], $header),
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_USERAGENT      => $this->userAgent,
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

    /**
     * Schreibender Aufruf mit JSON-Rumpf.
     *
     * Bewusst getrennt von {@see get}: Ein Werkzeug, dessen Kern lesend ist, soll den
     * schreibenden Weg an der Aufrufstelle erkennbar machen.
     *
     * @return array{status:int, body:string}
     */
    public function sende(string $methode, string $pfad, array $rumpf, int $timeout = 120): array
    {
        $url = \rtrim($this->base, '/') . $pfad;
        $this->takt();
        $ch = \curl_init($url);
        \curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_USERAGENT      => $this->userAgent,
            \CURLOPT_CUSTOMREQUEST  => \strtoupper($methode),
            \CURLOPT_POSTFIELDS     => \json_encode($rumpf, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
            \CURLOPT_TIMEOUT        => $timeout,
            \CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
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
            throw new \RuntimeException("HTTP-Fehler ($methode $url): $fehler");
        }
        return ['status' => $status, 'body' => $body];
    }

    /**
     * Antwort direkt in eine Datei schreiben.
     *
     * Der Preisabzug ist 191 MB gross — im Arbeitsspeicher gehalten sprengt er jede
     * vernuenftige Grenze (gemessen: `preg_match_all` starb bei 512 MB). Also auf Platte
     * und dann streamend zerlegen.
     */
    public function herunterladen(string $pfad, array $query, string $ziel, int $timeout = 600): int
    {
        $this->takt();
        $url = \rtrim($this->base, '/') . $pfad;
        if ($query !== []) {
            $url .= (\str_contains($url, '?') ? '&' : '?') . \http_build_query($query);
        }
        $fp = \fopen($ziel, 'w');
        if ($fp === false) {
            throw new \RuntimeException("Datei nicht schreibbar: $ziel");
        }
        $ch = \curl_init($url);
        \curl_setopt_array($ch, [
            \CURLOPT_FILE      => $fp,
            \CURLOPT_TIMEOUT   => $timeout,
            \CURLOPT_USERAGENT => $this->userAgent,
            \CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($this->user !== '') {
            \curl_setopt($ch, \CURLOPT_USERPWD, $this->user . ':' . $this->pass);
        }
        \curl_exec($ch);
        $status = (int) \curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        $fehler = \curl_error($ch);
        \curl_close($ch);
        \fclose($fp);
        if ($fehler !== '') {
            throw new \RuntimeException("HTTP-Fehler beim Herunterladen von $pfad: $fehler");
        }
        return $status;
    }

    public function json(string $pfad, array $query = []): mixed
    {
        $r = $this->get($pfad, $query);
        if ($r['status'] === 429) {
            throw new \RuntimeException("Ratenbegrenzung des Shops erreicht (HTTP 429) für $pfad "
                . '— `requests_per_minute` in app.yml senken');
        }
        if ($r['status'] !== 200) {
            throw new \RuntimeException("HTTP {$r['status']} für $pfad");
        }
        return \json_decode($r['body'], true, 512, \JSON_THROW_ON_ERROR);
    }
}
