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
            'anzuhängen. Shopwares addColumn() lässt `AFTER` deshalb nicht zu — nimm es statt des',
            'eigenen ALTER TABLE: MigrationStep bringt es mit, Existenzprüfung inklusive.',
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
     * Geprüft wird über ALLE Migrationen hinweg, nicht je Datei: Die Spalte entsteht in der
     * einen, der Schlüssel darüber kommt in der nächsten — so lag der Fall, den die Store-Prüfung
     * beanstandet hat.
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
        $nullable = $this->nullableColumnsPerTable();
        $found = [];

        foreach ($this->uniqueKeyColumnsPerTable() as [$file, $table, $column]) {
            if (in_array($column, $nullable[$table] ?? [], true)) {
                $found[] = basename($file) . ": `{$table}`.`{$column}`";
            }
        }

        return $found;
    }

    /**
     * Welche Spalte welcher Tabelle NULL sein darf — über ALLE Migrationen hinweg.
     *
     * Der Blick in eine einzelne Datei genügt nicht: Die Spalte entsteht in der einen Migration,
     * der Schlüssel darüber kommt Monate später in der nächsten. Genau so lag der Fall, an dem
     * die Store-Prüfung hing — und genau den übersah diese Regel anfangs.
     *
     * Zieht eine spätere Migration die Spalte auf `NOT NULL`, fällt sie wieder heraus: Das ist
     * ja die Abhilfe, und danach darf die Prüfung nicht weiter mahnen. Die Migrationen laufen
     * dafür in ihrer Reihenfolge durch (Dateiname = Zeitstempel).
     *
     * @return array<string, list<string>>
     */
    private function nullableColumnsPerTable(): array
    {
        $nullable = [];

        foreach ($this->migrations() as $sql) {
            foreach ($this->createTableBodies($sql) as $table => $body) {
                foreach ($this->nullableColumnsIn($body) as $column) {
                    $nullable[$table][] = $column;
                }
            }

            foreach ($this->alterStatements($sql) as $statement) {
                $table = $this->tableOf($statement);

                if ($table === null) {
                    continue;
                }

                foreach ($this->addedNullableColumns($statement) as $column) {
                    $nullable[$table][] = $column;
                }

                foreach ($this->columnsMadeNotNull($statement) as $column) {
                    $nullable[$table] = array_values(array_diff($nullable[$table] ?? [], [$column]));
                }
            }
        }

        return $nullable;
    }

    /**
     * Jede Spalte, die ein `UNIQUE`-Schlüssel nennt — mit Tabelle und Datei. Erfasst beide
     * Schreibweisen: im Rumpf eines `CREATE TABLE` und als nachgereichtes `ALTER TABLE`.
     *
     * @return list<array{string, string, string}>
     */
    private function uniqueKeyColumnsPerTable(): array
    {
        $found = [];

        foreach ($this->migrations() as $file => $sql) {
            foreach ($this->createTableBodies($sql) as $table => $body) {
                foreach ($this->uniqueKeyColumnsIn($body) as $column) {
                    $found[] = [$file, $table, $column];
                }
            }

            foreach ($this->alterStatements($sql) as $statement) {
                $table = $this->tableOf($statement);

                if ($table === null) {
                    continue;
                }

                foreach ($this->uniqueKeyColumnsIn($statement) as $column) {
                    $found[] = [$file, $table, $column];
                }
            }
        }

        return $found;
    }

    /** Die Tabelle, die eine `ALTER TABLE`-Anweisung anfasst. */
    private function tableOf(string $statement): ?string
    {
        if (preg_match('/ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?/i', $statement, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    /**
     * Spalten, die eine `ALTER TABLE`-Anweisung anhängt, ohne sie auf `NOT NULL` zu setzen.
     *
     * @return list<string>
     */
    private function addedNullableColumns(string $statement): array
    {
        preg_match_all('/ADD\s+COLUMN\s+`([^`]+)`([^,;]*)/i', $statement, $matches, PREG_SET_ORDER);

        $columns = [];
        foreach ($matches as $match) {
            if (stripos($match[2], 'NOT NULL') === false) {
                $columns[] = $match[1];
            }
        }

        return $columns;
    }

    /**
     * Spalten, die eine Anweisung auf `NOT NULL` zieht.
     *
     * Grob: Bei `CHANGE` nennt MySQL alten UND neuen Namen, und beide werden herausgenommen.
     * Das kann eine Meldung verschlucken, wenn jemand beim Umbenennen gleichzeitig eine zweite
     * Spalte NULL-fähig lässt — dafür meldet es nie fälschlich etwas als kaputt.
     *
     * @return list<string>
     */
    private function columnsMadeNotNull(string $statement): array
    {
        preg_match_all('/(?:MODIFY|CHANGE)\s+(?:COLUMN\s+)?([^,;]*)/i', $statement, $matches);

        $columns = [];
        foreach ($matches[1] as $clause) {
            if (stripos($clause, 'NOT NULL') === false) {
                continue;
            }

            preg_match_all('/`([^`]+)`/', $clause, $names);
            $columns = array_merge($columns, $names[1]);
        }

        return $columns;
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
     * Spalten eines `CREATE TABLE`-Rumpfs ohne `NOT NULL` — also die, in denen NULL stehen darf.
     *
     * @return list<string>
     */
    private function nullableColumnsIn(string $body): array
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
     * Die Spalten, die ein `UNIQUE`-Schlüssel nennt — im Tabellenrumpf wie in einem `ALTER`.
     *
     * @return list<string>
     */
    private function uniqueKeyColumnsIn(string $body): array
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
