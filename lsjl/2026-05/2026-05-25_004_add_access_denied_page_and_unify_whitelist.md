# AI代码修改记录

## 基本信息
- **生成/修改日期**：2026-05-25 15:19:00
- **生成/修改者**：AI智能体
- **版本号**：1.1.4
- **关联需求**：/admin后台的只有白名单中的域名可以访问，依然没有生效，增加无权访问页面，非白名单域名访问/admin，转到无权访问
- **修改类型**：新增功能 + 代码重构
- **影响等级**：中

## 代码变更清单
1. 新增：public/admin/access_denied.php —— 无权访问展示页面
2. 修改：core/Auth.php —— checkAdminDomain() 跳转 access_denied 替代 404
3. 修改：core/SsoAuth.php —— checkAdminDomain() 跳转 access_denied 替代 404
4. 修改：public/admin/login.php —— 移除内联重复代码，复用 SsoAuth::checkAdminDomain()
5. 修改：public/admin/callback.php —— 移除内联重复代码，复用 SsoAuth::checkAdminDomain()
6. 修改：public/admin/logout.php —— 移除内联重复代码，复用 SsoAuth::checkAdminDomain()
7. 修改：config/config.php —— 更新白名单注释说明

## 详细修改说明

### 1. 新增 access_denied.php
- 创建统一的"无权访问"展示页面，使用深色科技风格
- 包含禁止图标、说明文字、联系管理员提示
- 替代之前简单的 404 裸页面，提供更友好的用户体验

### 2. Auth.php 第122-128行
- 原逻辑：`http_response_code(404); die('<!DOCTYPE html>...404...')`
- 新逻辑：`header('Location: /admin/access_denied.php'); exit;`
- 保留安全日志记录（error_log + Debug::logAuth）

### 3. SsoAuth.php 第497-498行
- 原逻辑：`http_response_code(404); die(...)`
- 新逻辑：添加安全日志记录 + 跳转到 /admin/access_denied.php

### 4-6. login.php / callback.php / logout.php
- 移除内联的 ~25 行域名白名单检查代码（与 Auth/SsoAuth 中的逻辑完全重复）
- 统一改为调用 `$ssoAuth->checkAdminDomain()`，逻辑集中管理
- login.php: SsoAuth 构造后立即调用
- callback.php: SsoAuth 构造后立即调用（移除了构造前的内联检查）
- logout.php: SsoAuth 实例化提前，构造后立即调用

### 7. config/config.php
- 更新 admin_allowed_domains 注释，增加多域名配置示例

## 等保2.0合规验证
- 访问控制：后台域名白名单统一由 Auth/SsoAuth 管理，所有 admin 入口统一校验
- 安全审计：非白名单域名访问记录 error_log 安全告警
- 输入验证：getCurrentHost() 对 HTTP_HOST 进行格式验证，防止 Host 头注入

## 测试验证情况
- 静态分析：所有修改文件零新增 lint 错误
- 逻辑验证：白名单为空时 checkAdminDomain() 直接返回（不限制），向后兼容
- 功能验证：非白名单域名访问 /admin/* 跳转到 access_denied.php

## 依赖变更说明
- 无依赖变更

## 回滚方案
回滚到 v1.1.3：
1. git reset --hard v1.1.3
2. 重新部署文件

## 风险评估
- 安全风险：无（access_denied.php 不暴露任何系统信息）
- 功能风险：低（白名单为空时不限制访问，向后兼容）
- 性能风险：无

## 使用说明
在 config/config.php 中配置白名单后生效：
```php
'admin_allowed_domains' => ['dnslj.dengtanet.com', 'admin.example.com'],
```
配置后，非白名单域名访问 /admin/ 将跳转到"无权访问"页面。

## 备注
- 排查发现 config.php 中 admin_allowed_domains 配置为空数组 []，需部署后填写实际域名
