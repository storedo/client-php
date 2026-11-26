<?php
declare(strict_types=1);

namespace Storedo;

/**
 * STOREDO-Client für PHP 8.1+: Bewertung, Befunde und Aufgaben je Domain in eigene Software holen (Agenturverwaltung, Kundenportal, Wartungsvertrag).
 * Ohne Abhängigkeiten (curl). Nur lesend: der Client löst nie Scans aus und legt nie Projekte an.
 *
 *   $c = new \Storedo\Client(['apiKey' => getenv('STOREDO_KEY')]);   // Integrations-Schlüssel (Bereiche read:projects und read:scans)
 *   $s = $c->summary('beispiel.de');                              // ['ampel' => 'yellow', 'areas' => [...], 'tasks' => [...]]
 *
 * Fehler kommen als StoredoException mit einer Art (Config, Auth, Limit, Network, NotFound, Response) und einer Meldung für Menschen.
 */
final class Client
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private int $retries;
    /** @var callable|null function(string $method, string $url, array $headers, int $timeout): array{status:int, body:string, errno:int} */
    private $transport;
    /** @var object|null PSR-16-ähnlich: get(string): mixed, set(string, mixed, int): bool */
    private ?object $cache;

    /**
     * @param array{apiKey:string, baseUrl?:string, timeout?:int, retries?:int, cache?:object, transport?:callable} $o
     */
    public function __construct(array $o)
    {
        $this->apiKey = (string)($o['apiKey'] ?? '');
        $this->baseUrl = rtrim((string)($o['baseUrl'] ?? 'https://api.storedo.de'), '/');
        $this->timeout = (int)($o['timeout'] ?? 10);
        $this->retries = (int)($o['retries'] ?? 2);
        $this->cache = $o['cache'] ?? null;
        $this->transport = $o['transport'] ?? null;
        if ($this->apiKey === '') {
            throw new StoredoException(ErrorKind::Config, 'STOREDO-Schlüssel fehlt');
        }
    }

    /** Alle Domains des Kontos mit Note, Ampel und letztem Scan (kuratierter Endpunkt). */
    public function domains(): array
    {
        return $this->cached('domains', 900, fn() => $this->get('/api/v1/integration/domains')['data']['domains'] ?? []);
    }

    /**
     * Zusammenfassung einer Domain: neuester abgeschlossener Scan je Bereich mit Ampel, Aufgaben aus den kritischen und hohen Befunden.
     * Die Ampelregel liegt auf dem Server (rot bei kritischem Befund oder unter 70 Punkten, gelb bei hohen Befunden oder unter 85, sonst grün).
     * @throws StoredoException NotFound, wenn es kein Projekt zur Domain gibt (oder der Schlüssel es nicht sehen darf)
     */
    public function summary(string $domain): array
    {
        $d = preg_replace('/^www\./', '', strtolower(trim($domain)));
        return $this->cached('summary:' . $d, 3600, fn() => $this->get('/api/v1/integration/domains/' . rawurlencode($d) . '/summary')['data'] ?? []);
    }

    private function cached(string $key, int $ttl, callable $fetch): array
    {
        if ($this->cache !== null) {
            $hit = $this->cache->get('storedo:' . $key);
            if (is_array($hit)) {
                return $hit;
            }
        }
        $v = $fetch();
        if ($this->cache !== null) {
            $this->cache->set('storedo:' . $key, $v, $ttl);
        }
        return $v;
    }

    private function get(string $path): array
    {
        $url = $this->baseUrl . $path;
        $headers = ['X-API-Key: ' . $this->apiKey, 'Accept: application/json'];
        $attempt = 0;
        while (true) {
            $attempt++;
            $r = $this->transport !== null ? ($this->transport)('GET', $url, $headers, $this->timeout) : self::curl($url, $headers, $this->timeout);
            if ($r['errno'] !== 0) {
                if ($attempt <= $this->retries) {
                    usleep(150000 * $attempt);
                    continue;
                }
                throw new StoredoException(ErrorKind::Network, $r['errno'] === 28 ? 'Zeitüberschreitung bei STOREDO' : 'STOREDO nicht erreichbar');
            }
            $s = $r['status'];
            if (in_array($s, [502, 503, 504], true) && $attempt <= $this->retries) {
                usleep(150000 * $attempt);
                continue;
            }
            $j = json_decode($r['body'], true);
            $code = is_array($j) ? (int)($j['code'] ?? $s) : $s;
            if ($s === 404 || $code === 404) {
                throw new StoredoException(ErrorKind::NotFound, 'Für diese Domain gibt es in STOREDO kein Projekt');
            }
            if ($s === 401 || $s === 403 || $code === 401 || $code === 403) {
                throw new StoredoException(ErrorKind::Auth, 'STOREDO lehnt den Schlüssel ab (abgelaufen, widerrufen oder ohne Berechtigung)');
            }
            if ($s === 402 || $s === 429 || $code === 402 || $code === 429) {
                throw new StoredoException(ErrorKind::Limit, 'STOREDO-Limit erreicht');
            }
            if ($s >= 400 || !is_array($j) || ($j['success'] ?? true) === false) {
                throw new StoredoException(ErrorKind::Response, 'STOREDO lieferte keine gültige Antwort');
            }
            return $j;
        }
    }

    private static function curl(string $url, array $headers, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false]);
        $body = curl_exec($ch);
        $res = ['status' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => is_string($body) ? $body : '', 'errno' => curl_errno($ch)];
        curl_close($ch);
        return $res;
    }
}

enum ErrorKind: string
{
    case Config = 'config';
    case Auth = 'auth';
    case Limit = 'limit';
    case Network = 'network';
    case NotFound = 'not_found';
    case Response = 'response';
}

final class StoredoException extends \RuntimeException
{
    public function __construct(public readonly ErrorKind $kind, string $message)
    {
        parent::__construct($message);
    }
}
