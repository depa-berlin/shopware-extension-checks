<?php

declare(strict_types=1);

namespace Depa\ShopwareChecks\Tests;

use Depa\ShopwareChecks\MigrationHygiene;
use PHPUnit\Framework\TestCase;

/**
 * Prüft die Prüfung: Findet sie die drei Muster in einer absichtlich falschen Migration, und
 * lässt sie die richtige in Ruhe?
 *
 * Ohne das wäre nicht belegt, dass die Ausdrücke greifen — eine Regel, die nie anschlägt, sieht
 * genauso grün aus wie eine erfüllte.
 */
class MigrationHygieneSelfTest extends TestCase
{
    public function testItFindsAllThreePatternsInTheBadFixture(): void
    {
        $checks = $this->checksFor('bad');

        static::assertSame(['Migration1000000001Bad.php'], $checks->afterFindings());
        static::assertSame(['Migration1000000001Bad.php'], $checks->constraintFindings());

        // Zwei Fundstellen, und die zweite ist die wichtigere: Dort steht der Schlüssel in einer
        // ANDEREN Datei als die Spalte. Genau die übersah die Regel anfangs.
        static::assertSame(
            [
                'Migration1000000001Bad.php: `preset_option`.`property_group_option_id`',
                'Migration1000000003LateKey.php: `preset_option`.`property_group_option_id`',
            ],
            $checks->uniqueFindings(),
        );
    }

    /**
     * Dieselben Fehler, anders geschrieben. Ohne diesen Fall belegte der Selbsttest nur, dass
     * die Regeln die Handschrift wiedererkennen, in der auch die Vorlagen verfasst sind — und
     * genau daran scheiterten sie am 22.09.2026 an einem fremden Plugin.
     */
    public function testItFindsTheSameMistakesInAnotherHandwriting(): void
    {
        $checks = $this->checksFor('variants');

        // `MODIFY … AFTER` statt `ADD COLUMN … AFTER`
        static::assertSame(['Migration3000000001Variants.php'], $checks->afterFindings());
        // `ADD `col`` ohne das Wort COLUMN, mit Nebenbedingung daneben
        static::assertSame(['Migration3000000001Variants.php'], $checks->constraintFindings());
        // ohne Rückstriche; und als eigenständiges CREATE UNIQUE INDEX auf eine Einzeiler-Tabelle
        static::assertSame(
            [
                'Migration3000000001Variants.php: `no_backticks`.`option_id`',
                'Migration3000000001Variants.php: `with_index`.`option_id`',
            ],
            $checks->uniqueFindings(),
        );
    }

    public function testItLeavesTheGoodFixtureAlone(): void
    {
        $checks = $this->checksFor('good');

        static::assertSame([], $checks->afterFindings());
        static::assertSame([], $checks->constraintFindings());
        static::assertSame([], $checks->uniqueFindings());
    }

    /** Ohne Migrationsverzeichnis gibt es nichts zu beanstanden, nicht etwas zu melden. */
    public function testAnExtensionWithoutMigrationsPasses(): void
    {
        $checks = $this->checksFor('does-not-exist');

        static::assertSame([], $checks->afterFindings());
        static::assertSame([], $checks->constraintFindings());
        static::assertSame([], $checks->uniqueFindings());
    }

    private function checksFor(string $fixture): object
    {
        // Kein eigener Konstruktor: PHPUnit hat den seinen final gesetzt. Die Vorlage wandert
        // deshalb als Eigenschaft hinein, nachdem das Objekt steht.
        $checks = new class('checks') extends MigrationHygiene {
            public string $fixture = '';

            protected function pluginRoot(): string
            {
                return __DIR__ . '/fixtures/' . $this->fixture;
            }

            /** @return list<string> */
            public function afterFindings(): array
            {
                return $this->findColumnsAddedAfterAnother();
            }

            /** @return list<string> */
            public function constraintFindings(): array
            {
                return $this->findColumnsAddedWithAConstraint();
            }

            /** @return list<string> */
            public function uniqueFindings(): array
            {
                return $this->findUniqueKeysOverNullableColumns();
            }
        };

        $checks->fixture = $fixture;

        return $checks;
    }
}
