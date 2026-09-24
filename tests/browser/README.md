# 原生后台签到浏览器回归

本测试运行真实 `resources/views/admin.blade.php` 与 `public/assets/admin/` 的 Umi、vendor、components JavaScript/CSS。只将 Blade 启动参数替换为本地测试值，并拦截 API 为同源模拟响应；不创建替代组件、不连接真实账号、不调用真实业务接口。

安装测试依赖并运行（不修改依赖清单）：

```sh
npm install --no-save --package-lock=false playwright
npx playwright install chromium
node tests/browser/run-native-admin-checkin.mjs
```

Runner 自动启动只监听 `127.0.0.1` 随机端口的静态服务，完成后关闭服务和浏览器。可以使用现有 Playwright 安装目录作为 `NODE_PATH`，无需重复安装。可选截图：

```sh
CHECKIN_SCREENSHOT=/tmp/native-admin-checkin.png node tests/browser/run-native-admin-checkin.mjs
```

检查内容包括：真实 bundle 加载、站点标签页内的签到与运气签到开关、缺省关闭、关闭主开关时联动关闭运气签到、没有独立签到标签页或奖励范围配置、鉴权头、原有站点设置自动保存、保存失败后的恢复、跨标签页串行保存及页面错误。实际检查数量由 Runner 输出。

签到与现有配置保存共用 Promise 队列，避免本页多个请求同时触发后端整体配置文件覆写。队列在明确成功或失败后继续下一项。

请求复用既有工具，没有新增超时。若连接一直挂起，请刷新页面后重新加载并核对服务端配置；HTTP、业务错误和明确的网络失败可直接重试。不要在写入结果未确认时绕过队列自动重试。
