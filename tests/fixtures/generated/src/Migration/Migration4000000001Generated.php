<?php

// Generierte Spalten unter einem UNIQUE-Schlüssel. Die ersten beiden bilden NULL auf einen
// Ersatzwert ab und dürfen nicht gemeldet werden — `NOT NULL` lässt MariaDB daneben gar nicht zu.
// Die dritte reicht die NULL nur durch und muss gemeldet werden.

// Wie in DepaPropertyTemplate: nachgereicht per ALTER, `GENERATED ALWAYS AS (IFNULL(…))`.
$connection->executeStatement('
    ALTER TABLE `with_fallback`
        ADD COLUMN `option_key` BINARY(16)
            GENERATED ALWAYS AS (IFNULL(`option_id`, 0x00000000000000000000000000000000)) VIRTUAL;
');
$connection->executeStatement('
    ALTER TABLE `with_fallback`
        ADD UNIQUE KEY `uniq.with_fallback.option_key` (`group_id`, `option_key`);
');

// Andere Handschrift: im Tabellenrumpf, Kurzform ohne GENERATED ALWAYS, COALESCE statt IFNULL.
$connection->executeStatement('
    CREATE TABLE inline_fallback (
        id BINARY(16) NOT NULL,
        option_id BINARY(16) NULL,
        option_key BINARY(16) AS (COALESCE(option_id, 0x00000000000000000000000000000000)) VIRTUAL,
        PRIMARY KEY (id),
        UNIQUE KEY `uniq.inline_fallback.option_key` (option_key)
    );
');

// Ohne Ersatzwert: Die generierte Spalte ist genau so NULL-fähig wie `option_id`.
$connection->executeStatement('
    ALTER TABLE `without_fallback`
        ADD COLUMN `option_key` BINARY(16) GENERATED ALWAYS AS (`option_id`) VIRTUAL;
');
$connection->executeStatement('
    ALTER TABLE `without_fallback`
        ADD UNIQUE KEY `uniq.without_fallback.option_key` (`group_id`, `option_key`);
');
