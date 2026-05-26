# 灯塔DNS拦截响应平台

> 版本：v1.1.0  
> 更新日期：2026-05-26

---

## 项目简介

灯塔DNS拦截响应平台是一个专业的域名过期与违规拦截服务系统，提供完整的域名管理、IP封禁、访问日志、申诉处理和在线客服功能。

### 主要特性

- 🔒 **域名拦截**：支持过期域名和违规域名两种拦截类型
- 🛡️ **IP封禁**：手动/临时/永久封禁，支持CDN真实IP检测
- 📊 **访问日志**：完整的访问记录，包含地理位置、浏览器信息等
- 📝 **申诉系统**：用户可提交申诉，支持证据上传和邮箱验证
- 💬 **在线客服**：实时聊天功能，支持文件上传
- 🔐 **SSO登录**：集成 Logto 单点登录
- 🛡️ **安全加固**：符合等保2.0三级要求，包含CSRF防护、XSS防护等
- 📈 **数据统计**：后台仪表盘，实时数据展示

---

## 目录结构

```
dnsljxyptSSO/
├── config/              # 配置文件目录
│   └── config.php       # 主配置文件
├── core/                # 核心类库
│   ├── Auth.php         # 认证类
│   ├── CsrfProtection.php # CSRF防护
│   ├── Database.php     # 数据库类
│   ├── Debug.php        # 调试类
│   ├── IpDetector.php   # IP检测
│   ├── Logger.php       # 日志类
│   ├── RateLimiter.php  # 速率限制
│   └── SsoAuth.php      # SSO认证
├── includes/            # 公共文件
│   ├── functions.php    # 公共函数库
│   ├── header.php       # 页头
│   └── footer.php       # 页脚
├── public/              # Web根目录
│   ├── admin/           # 后台管理
│   │   ├── chat/        # 客服聊天
│   │   ├── domains/     # 域名管理
│   │   ├── ipban/       # IP封禁
│   │   ├── logs/        # 日志查看
│   │   ├── staff/       # 员工管理
│   │   ├── assets/      # 后台资源
│   │   └── partials/    # 后台模板
│   ├── api/             # API接口
│   ├── assets/          # 前台资源
│   ├── uploads/         # 上传文件
│   ├── index.php        # 入口文件
│   ├── install.php      # 安装向导
│   ├── banned.php       # 封禁页面
│   ├── expired.php      # 过期拦截页
│   └── violation.php    # 违规拦截页
├── sql/                 # 数据库文件
│   └── init.sql         # 初始化脚本
├── storage/             # 存储目录
│   └── logs/            # 日志文件
├── composer.json        # Composer配置
├── DEPLOY.md            # 部署文档
└── README.md            # 本文件
```

---

## 系统要求

| 组件 | 最低要求 | 推荐配置 |
|------|---------|---------|
| PHP | >= 8.0 | 8.1+ |
| MySQL/MariaDB | >= 5.7 | 8.0+ |
| Web服务器 | Nginx/Apache | Nginx |
| 操作系统 | Linux/Windows | Linux (Ubuntu/CentOS) |

### PHP 扩展要求

```
pdo, pdo_mysql, json, curl, mbstring, openssl, fileinfo, session
```

### PHP 取消禁用函数

#### 系统运行必需
```
file_get_contents, file_put_contents, mkdir, rename, unlink, fopen, fwrite, fclose, chmod,
session_start, setcookie, header, password_hash, password_verify, random_bytes, bin2hex,
json_encode, json_decode, mb_substr, mb_strlen,
curl_init, curl_exec, curl_setopt, curl_close
```

#### Composer 安装依赖必需
```
putenv, getenv, proc_open, proc_close, shell_exec, popen, pcntl_signal
```

---

## 快速开始

### 1. 安装依赖

```bash
cd /path/to/project
composer install --no-dev --optimize-autoloader
```

### 2. 配置 Web 服务器

将 Web 根目录指向 `public/` 文件夹。

#### Nginx 伪静态配置

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

#### Apache 伪静态配置

在 `public/` 目录创建 `.htaccess`：

```apache
RewriteEngine On
RewriteRule ^(core|config|storage|sql)/ - [F,L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php?$1 [L,QSA]
```

### 3. 设置目录权限

```bash
chmod -R 755 storage/
chmod -R 755 public/uploads/
```

### 4. 运行安装向导

访问 `http://your-domain.com/install.php`，按照向导完成安装。

---

## 功能模块

### 1. 域名拦截

