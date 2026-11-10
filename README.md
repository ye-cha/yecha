# YeCha（夜叉）

轻量 PHP 内容系统：单入口、服务端直出、多主题多插件、多语言 + AI 翻译。

> 一个 PHP 进程就能跑起来——没有框架依赖、没有 Composer 包袱、没有 Node 前端工程。解压、填库、跑一次安装向导，就能开始发内容。
>
> 当前版本 **1.2.4** · 协议 **MIT** · 官网与文档 <https://yecha.org>

## 特性

- **服务端直出**：内容页 HTML 全部由服务端渲染，JS 只做交互增强；无 JS 也能看全内容，对 SEO 与首屏友好。
- **35 套主题**：`yecha`（默认）、`teadoc`、`warmpaper`、`octern`、`antai`、`astra`、`chawiki`、`jingdu`（静读，专注阅读的极简单栏）等，支持浅色/深色与响应式；主题可切换、模板可回落。
- **20 个插件**：应用市场（market）、多语言（poly）、企业官网（site）、论坛（forum）、统计（statx）、图片优化（imgopt）、静态首页、留言、订阅、友链、自动保存等。
- **多语言**：URL 前缀区分语种（`/`、`/en/`），译文独立存放于 `ye_poly_contents`，分类/标签有别名表；AI 翻译带目标语种脚本校验，防止源文回显。
- **内容模型**：文章 / 页面 / 分类 / 标签 / 评论，支持软删除、自定义字段与内容翻译。
- **性能**：页面缓存、静态首页、分类分页键序快路径（bounds）、计数去规范化；已在 100 万+ 内容页站点（gushuwu.com）验证可秒开。
- **后台**：内容、主题与布局、插件启停、选项表、AI 翻译、系统升级与工具集，带 CSRF 与权限校验。
- **数据自持**：所有数据在自己的服务器上，前台无第三方广告或统计注入。

## 环境要求

| 项目 | 要求 |
|---|---|
| PHP | 8.1+（建议 8.3 / 8.4），需 `pdo_mysql` `mbstring` `curl` `simplexml` `fileinfo` `zip` `openssl` |
| 数据库 | MySQL 5.7+（建议 8.0）或 MariaDB 10.3+，`utf8mb4` |
| Web 服务器 | Nginx 1.20+ / Apache 2.4+（需可配伪静态，建议见 `deploy/nginx-rewrite.conf`） |
| 可写目录 | `uploads/`、`var/cache/`、`var/home-static/` |
| 可选 | HTTPS 证书、CDN、OPcache / Redis |

## 三步安装

1. **取包解压**到站点根目录（docroot），确认 `index.php`、`core/`、`themes/`、`plugins/` 在同一层。
2. **浏览器访问站点根目录**，进入安装向导（`install/index.php`）：填写数据库与管理员账号 → 自动写 `config.inc.php`、建表、写种子。
3. **登录 `/admin/`** 完善站点信息、选主题、启插件，开始发内容。

> 也可手动安装：`cp config.inc.php.example config.inc.php` 填好 `DB_*` 与 `SECRET`，再导入 `install/schema.sql`（`{PREFIX}` 会被替换为 `DB_PREFIX`）。
>
> 安装完成后请**删除或屏蔽 `install/` 目录**（线上默认返回 403）。

详细步骤（伪静态、目录权限、HTTPS、加固、故障排查）见[安装与部署](https://yecha.org/docs-install.html)。

## 升级与备份

```bash
# 1. 先整库备份（或后台「系统升级」一键备份）
mysqldump --single-transaction --default-character-set=utf8mb4 yecha > backup-$(date +%F).sql

# 2. 用新版本覆盖文件，保留 config.inc.php 与 uploads/
# 3. 后台「系统升级」应用迁移，或命令行：
php tools/upgrade.php --component core
```

迁移脚本幂等，执行留痕于 `ye_migrations`。

## 目录结构

```
index.php            前台单一入口（路由分发）
core/                内核（Router / Front / Db / View / Markdown / Poly / Nav / Cache …）
admin/               后台
install/             安装向导、schema.sql 与增量 migrations
themes/<name>/       主题（theme.json + views + assets）
plugins/<name>/      插件（plugin.php 声明路由与后台页，migrations/ 幂等迁移）
lang/                界面语言包（zh_CN / en_US）
tools/               运维脚本（health / bench / upgrade / admin-pass …）
deploy/              伪静态与部署建议（nginx-rewrite.conf）
uploads/  var/       上传与运行时（不入版本控制）
CHANGELOG.md         更新日志
RELEASE.md           发布标准规范（强制清单）
```

## 文档

| 章节 | 链接 |
|---|---|
| 文档中心 | <https://yecha.org/docs> |
| 安装与部署 | <https://yecha.org/docs-install.html> |
| 快速开始 | <https://yecha.org/docs-quickstart.html> |
| 使用手册 | <https://yecha.org/docs-usage.html> |
| 主题开发 | <https://yecha.org/docs-theme.html> |
| 插件开发 | <https://yecha.org/docs-plugin.html> |
| 内核与二次开发 | <https://yecha.org/docs-dev.html> |
| 多语言与 AI 翻译 | <https://yecha.org/docs-i18n.html> |
| 运维、升级与性能 | <https://yecha.org/docs-ops.html> |
| 常见问题 | <https://yecha.org/docs-faq.html> |

## 参与开发

- **插件**：`plugins/<id>/plugin.php` 声明清单与路由，`migrations/*.php` 幂等迁移，独立页与后台页分开；参考 `plugins/forum`、`plugins/market`。
- **主题**：`themes/<name>/theme.json` + `views/` + `assets/`；参考 `themes/yecha`。
- **内核**：`core/` 下为静态类，路由顺序见 `core/Router.php`；改内核请按 `RELEASE.md` 流程发版。

## 安全

- 上线后立即修改管理员口令（`php tools/admin-pass.php` 可重置）。
- 用 `deploy/nginx-rewrite.conf` 拦截 `config.inc.php`、`install/`、`var/`、`tools/` 等敏感路径。
- 官方不会以任何形式索取你的后台口令或数据库密码。

## 版本与发布

- 更新日志：[CHANGELOG.md](CHANGELOG.md) · 在线版 <https://yecha.org/changelog>
- 发布规范：[RELEASE.md](RELEASE.md)
- 版本号唯一来源：`core/version.php` 的 `YC_VERSION`
- 发行包与 Release：<https://github.com/ye-cha/yecha/releases>

## 协议

[MIT](LICENSE) © 2026 YeCha Contributors
