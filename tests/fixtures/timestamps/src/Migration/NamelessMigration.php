<?php

// Eine Migration, deren Name gar keinen Zeitstempel nennt. Shopwares `migration:refresh` kommt
// damit nicht zurecht (`couldNotDetermineTimestamp`), und im Ordner sagt der Name nichts über
// die Reihenfolge.

class NamelessMigration extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1000000006;
    }

    public function update(Connection $connection): void
    {
    }
}
