-- ============================================================
-- 灯塔DNS拦截响应平台 - 数据库初始化脚本
-- 安装程序会自动替换表前缀和管理员信息
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------
-- 管理员表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `admin_users`;
CREATE TABLE `admin_users` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `username` varchar(50) NOT NULL COMMENT '用户名',
    `email` varchar(100) NOT NULL COMMENT '邮箱',
    `password_hash` varchar(255) NOT NULL COMMENT '密码哈希',
    `role` enum('super_admin','admin','operator','domain_admin','support','security','auditor') NOT NULL DEFAULT 'admin' COMMENT '角色',
    `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态: 1启用 0禁用',
    `permissions` text DEFAULT NULL COMMENT '自定义权限(JSON数组)',
    `sso_id` varchar(255) DEFAULT NULL COMMENT 'SSO 用户标识(Logto sub)',
    `sso_provider` varchar(50) DEFAULT NULL COMMENT 'SSO 提供商(logto)',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `last_login` datetime DEFAULT NULL,
    `last_login_ip` varchar(45) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_username` (`username`),
    UNIQUE KEY `uk_email` (`email`),
    KEY `idx_sso_id` (`sso_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员表';

INSERT INTO `admin_users` (`username`, `email`, `password_hash`, `role`, `status`) VALUES ('admin', 'admin@example.com', '$2y$12$placeholder_hash_please_replace_via_installer', 'super_admin', 1);

-- -----------------------------------------------------------
-- 域名表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `domains`;
CREATE TABLE `domains` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `domain_name` varchar(255) NOT NULL COMMENT '域名',
    `type` enum('expired','violation') NOT NULL COMMENT '类型: expired过期 violation违规',
    `status` enum('active','inactive','released','pending_review') NOT NULL DEFAULT 'active' COMMENT '状态',
    `reason` text DEFAULT NULL COMMENT '违规原因',
    `violation_reason` varchar(500) DEFAULT NULL COMMENT '违规原因详情',
    `expiry_date` date DEFAULT NULL COMMENT '过期日期',
    `expire_date` varchar(50) DEFAULT NULL COMMENT '过期日期(字符串)',
    `contact_email` varchar(255) DEFAULT NULL COMMENT '联系邮箱',
    `customer_email` varchar(255) DEFAULT NULL COMMENT '客户邮箱',
    `customer_name` varchar(100) DEFAULT NULL COMMENT '客户名称',
    `notes` text DEFAULT NULL COMMENT '备注',
    `release_date` datetime DEFAULT NULL COMMENT '释放日期',
    `review_date` datetime DEFAULT NULL COMMENT '审核日期',
    `review_note` text DEFAULT NULL COMMENT '审核备注',
    `review_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending' COMMENT '审核状态',
    `delete_date` date DEFAULT NULL COMMENT '删除日期',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_domain_name` (`domain_name`),
    KEY `idx_type` (`type`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='域名表';

-- -----------------------------------------------------------
-- IP封禁表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `ip_bans`;
CREATE TABLE `ip_bans` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `ip` varchar(45) NOT NULL COMMENT 'IP地址',
    `real_ip` varchar(45) DEFAULT NULL COMMENT '真实IP',
    `reason` varchar(500) DEFAULT NULL COMMENT '封禁原因',
    `ban_type` enum('manual','temporary','permanent') NOT NULL DEFAULT 'manual' COMMENT '封禁类型',
    `detection_source` varchar(50) NOT NULL DEFAULT 'manual' COMMENT '来源',
    `banned_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '封禁时间',
    `expires_at` datetime DEFAULT NULL COMMENT '过期时间',
    `lifted_at` datetime DEFAULT NULL COMMENT '解封时间',
    `lifted_by` int(10) unsigned DEFAULT NULL COMMENT '解封操作人',
    `lift_reason` varchar(500) DEFAULT NULL COMMENT '解封原因',
    `status` enum('active','lifted','expired') NOT NULL DEFAULT 'active' COMMENT '状态',
    `banned_by` int(10) unsigned DEFAULT NULL COMMENT '封禁操作人',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ip` (`ip`),
    KEY `idx_status` (`status`),
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='IP封禁表';

-- -----------------------------------------------------------
-- 访问日志表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `access_logs`;
CREATE TABLE `access_logs` (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `domain_id` int(10) unsigned DEFAULT NULL COMMENT '关联域名ID',
    `ip` varchar(45) NOT NULL COMMENT '访问IP',
    `real_ip` varchar(45) DEFAULT NULL COMMENT '真实IP',
    `user_agent` varchar(500) DEFAULT NULL COMMENT '浏览器UA',
    `referer` varchar(500) DEFAULT NULL COMMENT '来源页面',
    `request_url` varchar(500) DEFAULT NULL COMMENT '请求URL',
    `requested_url` varchar(500) DEFAULT NULL COMMENT '请求URL(别名)',
    `request_method` varchar(10) DEFAULT NULL COMMENT '请求方法',
    `response_code` int DEFAULT NULL COMMENT '响应码',
    `country` varchar(50) DEFAULT NULL COMMENT '国家',
    `region` varchar(50) DEFAULT NULL COMMENT '省份',
    `city` varchar(50) DEFAULT NULL COMMENT '城市',
    `detection_source` varchar(50) DEFAULT NULL COMMENT 'IP检测来源',
    `mac` varchar(50) DEFAULT NULL COMMENT 'MAC地址',
    `browser` varchar(100) DEFAULT NULL COMMENT '浏览器',
    `browser_version` varchar(50) DEFAULT NULL COMMENT '浏览器版本',
    `os` varchar(100) DEFAULT NULL COMMENT '操作系统',
    `os_version` varchar(50) DEFAULT NULL COMMENT '系统版本',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ip` (`ip`),
    KEY `idx_domain` (`domain_id`),
    KEY `idx_created` (`created_at`),
    KEY `idx_country` (`country`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='访问日志表';

-- -----------------------------------------------------------
-- 申诉表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `appeals`;
CREATE TABLE `appeals` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `domain_id` int(10) unsigned DEFAULT NULL COMMENT '关联域名ID',
    `domain_name` varchar(255) NOT NULL COMMENT '域名',
    `customer_name` varchar(100) DEFAULT NULL COMMENT '联系人',
    `contact_name` varchar(100) DEFAULT NULL COMMENT '联系人(别名)',
    `customer_email` varchar(255) NOT NULL COMMENT '联系邮箱',
    `contact_email` varchar(255) DEFAULT NULL COMMENT '联系邮箱(别名)',
    `customer_phone` varchar(20) DEFAULT NULL COMMENT '联系电话',
    `contact_phone` varchar(20) DEFAULT NULL COMMENT '联系电话(别名)',
    `appeal_content` text NOT NULL COMMENT '申诉内容',
    `evidence` text DEFAULT NULL COMMENT '证据材料',
    `evidence_files` text DEFAULT NULL COMMENT '证据文件',
    `appeal_type` varchar(50) DEFAULT NULL COMMENT '申诉类型',
    `is_verified` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否已验证',
    `customer_ip` varchar(45) DEFAULT NULL COMMENT '客户IP',
    `status` enum('pending','processing','approved','rejected') NOT NULL DEFAULT 'pending' COMMENT '状态',
    `review_note` text DEFAULT NULL COMMENT '审核备注',
    `reviewed_by` int(10) unsigned DEFAULT NULL COMMENT '审核人',
    `reviewed_at` datetime DEFAULT NULL COMMENT '审核时间',
    `verification_code` varchar(10) DEFAULT NULL COMMENT '邮箱验证码',
    `verification_token` varchar(64) DEFAULT NULL COMMENT '验证Token',
    `verification_sent_at` datetime DEFAULT NULL COMMENT '验证码发送时间',
    `verified_at` datetime DEFAULT NULL COMMENT '验证时间',
    `verification_verified_at` datetime DEFAULT NULL COMMENT '验证时间(别名)',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_domain` (`domain_name`),
    KEY `idx_email` (`customer_email`),
    KEY `idx_status` (`status`),
    KEY `idx_token` (`verification_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='申诉表';

-- -----------------------------------------------------------
-- 聊天会话表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `chat_conversations`;
CREATE TABLE `chat_conversations` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `appeal_id` int(10) unsigned DEFAULT NULL COMMENT '关联申诉ID',
    `domain_id` int(10) unsigned DEFAULT NULL COMMENT '关联域名ID',
    `domain_name` varchar(255) DEFAULT NULL COMMENT '域名',
    `customer_email` varchar(255) DEFAULT NULL COMMENT '客户邮箱',
    `customer_name` varchar(100) DEFAULT NULL COMMENT '客户名称',
    `customer_ip` varchar(45) DEFAULT NULL COMMENT '客户IP',
    `subject` varchar(255) DEFAULT NULL COMMENT '会话主题',
    `assigned_to` int(10) unsigned DEFAULT NULL COMMENT '分配给的管理员ID',
    `status` enum('active','closed') NOT NULL DEFAULT 'active' COMMENT '状态',
    `last_message_at` datetime DEFAULT NULL COMMENT '最后消息时间',
    `last_message_preview` varchar(200) DEFAULT NULL COMMENT '最后消息预览',
    `unread_admin` tinyint(1) NOT NULL DEFAULT 0 COMMENT '管理员未读数',
    `unread_customer` tinyint(1) NOT NULL DEFAULT 0 COMMENT '客户未读数',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_appeal` (`appeal_id`),
    KEY `idx_domain` (`domain_name`),
    KEY `idx_status` (`status`),
    KEY `idx_customer` (`customer_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='聊天会话表';

-- -----------------------------------------------------------
-- 聊天消息表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `chat_messages`;
CREATE TABLE `chat_messages` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `conversation_id` int(10) unsigned NOT NULL COMMENT '会话ID',
    `sender_type` enum('admin','customer','system') NOT NULL COMMENT '发送者类型',
    `sender_id` int(10) unsigned DEFAULT NULL COMMENT '发送者ID',
    `sender_name` varchar(100) DEFAULT NULL COMMENT '发送者名称',
    `message_type` enum('text','image','video','file','system') NOT NULL DEFAULT 'text' COMMENT '消息类型',
    `message` text DEFAULT NULL COMMENT '消息内容',
    `file_url` varchar(500) DEFAULT NULL COMMENT '文件URL',
    `file_path` varchar(500) DEFAULT NULL COMMENT '文件路径',
    `file_name` varchar(255) DEFAULT NULL COMMENT '文件名',
    `file_size` int(10) unsigned DEFAULT NULL COMMENT '文件大小(字节)',
    `customer_ip` varchar(45) DEFAULT NULL COMMENT '客户IP',
    `is_read` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否已读',
    `read_at` datetime DEFAULT NULL COMMENT '阅读时间',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_conversation` (`conversation_id`),
    KEY `idx_sender` (`sender_type`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='聊天消息表';

-- -----------------------------------------------------------
-- 操作日志表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `logzx`;
CREATE TABLE `logzx` (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `user_id` int(10) unsigned DEFAULT NULL COMMENT '操作用户ID(后台管理员)',
    `user_name` varchar(50) DEFAULT NULL COMMENT '操作用户名',
    `user_role` varchar(50) DEFAULT NULL COMMENT '操作用户角色',
    `user_type` enum('admin','customer','system') NOT NULL DEFAULT 'customer' COMMENT '用户类型',
    `action` varchar(100) NOT NULL COMMENT '操作类型(如:page_view,click,api_call,login,delete,update等)',
    `module` varchar(50) DEFAULT NULL COMMENT '功能模块(如:domains,chat,staff,ipban,appeal等)',
    `description` varchar(500) DEFAULT NULL COMMENT '操作描述',
    `target_type` varchar(50) DEFAULT NULL COMMENT '操作对象类型(如:domain,staff,ip,appeal,conversation等)',
    `target_id` varchar(100) DEFAULT NULL COMMENT '操作对象ID',
    `target_name` varchar(255) DEFAULT NULL COMMENT '操作对象名称',
    `request_url` varchar(500) DEFAULT NULL COMMENT '请求URL',
    `request_method` varchar(10) DEFAULT NULL COMMENT '请求方法',
    `request_data` text DEFAULT NULL COMMENT '请求数据(JSON)',
    `response_code` int DEFAULT NULL COMMENT '响应状态码',
    `ip` varchar(45) DEFAULT NULL COMMENT '访问IP',
    `real_ip` varchar(45) DEFAULT NULL COMMENT '真实IP',
    `user_agent` varchar(500) DEFAULT NULL COMMENT '浏览器UA',
    `browser` varchar(100) DEFAULT NULL COMMENT '浏览器',
    `os` varchar(100) DEFAULT NULL COMMENT '操作系统',
    `duration` int unsigned DEFAULT NULL COMMENT '操作耗时(毫秒)',
    `result` enum('success','failure','pending') NOT NULL DEFAULT 'success' COMMENT '操作结果',
    `error_msg` varchar(500) DEFAULT NULL COMMENT '错误信息',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user` (`user_id`),
    KEY `idx_action` (`action`),
    KEY `idx_module` (`module`),
    KEY `idx_target` (`target_type`, `target_id`),
    KEY `idx_created` (`created_at`),
    KEY `idx_user_type` (`user_type`),
    KEY `idx_ip` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='操作日志表';

-- -----------------------------------------------------------
-- 会话表（PHP Session存储）
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `sessions`;
CREATE TABLE `sessions` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `user_id` int(10) unsigned DEFAULT NULL COMMENT '关联管理员ID',
    `session_token` varchar(128) NOT NULL COMMENT '会话Token',
    `ip` varchar(45) DEFAULT NULL COMMENT 'IP地址',
    `real_ip` varchar(45) DEFAULT NULL COMMENT '真实IP',
    `user_agent` varchar(500) DEFAULT NULL COMMENT '浏览器UA',
    `expires_at` datetime NOT NULL COMMENT '过期时间',
    `sso_session` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否为SSO会话: 1是 0否',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_token` (`session_token`),
    KEY `idx_user` (`user_id`),
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='会话表';

SET FOREIGN_KEY_CHECKS = 1;
