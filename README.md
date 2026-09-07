# YeCha 夜叉 · 轻量多语言 PHP 博客系统

一个 PHP 进程就能跑起来的极简博客系统。没有框架依赖、没有 Composer 包袱、没有 Node 前端工程——解压、配好数据库、访问一次安装向导，就能开始写字。

- 官网 / 文档 / 市场：https://yecha.org
- 版本：1.0.0-beta
- 协议：MIT

## 特性
- 轻量：PHP 8.1+ / MySQL 5.7+，无需 Redis / Composer / Node
- 多语言：中文 + 英文（译文组、AI 翻译、`/en/` 路径与 hreflang）
- 主题与插件机制（plugins/、themes/、幂等迁移），应用市场
- 论坛插件（板块/帖子/回帖/审核）、友链收录与采集互认、应用市场
- RSS / Sitemap / 搜索 / 归档 / 页面与文章
- 数据全部在自己服务器上，前台无广告

## 环境要求
- PHP 8.1+（需 `pdo_mysql / mbstring / simplexml / curl`），建议 8.3 / 8.4
- MySQL 5.7+（建议 8.0）
- Nginx / Apache（伪静态建议见 `deploy/nginx-rewrite.conf`）

## 三步安装
1. 解压到站点目录，把 `config.inc.php.example` 复制为 `config.inc.php` 并填入数据库信息；
2. 浏览器访问站点根目录 → 一次性安装向导自动建表并创建管理员；
3. 登录后台完善站点信息、选主题，开始写作。

## 升级与备份
- 升级前先整库备份（后台「系统升级」一键备份，或 `mysqldump`）；
- 用新版本文件覆盖（保留 `config.inc.php` 与 `uploads/`），再到后台「系统升级」应用迁移；
- 迁移脚本幂等并留痕于 `ye_migrations`；也可命令行执行 `php tools/upgrade.php --component core`。

## 目录
- `index.php` 前台单一入口 · `admin/` 后台 · `core/` 内核 · `install/` 安装向导与迁移 · `lang/` 多语言包
- `themes/`（yecha / octern / teadoc / forest）· `plugins/`（forum / links / market）· `deploy/`（伪静态建议）

## 开发
- 插件：`plugins/<id>/plugin.php` 清单 + `migrations/*.php` 幂等迁移 + 独立页/后台页（参考 plugins/forum）
- 主题：`themes/<name>/theme.json` + `views/`（参考 themes/yecha）
- 内容与多语：`ye_contents` + `ye_poly_contents` + `ye_metas`（详见网站开发文档）

## 安全
- 上线后尽快修改管理员口令；
- 按 `deploy/nginx-rewrite.conf` 拦截 config / install / var / tools / uploads 敏感路径；
- 官方不会索取口令。
