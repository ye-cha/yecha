# CHANGELOG · YeCha（夜叉）系统

> 发布日：2026-11-06 · 版本：**1.2.4**（含 1.2.1 / 1.2.2 / 1.2.3 / 1.2.4）
> 口径：只写「做了什么 + 判据」，逐条可回溯到 `/root/ta55/`（本轮）与 `_lqpix/ta/报告-*.md`（历史）。
> 说明：本文件随发行包冻结；出包后再改文字 = 重发一次版本。

## 0. 1.2.4 要点（tw 深翻页接 bounds 快路径）

| 组成 | 数值 |
|---|---|
| 系统版本 | `core/version.php` = `1.2.4` |
| 改动面 | `core/Front.php`（`polyMetaPage` 新增 tw 侧 bounds 快路径分支） |
| 规范检查 | `php -l` 全过；gushuwu.com tw 分类/标签深翻页实测 27~278ms（修复前 2~10s） |

### 0.1 根因（gushuwu.com 慢日志 / 直跑 `Front::enMetaList` 实测）
- zh 侧分类/标签页深翻页自 v1.2.0 起已走 `ye_meta_page_bounds` 键序快路径（O(limit)，毫秒级）；但 **tw（繁体）侧** `polyMetaPage` 在候选窗口法短页后直接回退 `relationships` 全量 filesort：唐 95k 关联、诗/宋/唐等密集大术语深翻页（offset 数万~百万）扫 10~30 万行 = 2~10s，慢日志可见 10s/166k 行指纹。
- `ye_meta_page_bounds` 排序键在 `ye_contents`（`published_at DESC, id DESC`），与语言无关，zh/tw 共用同一张表，但 tw 侧此前未接入。

### 0.2 修复
- 在 `polyMetaPage` 旧 `relationships` 回退之前插入 bounds 快路径分支：`metaPageBoundsReady()` 守卫 → 查 `ye_metas.count` → `metaPageIdsByBounds()` 取本页精确键 → `polyRowsByIds($lang, $byBounds, $limit, 0)` 回表按 `p.lang='tw'` 筛译文。
- 密集大术语（唐宋诗）tw 译文占比高，本页基本满 `limit`，毫秒级；tw 子集不足 `limit`（末段或稀疏术语）时退回旧 `relationships` 回退（行为不变，无回归）。
- 排序键一致性：译文侧 `p.id` 与 `c.id(group_id)` 同序（top 3000 互查错位 0 条），内容逐行等价。

### 0.3 判据 / 验收
- 服务器直跑 `Front::enMetaList`（Lang::switch('zh_TW')）真实路径：诗 page/40000 278ms、宋 page/15000 147ms、唐 page/7000 109ms、曲 page/5 27ms（修复前 2~10s）。
- `php -l core/Front.php` 通过；依赖的 `metaPageBoundsReady` / `metaPageIdsByBounds` / `polyRowsByIds` / `Db::one` 均为 canon 既有，无新增依赖、不改动既有逻辑。

### 0.4 发行包与部署
- 发行包：`yecha-1.2.4.tar.gz` —— 基于 tag `v1.2.4` 的 `git archive`（校验和见同目录 `MANIFEST.sha256`）。
- 部署实例：yecha.org（canonical 仓库）提交 `v1.2.4` tag；gushuwu.com 此前已在实例层先行应用该分支（本次并入框架、消除分叉），bounds 预计算表由 `tools/gushu-meta-page-bounds.php --check` 巡检 cron 维持新鲜（水位守卫失效即自动重建）。

### 0.5 回滚
- 版本回滚：`core/version.php` 改回 `1.2.3`；`polyMetaPage` 该分支 `git revert` 或 checkout 历史版本；tw 深翻页回退到旧 `relationships` 路径（慢但正确）。

## 1. 1.2.3 要点（AI 翻译健壮性：覆盖模式防雪崩 + 语种校验防源文回显）