- **过期域名**：拦截已过期的域名，显示续费提示
- **违规域名**：拦截违规内容域名，显示违规通知
- 支持状态管理：active（拦截中）、inactive（未激活）、released（已释放）、pending_review（待审核）

### 2. IP 封禁管理

- 支持手动封禁、临时封禁、永久封禁
- 自动检测 CDN 真实 IP（支持 Cloudflare、腾讯云、阿里云等）
- 封禁原因记录和解封管理

### 3. 访问日志

- 完整记录每次访问信息
- 包含 IP、地理位置、浏览器、操作系统等
- 支持按域名、时间、IP 筛选

### 4. 申诉系统

- 用户可在线提交申诉
- 支持上传证据材料（图片、视频、文件）
- 邮箱验证功能
- 审核流程管理

### 5. 在线客服

- 实时聊天功能
- 支持文字、图片、文件消息
- 会话分配和未读消息提醒

### 6. 后台管理

- 仪表盘：数据统计概览
- 员工管理：角色权限控制
- 日志审计：操作日志和访问日志
- 系统配置：站点设置、安全配置

---

## 数据库表结构

| 表名 | 说明 |
|------|------|
| admin_users | 管理员用户表 |
| domains | 域名拦截表 |
| ip_bans | IP 封禁表 |
| access_logs | 访问日志表 |
| appeals | 申诉表 |
| chat_conversations | 聊天会话表 |
| chat_messages | 聊天消息表 |
| logzx | 操作日志表 |
| sessions | 会话表 |

---

## 角色权限

系统支持以下角色：

| 角色 | 说明 |
|------|------|
| super_admin | 超级管理员，拥有所有权限 |
| admin | 管理员，可管理域名、IP、员工等 |
| operator | 运维人员，可查看和操作基础功能 |
| domain_admin | 域名管理员，专注域名管理 |
| support | 客服人员，处理申诉和聊天 |
| security | 安全人员，IP封禁和安全审计 |
| auditor | 审计员，只读权限查看日志 |

---

## 安全特性

- ✅ CSRF 令牌防护
- ✅ XSS 攻击防护（输出转义 + CSP 头）
- ✅ SQL 注入防护（PDO 预处理语句）
- ✅ 密码安全（bcrypt 哈希）
- ✅ 会话安全（HttpOnly、Secure、SameSite Cookie）
- ✅ 安全响应头（X-Frame-Options、X-Content-Type-Options 等）
- ✅ 后台域名白名单
- ✅ 文件上传安全（MIME 验证、内容验证）
- ✅ 操作日志审计

---

## Logto SSO 集成

系统支持 Logto 单点登录：

1. 在 Logto Console 创建传统 Web 应用
2. 配置回调 URL：`https://your-domain.com/admin/callback.php`
3. 配置登出后重定向 URL：`https://your-domain.com/admin/login.php`
4. 在安装向导或 `config/config.php` 中配置：
   - `logto.endpoint`：Logto 服务端点
   - `logto.appId`：应用 ID
   - `logto.appSecret`：应用密钥

---

## API 接口

系统提供 RESTful API 接口，位于 `public/api/` 目录：

- `auth.php` - 认证相关
- `domain.php` - 域名管理
- `ipban.php` - IP 封禁
- `appeal.php` - 申诉处理
- `chat.php` - 聊天功能
- `staff.php` - 员工管理
- `log.php` - 日志查询
- `public.php` - 公共接口

---

## 调试模式

在 `config/config.php` 中启用调试模式：

```php
'debug' => [
    'enabled' => true,
    'log_queries' => true,
    'log_auth' => true,
    'log_api' => true,
],
```

调试日志存储在 `storage/logs/debug/` 目录。

> **注意**：生产环境请务必关闭调试模式！

---

## 常见问题

### 安装后访问 404

检查：
1. Web 根目录是否指向 `public/`
2. 伪静态规则是否配置正确
3. Apache 是否启用了 mod_rewrite

### SSO 登录失败

检查：
1. Logto 应用配置的回调 URL 是否与系统一致
2. `logto.endpoint`、`appId`、`appSecret` 是否正确
3. 服务器是否能访问 Logto 服务

### 文件上传失败

检查：
1. `public/uploads/` 目录写入权限
2. `php.ini` 中 `upload_max_filesize` 和 `post_max_size` 配置
3. PHP 禁用函数中是否包含文件操作函数

---

## 技术支持

- 部署文档：[DEPLOY.md](./DEPLOY.md)
- 问题反馈：提交 Issue 到项目仓库
- 联系邮箱：support@dengtanet.com

---

## 许可证

版权所有 © 2026 灯塔网络科技（甘肃）有限公司
