# 灯塔DNS拦截响应平台 - 部署文档

> 版本：v1.1.0\
> 更新日期：2026-05-26

***

## 目录

1. [环境要求](#一环境要求)
2. [PHP 扩展要求](#二php-扩展要求)
3. [PHP 取消禁用函数](#三php-取消禁用函数)
4. [安装 SSO 依赖](#四安装-sso-依赖)
5. [伪静态配置](#五伪静态配置)

***

## 一、环境要求

| 组件            | 最低要求          | 推荐配置                  |
| ------------- | ------------- | --------------------- |
| PHP           | >= 8.0        | 8.1+                  |
| MySQL/MariaDB | >= 5.7        | 8.0+                  |
| Web服务器        | Nginx/Apache  | Nginx                 |
| 操作系统          | Linux/Windows | Linux (Ubuntu/CentOS) |

***

## 二、PHP 扩展要求

必须安装以下 PHP 扩展：

```bash
# 数据库相关
- pdo
- pdo_mysql

# 基础功能
- json
- curl
- mbstring
- openssl
- fileinfo
- session
```

检查扩展是否已安装：

```bash
php -m | grep -E "pdo|json|curl|mbstring|openssl|fileinfo|session"
```

***

## 三、PHP 取消禁用函数

#### 3.2 Composer 依赖安装必需函数

**重要**：如果使用 Composer 安装依赖，还需要确保以下函数未被禁用：

| 函数名           | 说明                          |
| ------------- | --------------------------- |
| putenv        | 环境变量设置（配置文件解析需要）            |
| getenv        | 环境变量获取（配置文件解析需要）            |
| proc\_open    | 进程创建（Composer 安装需要依赖）       |
| proc\_close   | 进程关闭（Composer 安装依赖）         |
| shell\_exec   | Shell 执行命令（Composer 安装依赖需要） |
| popen         | 进程打开（Composer 安装依赖）         |
| pcntl\_signal | 处理信号（Composer 安装依赖需求）       |

### 3.3 宝塔面板配置方法

1. 登录宝塔面板
2. 进入「网站」→ 点击对应网站的「设置」
3. 选择「PHP 版本」选项卡
4. 点击「禁用函数」
5. 在禁用函数列表中，删除上述所有函数
6. 保存并重启 PHP 服务

### 3.4 修改 php.ini 配置示例

编辑 `php.ini`，找到 `disable_functions` 配置项，确保上述函数不在其中：

```ini
; 示例：仅禁用真正危险的函数，保留系统和 Composer 需要的函数
disable_functions = 
```

> 注意：生产环境中，安装完依赖后可以重新禁用 Composer 相关函数（proc\_open、proc\_close、shell\_exec、popen、pcntl\_signal）以提高安全性。

修改后重启 PHP-FPM 或 Web 服务器：

```bash
# Nginx + PHP-FPM
sudo systemctl restart php8.1-fpm
sudo systemctl restart nginx

# Apache
sudo systemctl restart apache2
```

***

## 四、安装 SSO 依赖

项目使用 Composer 管理依赖，包括 Logto SSO SDK。

### 1. 安装 Composer（如未安装）

```bash
# Linux/Mac
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Windows
# 下载 https://getcomposer.org/Composer-Setup.exe 安装
```

### 2. 安装项目依赖

进入项目根目录，执行：

```bash
cd /www/wwwroot/fxfw.dengtanet.com
composer install --no-dev --optimize-autoloader
```

### 3. 依赖说明

主要依赖包：

- `logto/sdk`: Logto SSO 认证 SDK（^0.3）

***

## 五、伪静态配置

### Nginx 伪静态配置

在 Nginx 配置的 `server` 块中添加以下规则：

```nginx
# 重写规则
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### Apache 伪静态配置

在项目根目录或 `public` 目录下创建 `.htaccess` 文件：

```apache
RewriteEngine On

# 禁止访问敏感目录
RewriteRule ^(core|config|storage|sql)/ - [F,L]

# 重写规则
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php?$1 [L,QSA]
```

确保 Apache 已启用重写模块：

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

***

## 六、目录权限要求

确保以下目录具有写入权限（755 或 775）：

```
/storage/
/storage/logs/
/public/uploads/
/public/uploads/chat/
/public/uploads/evidence/
```

设置权限命令：

```bash
# Linux
sudo chown -R www-data:www-data /path/to/project
sudo chmod -R 755 /path/to/project/storage
sudo chmod -R 755 /path/to/project/public/uploads
```

***

**版权所有 © 2026 灯塔网络科技（甘肃）有限公司**
