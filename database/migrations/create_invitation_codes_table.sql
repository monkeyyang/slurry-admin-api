CREATE TABLE `invitation_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(32) NOT NULL COMMENT '邀请码',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '状态 0:未使用 1:已使用',
  `used_by` bigint(20) unsigned DEFAULT NULL COMMENT '使用者ID',
  `used_at` timestamp NULL DEFAULT NULL COMMENT '使用时间',
  `created_by` bigint(20) unsigned NOT NULL COMMENT '创建者ID',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='邀请码表'; 
