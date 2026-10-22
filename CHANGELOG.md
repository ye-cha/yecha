# CHANGELOG · YeCha（夜叉）系统

> 发布日：2026-10-23 · 版本：**1.1.0**
> 口径：只写「做了什么 + 判据」，逐条可回溯到 `/root/ta55/`（本轮）与 `_lqpix/ta/报告-*.md`（历史）。
> 说明：本文件随发行包冻结；出包后再改文字 = 重发一次版本。

## 1. 1.1.0 要点（新默认主题）

| 组成 | 数值 |
|---|---|
| 系统版本 | `core/version.php` = `1.1.0` |
| 默认主题 | `themes/yecha` **1.1.0**（新设计，仍为出厂默认） |
| 主题契约 | 内核约定的 13 个模板齐备；`theme.json` 补齐 author / license / screenshot / settings |
| 规范检查 | 7 项全过，**不符项 0** |

### 1.1 新默认主题：Tailwind 设计系统移植

- `themes/yecha/views/layout.php` 重写：页头改多级 mega 菜单 + 移动端抽屉，页脚改分类导航，保留暗色切换与语言切换。
- 样式表分三层按序加载：`www.css`（Tailwind 产物，含设计令牌）→ `style.css`（实例功能件）→ `lq-align.css`（桥接层）。
- 三层全部走 `View::asset()` 输出 `?v=<filemtime>`，改样式不用手工改版本号，也不会吃到浏览器旧缓存。

### 1.2 主题规范（7 项 → 不符项 0）

- **元数据**：`theme.json` 由 4 个字段扩到 name / slug / version / author / license / homepage / description / screenshot + 5 项 `settings`（内容宽度 / 强调色 / 每页条数 / 侧栏条数 / 侧栏开关），后台「主题」页可直接渲染成表单。
- **模板完整性**：`404` · `landing` · `layout` + 13 个 `partials/*` 全部齐备（按 `core/View.php` 里内核真实请求的模板清单逐项核）。
- **资源与引用**：静态资源一律 `View::asset()`；页头 Logo / favicon 改为按 `theme.default` 推导，不再写死 `/themes/yecha/`（主题改名/换 slug 仍可用）。
- **安全**：语言包文案里的 HTML 用 `strip_tags($hint,'<a>')` 收口；`$back` 由「先转义再拼接」改为「先拼后转义」（去掉双重转义）；页面内联脚本里的译文改 `json_encode` 输出。
- **规范**：主题内全部 `.php` 过 `php -l`；无 `var_dump` / `print_r` / `console.log` / `eval` / `extract`；主题用到的 85 个语言 key 在中英两套语言包里 0 缺失。
- **响应式**：390 / 768 / 1440 × 15 个页型 = **45 次实测，横向溢出 0**（本轮修掉 `/changelog` 长行内代码撑破版心 40px、`/theme/<id>/` 截图栅格撑破 347px 两处）。
- **文档**：主题内新增 `README.md`（目录结构 / 模板清单 / 可配置项 / 开发约定）与 `screenshot.png`（1200×900）。

### 1.3 站内（yecha.org）

- `theme.default` = `yecha`；后台主题列表仍列出全部 34 套，旧主题随时可切回。
- 页缓存清空重建；移除 `var/home-static/` 里 2026-10-18 的过期首页烘焙件（该站 `home_static.enabled=0`，现为纯动态 + 页缓存）。
- 冒烟：`/` `/en/` 文章页 `/docs` `/changelog` `/market` `/forum` `/search` `/archives/2026/` `/category/` `/pages` `/theme/teadoc/` 404 页 —— 45 次请求全 200（404 页按 404），零 PHP 警告、零控制台报错。
- 发行包 `yecha-1.1.0.tar.gz`（含新主题）+ `MANIFEST.sha256`。

### 1.4 回滚

- 主题回滚：`tar xzf /root/ta55/backup/themes-yecha-before-*.tar.gz -C /www/wwwroot/yecha.org --overwrite`，再清页缓存。
- 版本回滚：`core/version.php` 改回 `1.0.0`。

