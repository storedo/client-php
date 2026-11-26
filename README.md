# STOREDO-Client für PHP

Holt Bewertung, Befunde und Aufgaben je Domain in eigene Software (Agenturverwaltung, Kundenportal). Ohne Abhängigkeiten (curl), PHP 8.1+, nur lesend.

Installation mit Composer:

```bash
composer require storedo/client
```

Ohne Composer genügt `require 'src/Client.php';`.

```php
require 'vendor/autoload.php';   // oder: require 'src/Client.php';
$c = new \Storedo\Client(['apiKey' => getenv('STOREDO_KEY')]);   // Integrations-Schlüssel (read:projects, read:scans)
try {
    $s = $c->summary('beispiel.de');
    echo $s['ampel'];                      // green | yellow | red | none
    foreach ($s['tasks'] as $t) echo $t['title'];
} catch (\Storedo\StoredoException $e) {
    echo $e->kind->value, ': ', $e->getMessage();   // config | auth | limit | network | not_found | response
}
```

- Schlüssel erzeugen: STOREDO, Konto, API-Schlüssel, Bereiche `read:projects` und `read:scans` (optional `project_ids`), Ablauf wählen. Der Schlüssel darf nur `/api/v1/integration/*` lesen, nie Konto, Rechnungen oder Rohdaten. Nie den Hauptschlüssel verwenden.
- Optionen: `baseUrl`, `timeout` (10 s), `retries` (2), `cache` (Objekt mit `get`/`set`), `transport` (für Tests).
- Ampel je Bereich (rechnet der Server): rot bei kritischem Befund oder unter 70 Punkten, gelb bei hohen Befunden oder unter 85, sonst grün.
- Test ohne Netz: `php tests/run.php`.

Konzept und Ausbau (Integrations-Schlüssel, Endpunkte, Webhooks): `concept/INTEGRATION_API_2026-11-26.md`.
