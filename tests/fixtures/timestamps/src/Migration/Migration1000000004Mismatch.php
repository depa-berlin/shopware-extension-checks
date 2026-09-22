<?php

// Name und Zeitstempel laufen auseinander — der Fall, den die Store-Prüfung beanstandet hat.
// Im Ordner steht diese Datei VOR der 5er, ausgeführt wird sie dahinter.

class Migration1000000004Mismatch extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        // Hier stand einmal `return 1000000004;` — im Kommentar zählt es nicht, und genau das
        // muss die Prüfung durchhalten.
        return 1000000009;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('ALTER TABLE `preset` ADD COLUMN `note` VARCHAR(255) NULL;');
    }
}
