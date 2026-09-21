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
        $checks = $this->checksFor('schlecht');

        static::assertSame(['Migration1000000001Schlecht.php'], $checks->afterFindings());
        static::assertSame(['Migration1000000001Schlecht.php'], $checks->constraintFindings());
        static::assertSame(
            ['Migration1000000001Schlecht.php: `preset_option`.`property_group_option_id`'],
            $checks->uniqueFindings(),
        );
    }

    public function testItLeavesTheGoodFixtureAlone(): void
    {
        $checks = $this->checksFor('gut');

        static::assertSame([], $checks->afterFindings());
        static::assertSame([], $checks->constraintFindings());
        static::assertSame([], $checks->uniqueFindings());
    }

    /** Ohne Migrationsverzeichnis gibt es nichts zu beanstanden, nicht etwas zu melden. */
    public function testAnExtensionWithoutMigrationsPasses(): void
    {
        $checks = $this->checksFor('gibt-es-nicht');

        static::assertSame([], $checks->afterFindings());
        static::assertSame([], $checks->constraintFindings());
        static::assertSame([], $checks->uniqueFindings());
    }

    private function checksFor(string $fixture): object
    {
        // Kein eigener Konstruktor: PHPUnit hat den seinen final gesetzt. Die Vorlage wandert
        // deshalb als Eigenschaft hinein, nachdem das Objekt steht.
        $checks = new class('pruefung') extends MigrationHygiene {
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
