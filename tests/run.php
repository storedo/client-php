<?php
declare(strict_types=1);

/** Test des PHP-Clients ohne Netz. Aufruf: php tests/run.php   Soll: alle OK, Exit 0. */
require __DIR__ . '/../src/Client.php';

use Storedo\{Client, ErrorKind, StoredoException};

$fails = 0;
function ok(string $n, bool $c): void { global $fails; echo ($c ? 'OK    ' : 'FEHLT ') . $n . "\n"; if (!$c) { $fails++; } }
$resp = fn(array $j, int $s = 200) => ['status' => $s, 'body' => json_encode($j), 'errno' => 0];

$log = [];
$sum = ['project' => ['id' => 15, 'name' => 'Scheib', 'domain' => 'scheib.de', 'verified' => false], 'ampel' => 'red', 'areas' => ['security' => ['label' => 'Sicherheit', 'ampel' => 'red', 'scan' => ['id' => 146, 'score' => 92]]],
    'tasks' => [['severity' => 'critical', 'title' => 'TLS veraltet']]];
$q = [$resp(['success' => true, 'data' => $sum])];
$c = new Client(['apiKey' => 'sk_live_geheim', 'transport' => function ($m, $u, $h, $t) use (&$q, &$log) { $log[] = [$m, $u, $h]; return array_shift($q) ?? ['status' => 500, 'body' => '', 'errno' => 0]; }]);
$s = $c->summary('WWW.Scheib.DE');
ok('Zusammenfassung vom kuratierten Endpunkt, Domain ohne www und klein', $s['project']['id'] === 15 && $s['ampel'] === 'red' && str_ends_with($log[0][1], '/api/v1/integration/domains/scheib.de/summary'));
ok('Nur lesende Anfragen, Schlüssel im Kopf und nicht in der Adresse', count(array_filter($log, fn($l) => $l[0] !== 'GET')) === 0 && str_contains(implode('|', $log[0][2]), 'X-API-Key: sk_live_geheim') && !str_contains($log[0][1], 'sk_live'));
ok('Domainliste', count((new Client(['apiKey' => 'k', 'transport' => fn() => $resp(['success' => true, 'data' => ['domains' => [['domain' => 'a.de'], ['domain' => 'b.de']]]])]))->domains()) === 2);

function kind(callable $f): ?ErrorKind { try { $f(); return null; } catch (StoredoException $e) { return $e->kind; } }
$mk = fn(array $replies) => new Client(['apiKey' => 'k', 'retries' => 1, 'transport' => function () use (&$replies) { return array_shift($replies) ?? ['status' => 500, 'body' => '', 'errno' => 0]; }]);
ok('Kein Schlüssel: Einrichtungsfehler', kind(fn() => new Client(['apiKey' => ''])) === ErrorKind::Config);
ok('401: Zugriff', kind(fn() => $mk([$resp(['success' => false, 'message' => 'Invalid API key'], 401)])->domains()) === ErrorKind::Auth);
ok('Plan-Limit 402 im Körper: Limit', kind(fn() => $mk([$resp(['success' => false, 'code' => 402])])->domains()) === ErrorKind::Limit);
ok('Zeitüberschreitung nach Wiederholung: Netz', kind(fn() => $mk([['status' => 0, 'body' => '', 'errno' => 28], ['status' => 0, 'body' => '', 'errno' => 28]])->domains()) === ErrorKind::Network);
ok('Wiederholung heilt kurzen Aussetzer', count($mk([['status' => 503, 'body' => '', 'errno' => 0], $resp(['success' => true, 'data' => ['domains' => [['domain' => 'a.de']]]])])->domains()) === 1);
ok('Domain ohne Projekt (404): NotFound', kind(fn() => $mk([$resp(['success' => false, 'message' => 'Domain not found'], 404)])->summary('x.de')) === ErrorKind::NotFound);
ok('Kaputte Antwort: Response', kind(fn() => $mk([['status' => 200, 'body' => '<html>', 'errno' => 0]])->domains()) === ErrorKind::Response);

$store = [];
$cache = new class($store) { public function __construct(public array &$s) {} public function get($k) { return $this->s[$k] ?? null; } public function set($k, $v, $t) { $this->s[$k] = $v; return true; } };
$n = 0;
$cc = new Client(['apiKey' => 'k', 'cache' => $cache, 'transport' => function () use (&$n) { $n++; return ['status' => 200, 'body' => json_encode(['success' => true, 'data' => []]), 'errno' => 0]; }]);
$cc->domains(); $cc->domains();
ok('Zwischenspeicher: zweiter Aufruf ohne Anfrage', $n === 1);

echo $fails === 0 ? "\nalle grün\n" : "\n$fails Fehler\n";
exit($fails === 0 ? 0 : 1);
