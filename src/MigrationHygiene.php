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
 *
 * **Gelesen wird nur das SQL, nicht die Datei.** PHP tokenisiert seine eigenen Dateien, und aus
 * den Tokens bleiben allein die Zeichenketten übrig. Ohne das sprang die Prüfung auf einen
 * KOMMENTAR an, in dem „ADD COLUMN" stand — eine Prüfung, die auf Prosa anschlägt, verliert ihr
 * Ansehen beim ersten Mal.
 *
 * **Schreibweisen sind absichtlich großzügig gefasst**, denn fremde Plugins schreiben anders:
 * Rückstriche sind überall freiwillig, `ADD` gilt mit und ohne das Wort `COLUMN`, ein
 * Tabellenrumpf wird an Kommas auf Klammerebene zerlegt statt zeilenweise, und ein
 * `CREATE UNIQUE INDEX` zählt wie ein `UNIQUE KEY`.
 */
abstract class MigrationHygiene extends TestCase
{
    /**
     * Ein Bezeichner — mit Rückstrichen oder ohne.
     *
     * Der Punkt gehört dazu: Shopware benennt Schlüssel `uniq.tabelle.spalten` und
     * `fk.tabelle.spalte`. Ohne ihn brach das Muster genau an den Namen, die im Kern üblich sind.
     */
    private const NAME = '`?([A-Za-z0-9_.]+)`?';