| 组成 | 数值 |
|---|---|
| 系统版本 | `core/version.php` = `1.2.3` |
| 改动面 | `core/Poly.php`（`aiTranslate` 语种校验 + 重试）、`admin/posts.inc.php`（续链容错）、`admin/index.php`（`ai_cont` 续链入口） |
| 规范检查 | 改后 `php -l` 全过；xuecha.org 日语页实测渲染为日语（含假名） |

### 0.1 根因（xuecha.org `xianyexianniangshucha` 实测）
- 1.2.2 的「覆盖翻译」在中文→日语/韩语时把**中文源文原样回显**：DeepSeek 因中日/中韩同属汉字文化圈，常将中文当日语/韩语输出；旧 `aiTranslate` 仅校验 JSON 能否解析，解析成功即 `ok:true`，于是把中文静默入库。
- 后果：前台 `/ja/`、`/ko/` 进的是目标语言模式（站点名已是日文/韩文），但文章标题正文全是中文 —— 表现为「翻译很快结束、日语没翻出来」。
- 另：1.2.2 的自动续链在**单种语言失败即整批停链**，表现为「只翻了 1 种就结束」。

### 0.2 修复
- **语种校验 + 自动重试（2 次）**：`Poly::aiTranslate` 新增目标语种脚本校验（日语须含假名、韩语须含谚文、拉丁语种须含字母且非纯汉字）；不通过则强化提示词（"必须用目标语言 native 书写、不得保留中文"）重试；仍失败**如实报错、绝不入库**。
- **系统提示词强化**：明确 "Translate into fluent {lang}, keep Markdown/links, return JSON" 并列出受保护术语，降低源文回显概率。
- **接口超时 20s → 18s**：配合单次单语言节奏，坏接口快速失败。
- **续链容错**：`admin/posts.inc.php` 自动续链改为「单种失败也继续翻下一种」，最后统一报告成功/跳过/失败，不再因某语言失败整批停链。

### 0.3 判据 / 验收
- CLI 实测 `Poly::aiTranslate(30,'ja')`：title「本来このお茶は存在しなかったが…」、正文日文、`kana_check=含假名(YES)`、7.5s。
- 前台 `/ja/xianyexianniangshucha.html` 标题已变为日语；`php -l` 三文件全过。
- 测试脚本已清理，未留痕。

### 0.4 发行包与部署
- **发行包**：`yecha-1.2.3.tar.gz` —— 基于 tag `v1.2.3` 的 `git archive`（校验和见同目录 `MANIFEST.sha256`）。
- **部署实例**：xuecha.org（canonical 仓库同级实例）已应用 1.2.1→1.2.3 全部修复并冒烟；yecha.org 仓库同步提交 v1.2.1 / v1.2.2 / v1.2.3 三个 tag。

### 0.5 回滚
- 版本回滚：`core/version.php` 改回 `1.2.2`；`Poly.php` / `posts.inc.php` / `index.php` 回退对应 commit（`git revert` 或 checkout 历史版本）。

## 2. 1.2.2 要点（AI 翻译「覆盖翻译」致死服务器 → 单请求单语言 + 并发锁 + 自动续链）

| 组成 | 数值 |
|---|---|
| 系统版本 | `core/version.php` = `1.2.2` |
| 改动面 | `core/Poly.php`（接口超时 60s→20s）、`admin/posts.inc.php`（续链）、`admin/index.php`（`ai_cont` 入口） |
| 规范检查 | `php -l` 全过；xuecha.org 重压后 `load average` 117→0.6 |

### 1.1 根因（xuecha.org 实测 load 117、Cloudflare 524）
- 旧 `pc_ai_row` 在**单个 HTTP 请求里串行翻译全部语言**，配合 `Poly::aiTranslate` 用 `file_get_contents(...,['timeout'=>60])` 且无 `set_time_limit`：重复点击 → 多个 FPM 进程各卡 60s 网络等待 → 进程池雪崩。

