<?php

declare(strict_types=1);

namespace Depa\ShopwareChecks;

use PHPUnit\Framework\TestCase;

/**
 * Prüft die Migrationen einer Shopware-Erweiterung auf Muster, die bei der Store-Prüfung
 * beanstandet werden. Die Regeln stehen mit ihrer Herkunft in der README.
 *
 * Verwendung im Plugin — eine Datei, vier Zeilen:
 *
 *     class MigrationHygieneTest extends \Depa\ShopwareChecks\MigrationHygiene
 *     {
 *         protected function pluginRoot(): string { return \dirname(__DIR__); }
 *     }
 *
 * Die Klasse weiß nichts über das Plugin: kein Name, kein Namensraum, keine Tabelle. Fehlt das
 * Migrationsverzeichnis, überspringt sie sich, statt rot zu werden.
 */
abstract class MigrationHygiene extends TestCase
{
    /** Wurzel der Erweiterung — das Verzeichnis, in dem `src/` liegt. */
    abstract protected function pluginRoot(): string;

    /**
     * Wo die Migrationen liegen. Shopwares Vorgabe ist `src/Migration`; wer davon abweicht,
     * überschreibt diese Methode.
     */
    protected function migrationDirectory(): string
    {
        return $this->pluginRoot() . '/src/Migration';
    }

    /**
     * `AFTER` zwingt MySQL, die ganze Tabelle zu kopieren, statt die Spalte sofort anzuhängen.
     *
     * Das ist keine Auslegung: Shopwares eigener `AddColumnTrait` lässt `AFTER` deshalb gar nicht
     * erst zu und schreibt den Grund als Kommentar daneben.
     */
    public function testNoColumnIsAddedAfterAnother(): void
    {
        $found = $this->findColumnsAddedAfterAnother();

        static::assertSame([], $found, implode("\n", [
            '`ADD COLUMN … AFTER …` kopiert bei MySQL die ganze Tabelle, statt die Spalte sofort',
            'anzuhängen. Shopwares AddColumnTrait::addColumn() lässt `AFTER` deshalb nicht zu —',
            'benutze den Trait, dann erledigt sich die Stelle samt Existenzprüfung.',
            'Betroffen: ' . implode(', ', $found),
        ]));
    }

    /**
     * Spalte und Nebenbedingung in EINER Anweisung erzwingen einen vollständigen Neuaufbau der
     * Tabelle; getrennt läuft wenigstens das Anhängen der Spalte sofort durch.
     */
    public function testNoColumnIsAddedTogetherWithAConstraint(): void
    {
        $found = $this->findColumnsAddedWithAConstraint();

        static::assertSame([], $found, implode("\n", [
            'Eine Anweisung, die Spalte UND Nebenbedingung anlegt, lehnt `ALGORITHM=INPLACE` ab',
            'und baut die Tabelle neu auf. Getrennte Anweisungen kosten nur den Fremdschlüssel.',
            'Betroffen: ' . implode(', ', array_unique($found)),
        ]));
    }

    /**
     * Ein `UNIQUE`-Schlüssel über eine NULL-fähige Spalte bindet nur halb: MySQL zählt zwei NULLs
     * als verschieden, doppelte Zeilen sind also weiterhin möglich.
     *
     * Geprüft wird je `CREATE TABLE`: Welche Spalten sind NULL-fähig, und nennt ein `UNIQUE KEY`
     * eine davon?
     */
    public function testNoUniqueKeyCoversANullableColumn(): void
    {
        $found = $this->findUniqueKeysOverNullableColumns();

        static::assertSame([], $found, implode("\n", [
            'Ein UNIQUE-Schlüssel über eine NULL-fähige Spalte verhindert die Doppelung nicht:',
            'MySQL hält zwei NULLs für verschieden. Entweder die Spalte auf NOT NULL setzen, einen',
            'Ersatzwert über eine generierte Spalte indizieren, oder im PreWriteValidationEvent prüfen.',
            'Betroffen: ' . implode(', ', $found),
        ]));
    }

    /** @return list<string> */
    protected function findColumnsAddedAfterAnother(): array
    {
        $found = [];

        foreach ($this->migrations() as $file => $sql) {
            if (preg_match('/ADD\s+COLUMN[^;]*\bAFTER\b/is', $sql) === 1) {
                $found[] = basename($file);
            }
        }

        return $found;
    }