## 2. 1.0.0（2026-10-18）摘要

- YeCha 1.0.0 定版：主题包 33 套 + 插件集 + 更新日志随站上线；13 个内容页静态件、sitemap 三方一致。
- 首页 CMS 化（`themes/yecha/views/landing.php` + `partials/landing-cms.php`），数字全部来自目录/DB，不写死。
- 生产口径：源站强制 https + HSTS，sitemap 全部 https。

---

## 3. 附录 · 1.0.0 发布记录（原样存档）

> 本段是 1.0.0 出包时随附的站点发布记录（载体 liulanqi.com），原样保留以供回溯；标题已降一级。

> 发布日：2026-10-18 · 版本：**1.0.0** · 载体：YeCha 博客系统 + 主题包 + 插件集
> 口径：按模块分组，条目只写「做了什么 + 判据」，逐条可回溯到 `_lqpix/ta/报告-*.md`。
> 说明：本文件随发行包冻结；出包后再改文字 = 重发一次版本。

### 0. 1.0.0 冻结内容

| 组成 | 数量 | 说明 |
|---|---|---|
| 系统核心 | 1 | 版本号 `core/version.php` = `1.0.0` |
| 主题 | 32 套 + 1 套实验（ChaWiki） = **33** | 全部「后台可见可选、切得动、切得回」；2 套另存（见 §9） |
| 插件 | 16 个目录 | 启用 11 · 停用 5（含本轮新装的企业官网，按站主「只留基本博客」保持停用） |
| 内容 | 1158 篇文章 + 14 个页面 | 与 1172 个内容页静态件、sitemap 三方一致 |

### 1. 主域切站（line：主域替换）

- **liulanqi.com 从旧站切到 YeCha 实例**：两 vhost 同一 docroot `/www/wwwroot/liulanqi-yecha`；主域走严格 410 兜底，实例侧保留 `try_files`。
- **旧站保留可回退**：`/www/wwwroot/liulanqi.com` 原样留存，主域切回 = 改 vhost root 一条。
- **主域登录修复**：登录/注册/用户中心/404 的「搜索 + 最新文章 + 归档」侧栏从模板层去掉（DOM 计数 3/1/2/1 → 0/0/0/0），其余页型该有的侧栏一个没少。
- **后端 S1/S2 安全补丁**：面板 `SECRET` 与 `ADMIN_TOKEN` 解耦 + 缺失 fail-closed + 删字面量（负例 `env -u ... ⇒ exit 1`）。
- 判据：主域/实例 5xx = 0；抽样 20 页全 200；`EXT = []`。

### 2. 工具（908 / 913 / 137 三套账）

- **工具总量**：`/tool` 栏目 1049 个静态条目（内容库 1158 篇文章含 tips/learn/download/tool 四段）。
- **913 款续做**：补 widget 8 → 68 支；续灌 857 款；总览页 + sitemap 覆盖到位。
- **工具 908/914 盘点与分批**：分批落地，逐款有 oracle 与像素/几何判据。
- **md-* 全族对齐**：`md-convert`（A–E 五批）、`md-format`（A/B）、`md-github-cards`（A/B1 + README 生成）、`md-mindmap`（markmap 内联）、`md-slide`（四批）、`md-wechat`（两批 + A）、`md-badge`、`md-toc`、`md2word`（兼容层）、`md2pdf`、`md2image`、`md-fromoffice`、`md-todocx`。
- **零外发**：markmap/mermaid 内联，`md-github-cards` 不依赖外部网络。
- 判据：逐款 `handler` 可用性普查 + 真浏览器几何/像素比对 + 静态/运行时同口径。

### 3. 栏目与页型对齐

- **其余 10 个栏目逐页对齐**（learn/tips/download/tool/markdown/gallery/links 等）。
- **壁纸两栏目删除**（站级去重，保留必要 301/410）。
- **严格 410 兜底**：R-23 回归证明「误伤 = 0」。
- **全站遍历测试**：逐页型 1920/390 双档截图 + DOM 计数判据。

