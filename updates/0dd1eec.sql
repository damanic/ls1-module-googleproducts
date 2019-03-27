ALTER TABLE `shop_products` ADD COLUMN `x_googleproducts_id_exists` tinyint(4) NULL;
update shop_products set x_googleproducts_id_exists = 1;