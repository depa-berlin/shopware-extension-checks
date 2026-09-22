<?php

// Kein Zeitstempel, weil keine Migration: Der Kern lässt im Migrationsverzeichnis auch Traits
// und Schnittstellen zu (`MigrationCollection.php:174`). Die Prüfung muss darüber hinwegsehen.

trait PresetColumnTrait
{
    private function presetColumn(): string
    {
        return 'preset_id';
    }
}
