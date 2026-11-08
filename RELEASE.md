# YeCha 发布标准规范（Release Standard）

> 适用范围：每次发布新版本（打 tag + 出包 + 上 GitHub），以及**日常每一次提交**（不发版也必须同步 GitHub，见第 6 节）。本规范是**强制清单**，逐项完成、缺一不可。
> 实测依据：v1.2.4 发布（2026-11-06）完整走通本流程。

## 0. 前置条件
- [ ] 代码改动已在 106（`/www/wwwroot/yecha.org`）完成，`git status` 工作树干净。
- [ ] 提交已落到 `main`，`core/version.php` 的 `YC_VERSION` 已 bump 到目标版本。
- [ ] tag 状态确认（未打 / 已打且指向正确 commit）。

## 1. 更新日志检查（CHANGELOG review）
- [ ] 顶部「版本」与 `core/version.php` 一致。
- [ ] `git diff <prev_tag> HEAD --stat` 的每个文件都在「改动面」列出，无遗漏、无多余。
- [ ] 每条改动具备六要素：**根因 / 修复 / 判据 / 验收 / 部署 / 回滚**，可回溯。
- [ ] 文档描述与真实代码一致（函数名、分支条件、受影响语言等）。

## 2. 程序功能完全性检查（functionality completeness）
- [ ] 改动的所有 PHP 文件 `php -l` 通过。
- [ ] 改动引用的既有函数/方法确实存在（`grep -n "function xxx"` 确认）。
- [ ] 改动自包含：有安全回退路径，不改动既有逻辑分支（除非明确必要）。
- [ ] 接受判据（性能/渲染/CLI 实测）可在实例或本地复现，或显式记录无法复现的外部依赖。
- [ ] 无未提交半成品；`git diff` 范围与 commit 完全一致。

## 3. 出包（build artifact）
- [ ] 构建：`git archive --prefix=yecha-X.Y.Z/ vX.Y.Z | gzip > releases/yecha-X.Y.Z.tar.gz`
- [ ] 记录 SHA256 到 `releases/MANIFEST.sha256`。
  - ⚠️ **gzip 头部含构建时间戳**：同 tag 两次 `git archive | gzip` 得到的 `.gz` SHA 不同。比对内容一致性请用 `gzip -dc file.tar.gz | sha256sum`，与 `git archive vX.Y.Z | sha256sum` 比对（应一致）。
- [ ] 抽查 tar 内 `core/version.php` 版本号正确（`tar xzf ... -O yecha-X.Y.Z/core/version.php | grep YC_VERSION`）。

## 4. GitHub 发布（publish）
- [ ] Token 有效：`curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $TOKEN" https://api.github.com/user` 返回 200。
  - 用经典 token（`ghp_` 前缀，勾 `repo`）最稳；细粒度 token 常因 Contents 只读而 403。
- [ ] 推送：`git push <remote> main vX.Y.Z`（main + 新 tag 一起推；**不把 token 写入 git config**，仅用于本次命令 URL）。
- [ ] 创建 Release（非 draft），body 取自 CHANGELOG 对应小节（用 `awk "/^## 0\./{f=1} /^## 1\./{f=0} f"` 抽取对应版本段）。
- [ ] 上传 `tar.gz` 为资产：`POST uploads.github.com/repos/ye-cha/yecha/releases/<id>/assets?name=yecha-X.Y.Z.tar.gz`，`Content-Type: application/gzip`。
- [ ] 验证：GitHub 上 tag / release / asset 三处就绪（API 各自查一次）。

## 5. 收尾
- [ ] 服务器上含 DB 密码等敏感信息的临时脚本已删除。
- [ ] 项目日志（`.workbuddy/memory`）追加本次发布结论。

## 6. 日常同步（不发版也必须推送）

> 目标：GitHub 上的 `ye-cha/yecha` 永远与线上 106 仓库一致，不能出现「线上改完、GitHub 落后几个提交」。

- [ ] 每次功能性提交后立即 `git push origin main`（展示层、文档、内容接线等不发版的改动同样适用）。
- [ ] 同步校验：`git rev-list --left-right --count main...origin/main` 必须输出 `0\t0`（左=本地独有，右=远端独有）。
- [ ] 文件数校验：`git ls-tree -r main --name-only | wc -l` 与 GitHub `git/trees/main?recursive=1` 的 blob 数一致。
- [ ] tag 校验：`comm -23 <(git tag | sort) <(git ls-remote --tags origin | awk '{print $2}' | sed 's#refs/tags/##' | grep -v '\^{}' | sort)` 应为空。
- [ ] 提交前 `git status --short` 必须为空；有未提交改动就先提交再推，不要「推了一半」。
- [ ] 凭证：106 的 `origin` 为 HTTPS + `credential.helper store`，token 存 `/root/.git-credentials`（权限 600，仓库外）。
  **不要把 token 写进仓库文件、提交内容或 `git config` 的值里**；换 token 时只改该文件。
- [ ] 是否顺带发版（bump `YC_VERSION` + tag + 出包 + Release）单独判断：涉及内核/对外接口变更则按第 1~5 步发版，纯展示层/文档可只推 `main`。

---

## 附：v1.2.4 走查记录（本规范首用例）
- 改动面：`core/Front.php`(+14 行，tw 繁体深翻页 bounds 快路径)、`core/version.php`(1.2.3→1.2.4)、`CHANGELOG.md`。
- 校验：`php -l` 通过；`metaPageBoundsReady / metaPageIdsByBounds / polyRowsByIds` 均为既有函数；tar 内容 SHA 与 `git archive v1.2.4` 一致（`2c66fbd4…`）。
- 发布：`main f8a8341..e9925c0`，新 tag `v1.2.4`，Release `436357881`，资产 `yecha-1.2.4.tar.gz`(76.7MB, uploaded)。
- 文档小瑕：CHANGELOG 写 `p.lang='tw'`，代码实际用通用 `$lang` 参数（对其他非 zh 语言同样生效），方向正确，未改代码。
