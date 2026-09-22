<?php

// Jede Regel einmal, aber in einer Schreibweise, die ein fremdes Plugin genauso benutzen darf.
//
// Dieser Kommentar enthält absichtlich die Wörter ADD COLUMN, und weiter unten steht ein AFTER:
// Eine Prüfung, die den Dateitext statt des SQL liest, springt darauf an. Diese nicht — sie
// liest nur die Zeichenketten, die PHP selbst als solche erkennt.

// `ADD` ohne das Wort COLUMN, zusammen mit einer Nebenbedingung in derselben Anweisung.
$connection->executeStatement('
    ALTER TABLE `a` ADD `col` INT NULL,
        ADD CONSTRAINT `fk.a` FOREIGN KEY (`col`) REFERENCES `b` (`id`);
');

// AFTER über MODIFY statt über ADD COLUMN — dieselbe Tabellenkopie.
$connection->executeStatement('ALTER TABLE `c` MODIFY COLUMN `col` INT NULL AFTER `other`;');

// Bezeichner ganz ohne Rückstriche.
$connection->executeStatement('CREATE TABLE IF NOT EXISTS no_backticks (
    id BINARY(16) NOT NULL,
    option_id BINARY(16) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_plain (option_id)
) ENGINE = InnoDB;');

// Tabelle in EINER Zeile, und der Schlüssel als eigenständiges CREATE UNIQUE INDEX — mit einem
// Namen, der Punkte enthält, wie Shopware sie vergibt.
$connection->executeStatement('CREATE TABLE `with_index` (`id` BINARY(16) NOT NULL, `option_id` BINARY(16) NULL, PRIMARY KEY (`id`)) ENGINE = InnoDB;');
$connection->executeStatement('CREATE UNIQUE INDEX `uniq.with_index` ON `with_index` (`option_id`);');
