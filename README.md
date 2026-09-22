# shopware-extension-checks

Wiederverwendbare Prüfungen für Shopware-Erweiterungen.

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

### Name und Zeitstempel müssen übereinstimmen

Die Zahl im Dateinamen (`Migration1774275954AddNoteToExample.php`) und der Rückgabewert von
`getCreationTimestamp()` müssen dieselbe sein. Sonst laufen zwei Reihenfolgen auseinander:
**Gefunden** werden Migrationen per `scandir` nach Dateiname
([`MigrationCollection.php:161`](https://github.com/shopware/shopware/blob/trunk/src/Core/Framework/Migration/MigrationCollection.php)),
**ausgeführt** nach `creation_timestamp` ASC
([`MigrationRuntime.php:122`](https://github.com/shopware/shopware/blob/trunk/src/Core/Framework/Migration/MigrationRuntime.php)).
Eine Migration, die im Ordner zuletzt steht, läuft dann zuerst — ihr Fremdschlüssel zeigt auf
eine Tabelle, die es noch nicht gibt. Beim nächsten Plugin sieht derselbe Fehler umgekehrt aus,
und niemand sucht ihn in der Reihenfolge.

Ein Name ganz ohne Zahl fällt genauso auf: `bin/console migration:refresh` kommt damit nicht
zurecht (`couldNotDetermineTimestamp`), und der Ordner sagt dann gar nichts über die Reihenfolge.
Dateien **ohne** `getCreationTimestamp()` bleiben außen vor — im Migrationsverzeichnis dürfen laut
Kern auch Traits und Schnittstellen liegen.

*Herkunft:* Store-Rückmeldung. Eine Migration trug ein Datum im Namen (`Migration20240301…`) und
gab einen Unix-Zeitstempel zurück — zwei Zahlen, die nichts miteinander zu tun hatten, und die
Ausführung folgte der zweiten. Die Regel gegen den damaligen Stand laufen gelassen: Sie meldet
genau diese Datei.

*Abhilfe:* `bin/console migration:refresh <Datei>` zieht Dateiname, Klassenname und Rückgabewert
in einem Zug nach. Von Hand alle drei ändern, sonst wirft der Kern beim Laden
(`invalidMigrationClass`).

### Jede Admin-Oberfläche an ein Recht binden

Keine PHP-Testklasse, sondern eine JS-Datei, die der Jest-Lauf des Plugins einbindet — denn
geprüft wird das **echte registrierte Objekt**, nicht der Dateitext. Im Plugin sind es fünf Zeilen:

```js
// src/Resources/app/administration/src/acl-wiring.spec.js
import checkAclWiring from '../../../../../vendor/depa/shopware-extension-checks/js/acl-wiring';

it('bindet jede Admin-Oberfläche an ein Recht', async () => {
    const { modules, open } = await checkAclWiring(() => import('./main.js'));

    expect(modules.length).toBeGreaterThan(0);
    expect(open).toEqual([]);
});
```

**Der Lader wird übergeben und nicht importiert**, und deshalb ist die Funktion async. Ein
`import './main.js'` in der Spec würde vorgezogen und liefe, bevor der Stub `Shopware` setzt; drei
Importe in fester Reihenfolge wären richtig, aber ihre Korrektheit hinge an einer Zeilenfolge, die
„Imports sortieren" jederzeit umschreibt. Ein `require()` wäre synchron und kürzer, verbietet
Shopwares ESLint in der Erweiterung aber (`@typescript-eslint/no-require-imports`) — und der
Extension Verifier zählt das als **Fehler**, nicht als Warnung. Alle drei Formen sind am Verifier
gemessen; diese ist die einzige ohne abgeschaltete Regel und ohne Reihenfolge-Bedingung.

Dazu in der `package.json` des Admins eine Zuordnung für alles, was Jest nicht lesen kann —
Vorlagen, SCSS und Vite-eigenes wie `import.meta.glob`:

```json
"jest": {
    "moduleNameMapper": {
        "\\.(twig|scss)$": "<rootDir>/../../../../vendor/depa/shopware-extension-checks/js/empty-module.js"
    }
}
```

Geprüft wird: Jede Route nennt ein `meta.privilege`, jeder Navigationseintrag und jede
Einstellungskachel ein `privilege`. Eine Route, die **nur weiterleitet**, ist ausgenommen — ihr
Recht sitzt am Ziel, und Shopware macht das selbst so (`sw-landing-page`).

*Herkunft:* Store-Rückmeldung. Ein Admin-Modul nannte an keiner Stelle ein Recht — keine Route,
kein Menüeintrag —, und es gab keine `addPrivilegeMappingEntry`. Die Regel gegen den damaligen
Stand laufen gelassen: Sie meldet alle drei Routen und den Menüeintrag.

*Warum das niemandem auffällt:* `AclService.can()` liefert `true`, sobald gar kein Recht
dasteht — die Oberfläche ist für jeden Admin-Benutzer offen. Und darüber steht `isAdmin()`, das
die Prüfung ganz wegkürzt. Wer das Plugin baut, ist Administrator und sieht beim Durchklicken
nichts.

*Bewusst NICHT geprüft:* ob ein benutztes Recht auch angemeldet ist. Dieselbe Prüfung war
gebaut und ist wieder herausgeflogen, weil sie beim üblichen Muster **falsch meldet**: Beide
Plugins hier erweitern Shopwares eigenen `product`-Schlüssel um eigene Rechte
(`addPrivilegeMappingEntry` mit `key: 'product'`). Danach sieht `product` wie ein eigener
Schlüssel aus, und eine Route, die `product.creator` verlangt — eine Rolle, die es im Kern
gibt —, wäre als „nicht angemeldet" gemeldet worden. Wem ein fremder Schlüssel gehört, weiß nur
eine Installation; das gehört an einen Lauf mit echtem Shop, nicht hierher.

*Die Prüfung prüfen:* `node js/acl-wiring.js` — die Datei bringt ihren eigenen Selbsttest mit
(kaputtes Modul, stimmiges Modul, Weiterleitung ohne Recht, leerer Lauf). Sie hat keine
Abhängigkeiten, es braucht also kein npm.

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

## Was die Regeln lesen

Das gilt für die Migrations-Regeln; die ACL-Regel liest gar keinen Text, sondern das registrierte
Objekt. Gelesen wird **nur, was PHP selbst als Code oder Zeichenkette erkennt**, nie der Dateitext: Die
SQL-Regeln nehmen aus den Tokens die Zeichenketten, die Zeitstempel-Regel nimmt das erste `return`
hinter dem Methodennamen. Ein Kommentar, in dem „ADD COLUMN" steht, löst also nichts aus — vor
Version 0.2.0 tat er genau das —, und ein `return 1234;` im Kommentar deckt keine Abweichung zu.
In den Selbsttest-Vorlagen steht beides drin.

Die Schreibweise ist bewusst großzügig gefasst, denn fremde Plugins schreiben anders als das
eigene. Erkannt wird jede dieser Formen:

| | |
|---|---|
| Rückstriche | freiwillig — `` `tabelle` `` wie `tabelle` |
| Spalte anhängen | `ADD COLUMN x …` **und** `ADD x …` (in MySQL ist `COLUMN` optional) |
| Stelle bestimmen | `ADD … AFTER` **und** `MODIFY`/`CHANGE … AFTER` |
| eindeutiger Schlüssel | im `CREATE TABLE`, als `ALTER TABLE … ADD UNIQUE KEY`, als `CREATE UNIQUE INDEX` |
| Tabellenrumpf | mehrzeilig **und** in einer Zeile (zerlegt wird an Kommas auf Klammerebene) |
| Bezeichner mit Punkt | `uniq.tabelle.spalte`, wie Shopware seine Schlüssel benennt |

Und über **alle** Migrationen hinweg: Die Spalte entsteht in der einen Datei, der Schlüssel
darüber kommt in der nächsten. Zieht eine spätere Migration die Spalte auf `NOT NULL`, ist die
Sache erledigt und die Meldung verschwindet.

*Woher die Liste stammt:* Aus einem Fehlschlag. Die Regeln waren gegen zwei Plugins geschrieben
und die Selbsttest-Vorlagen in derselben Handschrift — der Selbsttest bestätigte also nur, was
ohnehin angenommen war. An einem Plugin mit anderer Schreibweise meldeten alle drei Regeln
nichts, obwohl fünf Fehler darin standen. Seitdem gibt es die Vorlage `variants`, die
jeden Fall in einer ungewohnten Form noch einmal stellt.
