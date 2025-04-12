-- 入库记录表
CREATE TABLE IF NOT EXISTS `warehouse_stock_in` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `warehouse_id` int(11) NOT NULL COMMENT '仓库ID',
  `goods_id` int(11) NOT NULL COMMENT '货品ID',
  `order_number` varchar(100) DEFAULT NULL COMMENT '订单编号',
  `tracking_number` varchar(100) DEFAULT NULL COMMENT '物流单号',
  `country` varchar(10) DEFAULT NULL COMMENT '国家代码',
  `quantity` int(11) NOT NULL DEFAULT '1' COMMENT '入库数量',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态（1：正常，0：已撤销）',
  `create_user_id` int(11) DEFAULT NULL COMMENT '创建人ID',
  `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_warehouse_id` (`warehouse_id`),
  KEY `idx_goods_id` (`goods_id`),
  KEY `idx_order_number` (`order_number`),
  KEY `idx_tracking_number` (`tracking_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='入库记录表';

-- 库存表
CREATE TABLE IF NOT EXISTS `warehouse_stock` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `warehouse_id` int(11) NOT NULL COMMENT '仓库ID',
  `goods_id` int(11) NOT NULL COMMENT '货品ID',
  `quantity` int(11) NOT NULL DEFAULT '0' COMMENT '库存数量',
  `update_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_warehouse_goods` (`warehouse_id`,`goods_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='库存表'; 