### 1.2 修复
- **单请求只翻 1 种语言**：不再一口气跑全部语言。
- **按 `cid` 文件并发锁**（300s TTL）：重复点击/多标签页不再叠加 FPM 进程。
- **会话队列 + meta-refresh 自动续链**：把 N 种语言拆成 N 个短请求自动连做；任意一种失败即停链（防坏接口烧服务器）；安全阀 20 次迭代。
- **`set_time_limit(30)` 硬墙钟** + `Poly.php` AI 接口超时 **60s→20s**（快速失败）。
- 后台分发器新增 `ai_cont` GET 续链入口。

### 1.3 判据 / 验收
- xuecha.org / yecha.org 后台均 HTTP 200；`load average` 117→0.6；`php -l` 三文件无语法错误。

## 3. 1.2.1 要点（AI 翻译「覆盖翻译」模式 + 同步线上在研改动）

| 组成 | 数值 |
|---|---|
| 系统版本 | `core/version.php` = `1.2.1` |
| 改动面 | `admin/posts.inc.php`（覆盖翻译 UI/逻辑）、后台重写、`plugins/market/showcase.php`、`plugins/site`（留言回复）、`themes/bl`、`core/bootstrap.php` 等 |
| 规范检查 | `php -l` 全过；13 个在研改动 + `003_message_reply.sql` 迁移一并入版 |

### 2.1 覆盖翻译（force 模式）
- 列表页「AI 翻译」旁新增 **☐ 覆盖翻译** 勾选：勾选后重译全部语言（仍保护人工修正 `manual_override`）；用 `$_SESSION['force_ai_<cid>']` 记本会话起点，已重译语言（`last_ai_at` ≥ 起点）跳过，支持 45s 超时后续做。

### 2.2 同步线上已在运行的改动（合并入版，与线上运行态一致）
- `admin/index.php` 后台重写；`plugins/market/showcase.php` 主题/插件画廊（lightbox 点击放大、左右/键盘/Esc/循环切换）；`plugins/site` 留言回复后台（新增 `003_message_reply.sql` 幂等迁移，补 `reply`/`reply_at`/`reply_by`/`is_public`）；`plugins/statx/*`；`themes/bl` 详情页；`core/bootstrap.php`。

### 2.3 判据 / 验收
- 13 个改动 + 1 个未跟踪迁移脚本一并提交；`php -l` 全过；v1.2.0 仍可独立回滚。

---

## 4. 1.2.0 要点（性能：总数去规范化 + 缓存次序修正）

| 组成 | 数值 |
|---|---|
| 系统版本 | `core/version.php` = `1.2.0` |
| 改动面 | `core/Front.php`（`postsTotal()` / `home()`）+ 内置主题 `themes/xinglan`、`themes/wenshu`（同款 COUNT 反模式清理） |
| 规范检查 | 改后 `php -l` 全过；线上慢日志不再出现对应 COUNT 指纹 |

### 0.1 根因（gushuwu 1.1M 行 `ye_contents` + 3.27M 行 `ye_relationships` 实测）

- `home()` **先无条件跑 `COUNT(*)`、再读缓存**：缓存只是「事后覆盖」，计数每请求都真跑一次。在 gushu_stage 上该 COUNT 实测 20~35s，且一旦被 `max_execution_time` 打断就永远 `Cache::set` 不到 ⇒ 缓存建不起来 ⇒ 自锁（yecha.org 慢日志累计该指纹 5413 次）。
- `postsTotal()` 缺 `stats.posts_total` 去规范化：缓存过期（3600s）后回退现算，单次 11~100s。
- 标签云反模式（历史）：慢日志曾出现 `SELECT ... FROM ye_metas ... ORDER BY (SELECT COUNT(*) FROM ye_relationships WHERE meta_id=ye_metas.id) DESC`（时间戳 2026-10-19，已非当前热路径）。当前活跃主题 `gushu`/`yecha` 的标签列表早已走 `ye_metas.count` 预计算列；但该反模式仍残留在**内置**主题 `xinglan`/`wenshu`（`(SELECT COUNT(*) FROM {$R} r WHERE r.meta_id=m.id)`），一旦启用即复现同类 3.27M 表 COUNT。

