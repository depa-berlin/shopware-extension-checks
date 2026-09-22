<?php

// Dieselben drei Vorgänge, richtig gemacht.

$this->addColumn($connection, 'example', 'name', 'VARCHAR(255)');

$connection->executeStatement('ALTER TABLE `product` ADD COLUMN `preset_id` BINARY(16) NULL;');
$connection->executeStatement('
    ALTER TABLE `product`
    ADD CONSTRAINT `fk.product.preset_id` FOREIGN KEY (`preset_id`) REFERENCES `preset` (`id`);
');

$connection->executeStatement('
    CREATE TABLE IF NOT EXISTS `preset_option` (
        `id` BINARY(16) NOT NULL,
        `preset_id` BINARY(16) NOT NULL,
        `property_group_id` BINARY(16) NOT NULL,
        `property_group_option_id` BINARY(16) NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq.preset_option` (`preset_id`, `property_group_id`, `property_group_option_id`)
    ) ENGINE = InnoDB;
');
