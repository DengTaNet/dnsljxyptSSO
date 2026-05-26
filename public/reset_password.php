<?php
/**
 * 灯塔DNS拦截响应平台
 *
 * 本平台使用 SSO 单点登录，不支持密码登录和密码重置。
 * 如需登录，请通过 SSO 认证系统进行身份验证。
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

echo "=== 灯塔DNS拦截响应平台 ===\n\n";
echo "[信息] 本平台使用 SSO 单点登录，不支持密码登录和密码重置。\n";
echo "[信息] 如需登录，请通过 SSO 认证系统进行身份验证。\n";
echo "[信息] 如需创建管理员账户，请通过管理后台的组织管理页面操作。\n";
exit(0);