### 0.2 修复（框架通用版，已在 gushuwu 暂存实例验证后精炼）

- **`postsTotal()` 加可选 O(1) 快路径**：优先读 `ye_options.stats.posts_total`（由离线/导入线维护，如 gushuwu 批量导入），读不到再走 `Cache(posts_total_v1, 3600s)` → 现算。**框架不主动写该键**——避免无维护方的站点被首次计算值冻结；无该键时行为与 1.1.0 完全一致。
- **`home()` 改走 `self::postsTotal()`**：删除「先 COUNT 后缓存」与冗余的 `home_total_v1` 缓存，统一复用 `postsTotal()` 的去规范化/缓存次序，彻底消除每请求 COUNT。
- **清理内置主题同款反模式**：`themes/xinglan/views/landing.php` 与 `themes/wenshu`（`landing.php` + `partials/docnav.php`）的标签/分类计数由 `(SELECT COUNT(*) FROM {$R} r WHERE r.meta_id=m.id)` 改为预计算列 `m.count`（8 处/站 ×2 站 = 16 处）。`m.count` 即内核 `Front::tags()` 与 `gushu` 主题已在用的同款口径（已发布文章数），更快且口径更准（不含草稿/删除）。活跃主题 `gushu`/`yecha` 本就如此，无需改动。

### 0.3 判据 / 验收

- 两站（yecha.org / gushuwu）`core/Front.php` + `core/version.php` + 内置主题 `xinglan`/`wenshu`（6 文件）改后 `php -l` 全过。
- 首页与列表页 curl 冒烟 200，零 PHP 警告。
- 慢日志复核：不再出现 `home_total` / `posts_total` 的 `COUNT(*) FROM ye_contents` 与 `(SELECT COUNT(*) FROM ye_relationships)` 标签子查询指纹。
- 归档数据（`published_at` 历史脏数据）**按决策不动**，本次未触碰。

### 0.4 发行包与部署

- **发行包**：`yecha-1.2.0.tar.gz` —— 基于 tag `v1.2.0` 的 `git archive`（含全部已提交框架代码 + 1.2.0 主题清理，校验和见同目录 `MANIFEST.sha256`）。
- **部署实例**：
  - `yecha.org`（canonical，git 仓库）：已应用 1.2.0（commit `a731b37` + 本更新日志修订）；前端经 Cloudflare。
  - `gushuwu.com`（实例 `xuecha-yecha-stage`，非 git）：版本号升 `1.2.0`；`core/Front.php` 保留其暂存优化版（含 `masterworks()` / `daozangIds()` 等专属扩展），框架侧 `home()` / `postsTotal()` 修复已在其中。
- 代码已部署并冒烟：首页 200、零 PHP 报错；部署后慢日志无新增 `home_total` / `posts_total` / 标签子查询指纹。

### 0.5 回滚

- **文件回滚**：`/root/yecha_120_bak_20261105063324/`（yecha.org 的 `Front` / `version` / 主题）、`/root/yecha_120_gushu_theme_20261105063605/`（gushuwu 主题）。
- **版本回滚**：`core/version.php` 改回 `1.1.0`。
- **注意**：仓库工作树另有 12 个未提交在研改动（`admin/` 后台重写、`plugins/market/showcase.php`、`themes/bl` 等，共 +2969/−2740 行）**不在 1.2.0 发行包内**；回滚 1.2.0 不影响它们（仍留在工作树、线上照常运行），是否纳入版本由站主另行裁定。

## 4. 1.1.0 要点（新默认主题）

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

## 5. 1.0.0（2026-10-18）摘要

- YeCha 1.0.0 定版：主题包 33 套 + 插件集 + 更新日志随站上线；13 个内容页静态件、sitemap 三方一致。
- 首页 CMS 化（`themes/yecha/views/landing.php` + `partials/landing-cms.php`），数字全部来自目录/DB，不写死。
- 生产口径：源站强制 https + HSTS，sitemap 全部 https。

---

## 6. 附录 · 1.0.0 发布记录（原样存档）

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
