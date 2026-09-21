# shopware-extension-checks

Wiederverwendbare Prüfungen für Shopware-Erweiterungen.

Jede Regel hier stammt aus einer **Rückmeldung der Shopware-Store-Prüfung** oder aus einer
Stelle im Shopware-Kern, die eine Falle benennt. Keine steht aus Geschmack da. Zu jeder Regel
gehört deshalb unten ihre Herkunft — ohne sie ist eine Regel in einem Jahr nicht mehr zu
verteidigen, und der erste, den sie stört, nimmt sie heraus.

**Der Anlass:** Die Store-Prüfung beanstandet Dinge, die in der Dokumentation nicht stehen. Am
21.09.2026 nachgesehen — in den fünf Seiten unter `testing/store/` kommen `ALTER TABLE`,
`unique`, `deprecated` und `ALGORITHM` **kein einziges Mal** vor. Das Wissen existiert, aber es
steht im Quelltext des Kerns, in der Semantik von MySQL oder in den Annotationen einer
Abhängigkeit. Was hier liegt, holt es einmalig ein und hält es fest.

## Verwenden

```bash
composer require --dev depa/shopware-extension-checks
```

Im Plugin eine Datei anlegen — mehr ist es nicht:

```php
// tests/MigrationHygieneTest.php
<?php

declare(strict_types=1);

namespace MeinPlugin\Tests;

class MigrationHygieneTest extends \Depa\ShopwareChecks\MigrationHygiene
{
    protected function pluginRoot(): string
    {
        return \dirname(__DIR__);
    }
}
```

Liegen die Migrationen nicht unter `src/Migration`, wird zusätzlich `migrationDirectory()`
überschrieben. Gibt es gar keine, überspringen sich die Prüfungen — eine Erweiterung ohne
eigene Tabellen ist in Ordnung, nicht verdächtig.

## Die Regeln

### Keine Spalte mit `AFTER` anlegen

`ALTER TABLE … ADD COLUMN … AFTER \`x\`` lässt MySQL die **ganze Tabelle kopieren**, statt die
Spalte sofort anzuhängen. Bei einer großen Tabelle — `product` etwa — ist das eine spürbare
Installationszeit für nichts: Die Spaltenreihenfolge interessiert niemanden, die DAL schon gar
nicht.

*Herkunft:* Shopwares eigener `AddColumnTrait` lässt `AFTER` gar nicht erst zu und schreibt den
Grund als Kommentar daneben: *„don't allow AFTER statements, it causes temporary tables which are
extrem slow, because mysql has to copy whole tables"*. In der Dokumentation steht das nirgends.

*Abhilfe:* `$this->addColumn($connection, 'tabelle', 'spalte', 'VARCHAR(255)')` — **ohne
irgendetwas einzubinden**. `MigrationStep` bindet den Trait selbst ein (Zeile 16), die Methode
steht also in jeder Migration schon bereit; ein eigenes `use AddColumnTrait;` ist überflüssig.
Sie bringt die Existenzprüfung mit, die Migration schrumpft auf einen Aufruf. Vorhanden seit
`v6.7.0.0`.

*Und sie ist dafür gedacht:* Weder `AddColumnTrait` noch `MigrationStep` tragen `@internal`.
Im selben Ordner sind fünf Klassen so markiert — `MigrationRuntime`, `MigrationCollection`,
`MigrationSource`, `MigrationCollectionLoader`, `IndexerQueuer` —, die vier Helfer-Traits und
`MigrationStep` dagegen nicht. Die Grenze zwischen Maschinerie und Werkzeug ist dort bewusst
gezogen.

### Spalte und Nebenbedingung nicht in einer Anweisung

`ADD COLUMN … ADD CONSTRAINT …` in derselben Anweisung lehnt `ALGORITHM=INPLACE` ab und baut die
Tabelle neu auf. Getrennt läuft wenigstens das Anhängen der Spalte sofort durch; der
Fremdschlüssel kostet weiterhin.

*Herkunft:* Store-Rückmeldung, mit der Fehlermeldung als Beleg: *„ERROR 1846 … Adding foreign keys
needs foreign_key_checks=OFF. Try ALGORITHM=COPY"*, während ein alleinstehendes
`ADD COLUMN … ALGORITHM=INSTANT` durchläuft.

### Kein `UNIQUE` über eine NULL-fähige Spalte

MySQL hält zwei `NULL` für **verschieden**. Ein `UNIQUE`-Schlüssel, der eine NULL-fähige Spalte
nennt, verhindert die Doppelung also nur für die Zeilen, in denen dort etwas steht — die
halbe Miete, und die gefährlichere Hälfte bleibt offen.

*Herkunft:* Store-Rückmeldung, auf MariaDB 12.2.2 nachgestellt: zwei Zeilen mit gleicher
Gruppe und `NULL` in der Optionsspalte wurden beide angenommen.

*Abhilfe:* Spalte auf `NOT NULL`, oder eine generierte Spalte indizieren, die NULL auf einen
Ersatzwert abbildet, oder im `PreWriteValidationEvent` prüfen. **Eine Prüfung nur im Admin
genügt nicht** — sie deckt einen von mehreren Schreibwegen ab und nichts außerhalb der
Oberfläche.

### Verworfene Aufrufe sichtbar machen

Keine Testklasse, sondern eine PHPStan-Konfiguration zum Einbinden:

```neon
includes:
    - vendor/depa/shopware-extension-checks/phpstan/deprecations.neon
```

*Herkunft:* Der Extension Verifier bringt `phpstan/phpstan-deprecation-rules` mit, **bindet es in
seiner Konfiguration aber nicht ein** (an `shopware-cli` 0.18.3 nachgesehen). Verworfene Aufrufe
fallen dem offiziellen Werkzeug also nicht auf — dem Menschen, der danach prüft, schon.

*Achtung:* Ob etwas als verworfen gilt, hängt an der Fassung der Abhängigkeit, gegen die
analysiert wird. Die Store-Prüfung arbeitet mit neueren Ständen als der Arbeitsrechner. Wer den
Unterschied nicht will, analysiert in der CI gegen den höchsten unterstützten Stand.

## Was hier NICHT hingehört

Regeln, die nur aus Geschmack entstehen. Jede Regel braucht einen Beleg: eine Rückmeldung, eine
Stelle im Kern, eine gemessene Wirkung. Fehlt der, fehlt die Regel.

## Fernziel

Bewähren sich die Regeln über mehrere Erweiterungen, gehören sie fachlich in
[`shopware-cli`](https://github.com/shopware/shopware-cli) — dann prüft die Store-Einreichung sie
selbst, und niemand braucht dieses Paket mehr. Bis dahin ist es die Sammelstelle.