    /** @return list<string> */
    protected function findColumnsAddedWithAConstraint(): array
    {
        $found = [];

        foreach ($this->migrations() as $file => $sql) {
            foreach ($this->alterStatements($sql) as $statement) {
                $addsColumn = preg_match('/\bADD\s+COLUMN\b/i', $statement) === 1;
                $addsConstraint = preg_match('/\bADD\s+(CONSTRAINT|FOREIGN\s+KEY|INDEX|KEY|UNIQUE)\b/i', $statement) === 1;

                if ($addsColumn && $addsConstraint) {
                    $found[] = basename($file);
                }
            }
        }

        return array_values(array_unique($found));
    }

    /** @return list<string> */
    protected function findUniqueKeysOverNullableColumns(): array
    {
        $found = [];

        foreach ($this->migrations() as $file => $sql) {
            foreach ($this->createTableBodies($sql) as $table => $body) {
                $nullable = $this->nullableColumns($body);

                foreach ($this->uniqueKeyColumns($body) as $column) {
                    if (in_array($column, $nullable, true)) {
                        $found[] = basename($file) . ": `{$table}`.`{$column}`";
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Die Migrationen als `Pfad => Inhalt`. Ohne Verzeichnis bleibt die Liste leer, und die
     * Prüfungen laufen gegenstandslos durch — eine Erweiterung ohne eigene Tabellen ist in
     * Ordnung, nicht verdächtig.
     *
     * @return array<string, string>
     */
    private function migrations(): array
    {
        $files = glob($this->migrationDirectory() . '/*.php') ?: [];
        $migrations = [];

        foreach ($files as $file) {
            $migrations[$file] = file_get_contents($file) ?: '';
        }

        return $migrations;
    }

    /**
     * Die einzelnen `ALTER TABLE`-Anweisungen einer Datei. Getrennt wird am Semikolon, denn
     * nur was in DERSELBEN Anweisung steht, teilt sich den Tabellen-Neuaufbau.
     *
     * @return list<string>
     */
    private function alterStatements(string $sql): array
    {
        preg_match_all('/ALTER\s+TABLE[^;]*/is', $sql, $matches);

        return $matches[0];
    }

    /**
     * Die Rümpfe aller `CREATE TABLE`-Anweisungen, je Tabellenname.
     *
     * @return array<string, string>
     */
    private function createTableBodies(string $sql): array
    {
        preg_match_all('/CREATE\s+TABLE[^`]*`([^`]+)`\s*\((.*?)\)\s*(?:ENGINE|;)/is', $sql, $matches, PREG_SET_ORDER);

        $bodies = [];
        foreach ($matches as $match) {
            $bodies[$match[1]] = $match[2];
        }

        return $bodies;
    }

    /**
     * Spalten ohne `NOT NULL` — also die, in denen NULL stehen darf.
     *
     * @return list<string>
     */
    private function nullableColumns(string $body): array
    {
        $columns = [];

        foreach (explode("\n", $body) as $line) {
            $line = trim($line);

            // Nur Spaltendefinitionen: Sie beginnen mit dem Namen in Rückstrichen. Schlüssel
            // und Nebenbedingungen fangen mit einem Wort an (PRIMARY, UNIQUE, CONSTRAINT …).
            if (preg_match('/^`([^`]+)`\s+\S/', $line, $match) !== 1) {
                continue;
            }

            if (stripos($line, 'NOT NULL') === false) {
                $columns[] = $match[1];
            }
        }

        return $columns;
    }

    /**
     * Die Spalten, die in einem `UNIQUE`-Schlüssel genannt werden.
     *
     * @return list<string>
     */
    private function uniqueKeyColumns(string $body): array
    {
        preg_match_all('/UNIQUE\s+(?:KEY|INDEX)?[^(]*\(([^)]+)\)/i', $body, $matches);

        $columns = [];
        foreach ($matches[1] as $list) {
            preg_match_all('/`([^`]+)`/', $list, $names);
            $columns = array_merge($columns, $names[1]);
        }

        return $columns;
    }
}
