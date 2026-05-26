# AI代码修改记录

## 基本信息
- **生成/修改日期**：2026-05-25 15:57:00
- **生成/修改者**：AI智能体
- **版本号**：1.1.6
- **关联需求**：修复后台域名白名单绕过漏洞 — 配置了 `admin_allowed_domains => ['fxfw.dengtanet.com']` 后，通过 `https://a.sldn.cn/admin/login.php` 仍能进入后台登录界面
- **修改类型**：安全加固
- **影响等级**：高（核心安全功能失效）

## 代码变更清单
1. 修改：`includes/functions.php` — 重写 `getCurrentAdminHost()` 函数

## 详细修改说明

### 根因分析

`getCurrentAdminHost()` 原实现使用 `$_SERVER['SERVER_NAME']` 获取当前域名：

```php
$serverName = $_SERVER['SERVER_NAME'] ?? '';
if (!empty($serverName) && $serverName !== '_' && strtolower($serverName) !== 'localhost') {
    $host = $serverName;  // ← 服务器配置的主域名
}
```

当多个 DNS 别名（`a.sldn.cn`、`fxfw.dengtanet.com`）解析到同一台服务器时：
- `$_SERVER['SERVER_NAME']` 始终返回 Nginx/Apache 虚拟主机配置的 `server_name` 值
- 即使客户端访问 `a.sldn.cn`，`SERVER_NAME` 仍为 `fxfw.dengtanet.com`
- 白名单匹配 `fxfw.dengtanet.com` → 检查通过 → 白名单被绕过

### 修复方案

将域名获取源从 `SERVER_NAME`（服务器配置）改为 `HTTP_HOST`（客户端实际请求）：

```php
function getCurrentAdminHost(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    // ... 严格正则验证防止 Host 头注入 ...
    return $host;
}
```

**安全考量**：
- `HTTP_HOST` 经过与 `install.php` 一致的严格域名正则验证（标签不能以横线开头/结尾）
- 验证失败时返回空字符串，此时 `checkAdminDomain()` 无法匹配任何白名单条目 → 拒绝访问（fail-safe）
- 正则 `/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)+$/` 有效防止 Host 头注入

## 等保2.0合规验证
- **访问控制**：修复后正确实现基于域名的后台访问控制，只有白名单域名可访问管理后台
- **输入验证**：对 HTTP_HOST 进行严格格式验证，防止 Host 头注入攻击
- **安全审计**：域名拒绝访问时记录完整审计日志（IP、域名、URI）

## 测试验证情况
- 语法检查：零 linter 错误
- 逻辑验证：
  - 白名单域名访问 → `HTTP_HOST=fxfw.dengtanet.com` → 匹配白名单 → 正常访问 ✅
  - 非白名单域名访问 → `HTTP_HOST=a.sldn.cn` → 不匹配 → 跳转 access_denied.php ✅
  - Host 头注入尝试 → 正则验证失败 → 返回空 → 拒绝访问 ✅
  - 白名单为空 → 不限制 → 正常访问 ✅

## 依赖变更说明
- 无依赖变更

## 回滚方案
回滚到 v1.1.5 版本：
1. 还原 `includes/functions.php` 中 `getCurrentAdminHost()` 函数至 v1.1.5 版本
2. 重新部署

## 风险评估
- 安全风险：无（修复了高危绕过漏洞）
- 功能风险：低（反向代理/CDN 场景下 HTTP_HOST 可能被改写，需配合实际环境验证）
- 性能风险：无

## 备注
- `SsoAuth::getFullRedirectUri()` 和 `getFullPostLogoutUri()` 中仍使用 `SERVER_NAME` 构建 SSO 回调 URL，此用途与白名单不同，暂不修改
- 建议后续版本增加双重验证（`SERVER_NAME` 和 `HTTP_HOST` 都必须匹配白名单），或提供配置开关
