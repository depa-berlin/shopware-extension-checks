<?php

// Absichtlich falsch — Vorlage für den Selbsttest. Jede der drei Regeln kommt genau einmal vor.

$connection->executeStatement('
    ALTER TABLE `example` ADD COLUMN `name` VARCHAR(255) NULL AFTER `type`;
');

$connection->executeStatement('
    ALTER TABLE `product`
    ADD COLUMN `preset_id` BINARY(16) NULL,
    ADD CONSTRAINT `fk.product.preset_id` FOREIGN KEY (`preset_id`) REFERENCES `preset` (`id`);
');

$connection->executeStatement('
    CREATE TABLE IF NOT EXISTS `preset_option` (
        `id` BINARY(16) NOT NULL,
        `preset_id` BINARY(16) NOT NULL,
        `property_group_id` BINARY(16) NOT NULL,
        `property_group_option_id` BINARY(16) NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq.preset_option` (`preset_id`, `property_group_id`, `property_group_option_id`)
    ) ENGINE = InnoDB;
');