    /** Wörter, die nach `ADD` oder am Anfang einer Klausel KEINE Spalte einleiten. */
    private const NOT_A_COLUMN = [
        'CONSTRAINT', 'FOREIGN', 'PRIMARY', 'UNIQUE', 'INDEX', 'KEY',
        'FULLTEXT', 'SPATIAL', 'CHECK', 'PARTITION',
    ];

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
     * erst zu und schreibt den Grund als Kommentar daneben. Gilt genauso fürs Umsortieren einer
     * vorhandenen Spalte per `MODIFY`/`CHANGE` — dieselbe Kopie, derselbe Preis.
     */
    public function testNoColumnIsAddedAfterAnother(): void
    {
        $found = $this->findColumnsAddedAfterAnother();

        static::assertSame([], $found, implode("\n", [
            '`AFTER` bestimmt die Stelle einer Spalte und kopiert bei MySQL dafür die ganze',
            'Tabelle. Shopwares addColumn() lässt es deshalb nicht zu — nimm es statt des eigenen',
            'ALTER TABLE: MigrationStep bringt es mit, Existenzprüfung inklusive.',
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

    /**
     * Der Zeitstempel im Klassennamen und der aus `getCreationTimestamp()` müssen derselbe sein.
     *
     * Sonst laufen Fundreihenfolge und Ausführungsreihenfolge auseinander: Shopware findet die
     * Migrationen per `scandir` nach DATEINAME (`MigrationCollection.php:161`), ausgeführt werden
     * sie aber nach `creation_timestamp` ASC (`MigrationRuntime.php:122`). Eine Migration, die im
     * Ordner zuletzt steht, läuft dann zuerst — ihr Fremdschlüssel zeigt auf eine Tabelle, die es
     * noch nicht gibt, und beim nächsten Plugin sieht es umgekehrt aus.
     */
    public function testEveryMigrationNameCarriesItsCreationTimestamp(): void
    {
        $found = $this->findNamesDisagreeingWithTheirTimestamp();

        static::assertSame([], $found, implode("\n", [
            'Gefunden werden Migrationen nach Dateiname, ausgeführt nach `creation_timestamp`.',
            'Stimmen die beiden Zahlen nicht überein, ist die Reihenfolge im Ordner eine andere als',
            'die beim Installieren. `bin/console migration:refresh` zieht beide Stellen zusammen nach.',
            'Betroffen: ' . implode(', ', $found),
        ]));
    }

    /** @return list<string> */
    protected function findColumnsAddedAfterAnother(): array
    {
        $found = [];

        foreach ($this->migrations() as $file => $sql) {
            foreach ($this->alterStatements($sql) as $statement) {
                // In einem ALTER TABLE heißt `AFTER` immer „an diese Stelle". Ein gleichnamiges
                // Feld stünde in Rückstrichen und ist damit ausgenommen.
                if (preg_match('/(?<!`)\bAFTER\b(?!`)/i', $statement) === 1) {
                    $found[] = basename($file);
                }
            }
        }

        return array_values(array_unique($found));
    }

    /** @return list<string> */
    protected function findColumnsAddedWithAConstraint(): array
    {
        $found = [];

        foreach ($this->migrations() as $file => $sql) {
            foreach ($this->alterStatements($sql) as $statement) {
                $addsColumn = $this->addedColumns($statement) !== [];
                $addsConstraint = preg_match(
                    '/\bADD\s+(CONSTRAINT|FOREIGN\s+KEY|INDEX|KEY|UNIQUE|PRIMARY|FULLTEXT|SPATIAL|CHECK)\b/i',
                    $statement,
                ) === 1;

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
     * Migrationen, deren Name eine andere Zahl nennt als ihr `getCreationTimestamp()`.
     *
     * Eine Datei OHNE diese Methode bleibt außen vor: Im Migrationsverzeichnis dürfen laut Kern
     * auch Traits und Schnittstellen liegen (`MigrationCollection.php:174`), und die tragen keinen
     * Zeitstempel.
     *
     * @return list<string>
     */
    protected function findNamesDisagreeingWithTheirTimestamp(): array
    {
        $found = [];

        foreach ($this->migrationFiles() as $file) {
            $declared = $this->creationTimestampIn(file_get_contents($file) ?: '');
            $isAMigration = $declared !== null;

            if (!$isAMigration) {
                continue;
            }

            $inName = $this->timestampInName(basename($file));

            if ($inName !== $declared) {
                $found[] = sprintf(
                    '%s: Name %s, getCreationTimestamp() %s',
                    basename($file),
                    $inName ?? '(ohne Zahl)',
                    $declared,
                );
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

                foreach ($this->addedColumns($statement) as [$column, $rest]) {
                    if (stripos($rest, 'NOT NULL') === false) {
                        $nullable[$table][] = $column;
                    }
                }

                foreach ($this->columnsMadeNotNull($statement) as $column) {
                    $nullable[$table] = array_values(array_diff($nullable[$table] ?? [], [$column]));
                }
            }
        }

        return $nullable;
    }

    /**
     * Jede Spalte, die ein `UNIQUE`-Schlüssel nennt — mit Tabelle und Datei. Erfasst alle drei
     * Schreibweisen: im Rumpf eines `CREATE TABLE`, als nachgereichtes `ALTER TABLE` und als
     * eigenständiges `CREATE UNIQUE INDEX`.
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

            $pattern = '/CREATE\s+UNIQUE\s+INDEX\s+' . self::NAME . '\s+ON\s+' . self::NAME . '\s*\(([^)]+)\)/i';
            preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                foreach ($this->namesIn($match[3]) as $column) {
                    $found[] = [$file, $match[2], $column];
                }
            }
        }

        return $found;
    }

    /**
     * Die Migrationen als `Pfad => SQL`. Ohne Verzeichnis bleibt die Liste leer, und die
     * Prüfungen laufen gegenstandslos durch — eine Erweiterung ohne eigene Tabellen ist in
     * Ordnung, nicht verdächtig.
     *
     * @return array<string, string>
     */
    private function migrations(): array
    {
        $migrations = [];

        foreach ($this->migrationFiles() as $file) {
            $migrations[$file] = $this->sqlIn(file_get_contents($file) ?: '');
        }

        return $migrations;
    }

    /**
     * Die Migrationsdateien, nach Namen sortiert — wie `scandir` sie im Kern auch liefert.
     *
     * @return list<string>
     */
    private function migrationFiles(): array
    {
        return glob($this->migrationDirectory() . '/*.php') ?: [];
    }

    /**
     * Der Zeitstempel im Dateinamen, gelesen mit dem Muster, das auch Shopwares
     * `migration:refresh` dafür benutzt (`RefreshMigrationCommand.php:61`).
     *
     * Dateiname und Klassenname sind dabei dasselbe: Weichen sie ab, wirft der Kern beim Laden
     * (`MigrationCollection.php:174`) — das fällt laut auf und braucht hier keine Prüfung.
     */
    private function timestampInName(string $filename): ?string
    {
        if (preg_match('/^Migration(\d+)/', $filename, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    /**
     * Die Zahl, die `getCreationTimestamp()` zurückgibt — null, wenn die Datei die Methode nicht
     * hat oder etwas anderes als eine Zahl zurückgibt.
     *
     * Wieder über die Tokens statt über den Dateitext: In einem Kommentar steht `return 1234;`
     * genauso überzeugend da wie im Code. Gelesen wird nur das erste `return` NACH dem
     * Methodennamen, damit keine Zahl von irgendwo sonst in der Datei einspringt.
     */
    private function creationTimestampIn(string $php): ?string
    {
        $noise = [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT];
        $tokens = array_values(array_filter(
            token_get_all($php),
            static fn (array|string $token) => !is_array($token) || !in_array($token[0], $noise, true),
        ));

        $insideTheMethod = false;

        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === \T_STRING && $token[1] === 'getCreationTimestamp') {
                $insideTheMethod = true;
                continue;
            }

            if ($insideTheMethod && $token[0] === \T_RETURN) {
                $next = $tokens[$index + 1] ?? null;

                return is_array($next) && $next[0] === \T_LNUMBER ? $next[1] : null;
            }
        }

        return null;
    }

    /**
     * Das SQL einer PHP-Datei: alles, was in einer Zeichenkette steht — einfach, doppelt oder
     * als Heredoc. Kommentare und Code fallen weg, denn PHP sortiert sie selbst aus.
     */
    private function sqlIn(string $php): string
    {
        $strings = [\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE, \T_INLINE_HTML];
        $sql = '';

        foreach (token_get_all($php) as $token) {
            if (is_array($token) && in_array($token[0], $strings, true)) {
                $sql .= "\n" . $token[1];
            }
        }

        return $sql;
    }

    /**
     * Die einzelnen `ALTER TABLE`-Anweisungen. Getrennt wird am Semikolon, denn nur was in
     * DERSELBEN Anweisung steht, teilt sich den Tabellen-Neuaufbau.
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
     * Die schließende Klammer wird gezählt, nicht geraten: `BINARY(16)` und `DECIMAL(10,2)`
     * bringen jede Abkürzung über einen regulären Ausdruck durcheinander.
     *
     * @return array<string, string>
     */
    private function createTableBodies(string $sql): array
    {
        $pattern = '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?' . self::NAME . '\s*\(/i';
        $bodies = [];
        $offset = 0;

        while (preg_match($pattern, $sql, $match, \PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1] + strlen($match[0][0]);
            $end = $this->closingParenthesis($sql, $start);

            if ($end === null) {
                break;
            }

            $bodies[$match[1][0]] = substr($sql, $start, $end - $start);
            $offset = $end;
        }

        return $bodies;
    }

    /** Die Stelle der Klammer, die die bei `$start` offene wieder schließt. */
    private function closingParenthesis(string $sql, int $start): ?int
    {
        $depth = 1;
        $length = strlen($sql);

        for ($position = $start; $position < $length; $position++) {
            $depth += match ($sql[$position]) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };

            if ($depth === 0) {
                return $position;
            }
        }

        return null;
    }

    /**
     * Ein Tabellenrumpf, zerlegt in seine Klauseln: Spalten, Schlüssel, Nebenbedingungen.
     *
     * Getrennt wird an Kommas auf Klammerebene 0 — zeilenweise ginge auch, aber nur solange
     * jemand die Tabelle mehrzeilig schreibt. Ein Einzeiler ist genauso gültig.
     *
     * @return list<string>
     */
    private function clausesIn(string $body): array
    {
        $clauses = [];
        $current = '';
        $depth = 0;

        foreach (str_split($body) as $character) {
            if ($character === ',' && $depth === 0) {
                $clauses[] = trim($current);
                $current = '';
                continue;
            }

            $depth += match ($character) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };
            $current .= $character;
        }

        $clauses[] = trim($current);

        return array_values(array_filter($clauses, static fn (string $clause) => $clause !== ''));
    }

    /**
     * Spalten eines Tabellenrumpfs ohne `NOT NULL` — also die, in denen NULL stehen darf.
     *
     * @return list<string>
     */
    private function nullableColumnsIn(string $body): array
    {
        $columns = [];

        foreach ($this->clausesIn($body) as $clause) {
            $column = $this->columnDefinedBy($clause);

            if ($column !== null && stripos($clause, 'NOT NULL') === false) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /** Der Spaltenname, wenn die Klausel eine Spalte definiert — sonst null. */
    private function columnDefinedBy(string $clause): ?string
    {
        if (preg_match('/^' . self::NAME . '\s+\S/', $clause, $match) !== 1) {
            return null;
        }

        return in_array(strtoupper($match[1]), self::NOT_A_COLUMN, true) ? null : $match[1];
    }

    /** Die Tabelle, die eine `ALTER TABLE`-Anweisung anfasst. */
    private function tableOf(string $statement): ?string
    {
        if (preg_match('/ALTER\s+TABLE\s+' . self::NAME . '/i', $statement, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    /**
     * Die Spalten, die eine Anweisung anhängt — je Spalte ihr Name und der Rest ihrer Klausel.
     *
     * `COLUMN` ist in MySQL freiwillig (`ALTER TABLE t ADD spalte INT`), deshalb entscheidet das
     * WORT hinter `ADD`: Ist es `CONSTRAINT`, `INDEX`, `KEY` und so weiter, ist es keine Spalte.
     *
     * @return list<array{string, string}>
     */
    private function addedColumns(string $statement): array
    {
        preg_match_all(
            '/\bADD\s+(?:COLUMN\s+)?' . self::NAME . '([^,;]*)/i',
            $statement,
            $matches,
            \PREG_SET_ORDER,
        );

        $columns = [];
        foreach ($matches as $match) {
            if (!in_array(strtoupper($match[1]), self::NOT_A_COLUMN, true)) {
                $columns[] = [$match[1], $match[2]];
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
            if (stripos($clause, 'NOT NULL') !== false) {
                $columns = array_merge($columns, $this->namesIn($clause));
            }
        }

        return $columns;
    }

    /**
     * Die Spalten, die ein `UNIQUE`-Schlüssel nennt — im Tabellenrumpf wie in einem `ALTER`.
     *
     * @return list<string>
     */
    private function uniqueKeyColumnsIn(string $text): array
    {
        preg_match_all('/\bUNIQUE\b[^(;]*\(([^)]+)\)/i', $text, $matches);

        $columns = [];
        foreach ($matches[1] as $list) {
            $columns = array_merge($columns, $this->namesIn($list));
        }

        return $columns;
    }

    /**
     * Die Bezeichner einer Aufzählung wie `` `a`, b, `c` `` — Rückstriche sind freiwillig.
     *
     * @return list<string>
     */
    private function namesIn(string $list): array
    {
        $names = [];

        foreach (explode(',', $list) as $entry) {
            if (preg_match('/' . self::NAME . '/', trim($entry), $match) === 1) {
                $names[] = $match[1];
            }
        }

        return $names;
    }
}
