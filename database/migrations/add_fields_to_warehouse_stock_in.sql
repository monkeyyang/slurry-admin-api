-- 向 warehouse_stock_in 表添加 tracking_link、order_link 和 order_status 字段
ALTER TABLE `warehouse_stock_in` 
ADD COLUMN `tracking_link` varchar(255) DEFAULT NULL COMMENT '物流链接' AFTER `tracking_number`,
ADD COLUMN `order_link` varchar(255) DEFAULT NULL COMMENT '订单链接' AFTER `order_number`,
ADD COLUMN `order_status` varchar(50) DEFAULT NULL COMMENT '订单状态' AFTER `country`; 