### 4. 后台

- **后台恢复与精简**：菜单从满配收敛到「基本博客」所需；停用 `statx` / `subscribe` / `market`（保留目录，可一键启回）。
- **后台 AI 设置入口可见**：`/admin/?s=poly` 的 `ai_key` / `ai_base` 可配可存。
- **后台账号交付**：管理员 `ynwuse` 强随机口令重置（bcrypt cost=10，与站内同族），端到端真登录穿透验证。
- **CSRF/权限**：无 CSRF 的 POST = 403；无会话访问后台 = 302。

### 5. 云笔记与用户中心

- **云笔记可用**：新建/保存/删除/列表全链修好；保存后回读一致；删除后列表即刻消失。
- **密文笔记**：笔记正文按用户加密存放，迁移后 R2 校验通过。
- **用户中心**：`/user` `/user/notes` `/user/favorites` `/user/comments` `/user/notifications` 五页均为真用户中心（`uc-nav`/`uc-page` 各 1），单列无侧栏。

### 6. 品牌（favicon / logo）

- **favicon 与页头 logo 同一套几何**：`#5048E5` 圆角方 + 白环 + 白点，8× 超采样 LANCZOS。
- **logo 三件换新**：`logo.svg` / `logo-192.png` / `logo-512.png`（含 og:image 兜底），公网 == 盘上，CF 精确 purge 6 条后三轮复验一致。
- **登录页与全站残留清扫**：旧九宫格 logo 无活引用。

### 7. 安全（S1 / S2 / AI 门）

- **S1**：面板 `SECRET`/`ADMIN_TOKEN` 解耦 + fail-closed + 删字面量。
- **S2**：主域后端补丁应用并复验。
- **AI 类 15 款**：登录门 + 日配额（20/人/天，可配）落地；**真 key 端到端**验收通过。
- **访客侧零外发**：全站页面 `EXT = []`（上游请求全在服务端）。

### 8. 主题与插件（本棒整理上线）

- **主题包**：33 套主题 + ChaWiki（实验）全部装到实例 `themes/`，后台「本机主题」下拉可选（35 项），逐套实切冒烟。
- **内核兼容**：生产 `core/Str.php` 缺 `Str::plain()`（仓库「内核合流」已有），18 套主题会 500 ⇒ 按仓库原文补回（纯新增 22 行，补齐后与仓库 `Str.php` 逐字节一致）。
- **企业官网**：`plugins/site`（企业官网插件，12 行业主题皮肤 business/creative/education/energy/estate/finance/food/guofeng/law/logistics/medical/tech）装入实例；按站主「只留基本博客」保持**停用**，启用即用。
- **静态件与主题的关系（重要）**：首页 `var/home-static/index.html` 与 1172 个内容页静态件是**烘焙产物**，切主题不会自动重烘 ⇒ 切主题后必须重建静态件（本棒只做「可切换 + 冒烟」，主域切换待站主拍）。

### 9. 已知与待办

- **两套主题另存（不进可切换面、不进本包）**，均已逐套实切冒烟拿到 500 证据：
  - `gushu`（古书屋双皮）：`/` 与 `/archives` 500 —— 依赖 `ye_contents.words` 列与 `c.pub_y` 生成列（属另一站结构），且需内核 `index.php` 全页缓存按皮肤 cookie 分桶补丁（见主题内 `CORE-PATCH.md`）。
  - `zhiku`（知库）：`/` 500 —— 缺 `views/landing.php`，而生产内核 `View::render()` **无模板回落**（仓库版有、生产版没有）。
  - 两套目录已移至 `/root/ta34/parked/`，可原样搬回 `themes/` 备用。
- 主题包「模板完整度」：`gushu` 缺 `partials/sidebar-en`、`zhiku` 缺 `landing` —— 与上一条同源，已按「另存」处置，不靠回落掩盖。
- 主域是否切主题：**待站主拍**（建议 + 风险 + 回退见发布包 `INSTALL.md` 第 4 节）。
