-- 向 warehouse_stock_in 表添加结算相关字段
ALTER TABLE `warehouse_stock_in` 
ADD COLUMN `is_settled` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否已结算（0：未结算，1：已结算）' AFTER `status`,
ADD COLUMN `settle_time` timestamp NULL DEFAULT NULL COMMENT '结算时间' AFTER `is_settled`,
ADD COLUMN `settle_user_id` int(11) DEFAULT NULL COMMENT '结算人ID' AFTER `settle_time`; 