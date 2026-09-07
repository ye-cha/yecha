<?php
declare(strict_types=1);
/** YeCha 安装向导：检测 → 填写数据库/管理员 → 写 config → 建表 → 种子 → 进入后台 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Asia/Shanghai');

$ROOT = dirname(__DIR__);
$CONFIG = $ROOT . '/config.inc.php';
$error = '';
$done = false;

// 已安装 → 直接跳走（安全：禁止任何形式远程重装；重装请先删除 config.inc.php 再用 CLI）
if (is_file($CONFIG)) {
    header('Location: /');
    exit;
}

function val(string $k, string $d = ''): string
{
    return trim((string)($_POST[$k] ?? $d));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $dbHost = val('db_host', 'mysql');
    $dbPort = val('db_port', '3306');
    $dbName = val('db_name', 'yecha');
    $dbUser = val('db_user', 'yecha');
    $dbPass = $_POST['db_pass'] ?? 'yecha123';
    $prefix = val('db_prefix', 'ye_');
    $siteName = val('site_name', 'YeCha');
    $adminUser = val('admin_user', 'admin');
    $adminMail = val('admin_email', '');
    $adminPass = $_POST['admin_pass'] ?? '';
    $tz = val('tz', 'Asia/Shanghai');

    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $prefix)) { $error = '表前缀只能包含字母数字下划线'; }
    elseif (strlen($adminPass) < 6) { $error = '管理员密码至少 6 位'; }
    elseif (!filter_var($adminMail, FILTER_VALIDATE_EMAIL)) { $error = '邮箱格式不正确'; }
    else {
        // 1) 写配置
        $cfg = "<?php\n// YeCha 配置（安装向导生成）\n"
            . "define('DB_HOST', " . var_export($dbHost, true) . ");\n"
            . "define('DB_PORT', " . var_export($dbPort, true) . ");\n"
            . "define('DB_NAME', " . var_export($dbName, true) . ");\n"
            . "define('DB_USER', " . var_export($dbUser, true) . ");\n"
            . "define('DB_PASS', " . var_export($dbPass, true) . ");\n"
            . "define('DB_PREFIX', " . var_export($prefix, true) . ");\n"
            . "define('SECRET', " . var_export(bin2hex(random_bytes(24)), true) . ");\n"
            . "define('TZ', " . var_export($tz, true) . ");\n"
            . "define('SITE_LANG', 'zh_CN');\n"
            . "define('DEBUG', false);\n";
        if (@file_put_contents($CONFIG, $cfg) === false) {
            $error = '无法写入 config.inc.php，请检查目录权限';
        } else {
            require $CONFIG;
            require $ROOT . '/core/helpers.php';
            spl_autoload_register(fn($c) => is_file($ROOT . "/core/$c.php") ? require $ROOT . "/core/$c.php" : null);
            try {
                // 2) 建表（{PREFIX} 替换；先逐行剔除整行注释，避免注释并入下一条建表语句被丢弃）
                $sql = file_get_contents($ROOT . '/install/schema.sql');
                $sql = str_replace('{PREFIX}', $prefix, $sql);
                $sql = preg_replace('/^--.*$/m', '', $sql) ?? $sql;
                $statements = array_values(array_filter(array_map('trim', explode(';', $sql))));
                foreach ($statements as $st) {
                    if ($st !== '' && !str_starts_with($st, '--')) {
                        Db::run($st);
                    }
                }
                // 3) 种子数据
                seed($siteName, $adminUser, $adminMail, $adminPass, $prefix);
                $done = true;
            } catch (Throwable $ex) {
                $error = '初始化失败：' . $ex->getMessage();
                @unlink($CONFIG);
            }
        }
    }
}

/** 默认选项/管理员/示例文章/分类/标签/评论 */
function seed(string $siteName, string $adminUser, string $adminMail, string $adminPass, string $prefix): void
{
    $T = fn(string $t) => $prefix . $t;

    $opts = [
        'site.name' => $siteName, 'site.subtitle' => '一个安静的写作之地',
        'site.languages' => json_encode(['zh'], JSON_UNESCAPED_UNICODE),
        'theme.default' => 'yecha', 'home.style' => 'text',
        'seo.pretty' => '1', 'seo.permalink_format' => '{slug}.html',
        'comment.moderation' => '1',
        'blogger.bio' => '安静写字，认真喝茶。本站运行于 YeCha（PHP + MySQL，Typecho 式极简）。',
    ];
    foreach ($opts as $k => $v) {
        Db::insert('options', ['name' => $k, 'value' => $v, 'autoload' => 1]);
    }

    $adminId = Db::insert('users', [
        'username' => $adminUser, 'email' => $adminMail,
        'password' => password_hash($adminPass, PASSWORD_DEFAULT),
        'role' => 'admin', 'display_name' => '站长',
        'bio' => '安静写字，认真喝茶。', 'status' => 'normal',
    ]);

    // 分类/标签
    $cats = ['suibi' => '随笔', 'jishu' => '技术', 'chanpin-shouji' => '产品手记'];
    $tags = ['yecha' => 'YeCha', 'php' => 'PHP', 'mysql' => 'MySQL', 'jijian' => '极简', 'typecho' => 'Typecho', 'duoyu' => '多语言', 'ai' => 'AI 翻译'];
    $mid = [];
    foreach ($cats as $slug => $name) {
        $mid['c:' . $slug] = Db::insert('metas', ['type' => 'category', 'name' => $name, 'slug' => $slug]);
    }
    foreach ($tags as $slug => $name) {
        $mid['t:' . $slug] = Db::insert('metas', ['type' => 'tag', 'name' => $name, 'slug' => $slug]);
    }

    // 示例文章
    $posts = [
        [
            'title' => 'YeCha 夜叉：从零写一个极简博客系统', 'slug' => 'yecha-minimal-blog',
            'cat' => 'suibi', 'tags' => ['yecha', 'php', 'mysql', 'jijian'],
            'excerpt' => '为什么又要写一个博客系统？不是重复造轮子，而是对轻量的执念：不依赖 Redis，一个 PHP 进程就能跑全站，正文永远完整输出。',
            'desc' => 'YeCha 夜叉极简博客系统设计说明', 'kw' => 'YeCha,夜叉,极简博客,PHP,MySQL',
            'content' => <<<'MD'
# 为什么又要写一个博客系统？

这不是**重复造轮子**，而是一种对 *轻量* 的执念：内核不依赖 Redis、不上云市场，一个 PHP 进程就能把全站跑起来，正文永远完整输出。

- 图片 WebP 插件
- 远程图片本地化插件
- 记事本 / 段评 / 多语言（翻译组 + AI 翻译）

> slug 全局唯一，从 WordPress / Typecho 迁入时原网址直接继承。

```php
// 阅读量：同一访客对同一文章自然日去重计 1 次
if (!cookie_has($key)) { $views++; }
```

界面回归 Typecho 式极简：白底、墨字、内容居中、少装饰。夜叉（YeCha）——中文名藏在系统介绍里，英文名负责对外行走。
MD,
        ],
        [
            'title' => '我的学习笔记：划线、注释与段评', 'slug' => 'learning-notes',
            'cat' => 'chanpin-shouji', 'tags' => ['yecha'],
            'excerpt' => '选中一段文字就能高亮、批注，像在纸质书上做笔记；段评让每位读者都能在某句话旁留下公开讨论。',
            'desc' => '学习笔记型用户中心设计', 'kw' => '划线,注释,段评,收藏',
            'content' => <<<'MD'
写作与阅读体验是另一条主线。

选中一段文字，可以像在纸质书上那样**高亮划线**，并写下只属于自己的批注；而 *段评* 让每位读者都能在某句话旁边留下公开讨论，如同小说网站的"本章说"。

- 收藏：一键收藏文章，用户中心异步加载
- 划线：quote + 段落锚点，正文改动后自动重锚
- 注释：挂在划线或段落下，仅自己可见

> 一个围绕"认真读"的用户中心，而不只是冷冰冰的评论区。
MD,
        ],
        [
            'title' => '一篇文章，多国语言：翻译组与 AI 自动翻译', 'slug' => 'polyglot-translation',
            'cat' => 'jishu', 'tags' => ['duoyu', 'ai'],
            'excerpt' => '类似 Drupal：每篇文章是一个翻译组，/zh/ 与 /en/ 各有真实内容版本；AI 生成草稿，人工双语审校后发布。',
            'desc' => '多语翻译组与AI自动翻译', 'kw' => '多语言,翻译组,AI,DeepSeek',
            'content' => <<<'MD'
# 一篇文章，多国语言

类似 Drupal 的思路：每篇文章是一个**翻译组**，各语言拥有独立的标题、slug 与**真实翻译正文**。

- URL：全部语言带前缀，如 `/zh/文章别名`、`/en/文章别名`
- AI：OpenAI 兼容接口可配置（默认 DeepSeek）
- 流程：AI 生成草稿 → 人工双语审校 → 发布

> 未翻译的语言自动回落默认语言展示。
MD,
        ],
        [
            'title' => '关于我们', 'slug' => 'about', 'type' => 'page', 'cat' => null, 'tags' => [],
            'excerpt' => '', 'desc' => '关于本站', 'kw' => '',
            'content' => "YeCha 夜叉 —— PHP + MySQL 的 Typecho 式极简博客系统。\n\n由安装向导自动创建的示例页面，可在后台编辑。",
        ],
    ];

    $now = date('Y-m-d H:i:s');
    foreach ($posts as $i => $p) {
        $cid = Db::insert('contents', [
            'type' => $p['type'] ?? 'post',
            'status' => 'publish',
            'title' => $p['title'], 'slug' => $p['slug'],
            'excerpt' => $p['excerpt'], 'description' => $p['desc'], 'keywords' => $p['kw'],
            'content' => $p['content'], 'content_format' => 'markdown',
            'author_id' => $adminId, 'views' => [1284, 867, 1026, 96][$i] ?? 0,
            'published_at' => date('Y-m-d H:i:s', strtotime($now . ' -' . $i . ' days')),
        ]);
        if ($p['cat'] !== null) {
            Db::insert('relationships', ['content_id' => $cid, 'meta_id' => $mid['c:' . $p['cat']]]);
        }
        foreach ($p['tags'] as $t) {
            Db::insert('relationships', ['content_id' => $cid, 'meta_id' => $mid['t:' . $t]]);
            Db::update('metas', ['count' => (int)Db::one('SELECT count FROM ' . $T('metas') . ' WHERE id=?', [$mid['t:' . $t]]) + 1], 'id=?', [$mid['t:' . $t]]);
        }
        // 分类计数
        if ($p['cat'] !== null) {
            Db::update('metas', ['count' => (int)Db::one('SELECT count FROM ' . $T('metas') . ' WHERE id=?', [$mid['c:' . $p['cat']]]) + 1], 'id=?', [$mid['c:' . $p['cat']]]);
        }
    }

    // 示例评论（首篇已通过）
    $first = (int)Db::one('SELECT id FROM ' . $T('contents') . " WHERE slug='yecha-minimal-blog'");
    Db::insert('comments', [
        'content_id' => $first, 'author_id' => null, 'author_name' => '老茶客',
        'author_email' => 'guest@example.com', 'ip' => '127.0.0.1',
        'body' => '「正文永远完整输出」这条请务必写进验收用例，血泪教训。',
        'kind' => 'segment', 'anchor_kind' => 'paragraph', 'anchor_ref' => '1',
        'anchor_quote' => '正文永远完整输出', 'status' => 'approved',
    ]);
    Db::insert('comments', [
        'content_id' => $first, 'author_id' => null, 'author_name' => '青衫',
        'author_email' => 'guest2@example.com', 'ip' => '127.0.0.1',
        'body' => '极简路线 + 插件化的取舍很认同。', 'kind' => 'normal', 'status' => 'approved',
    ]);
}
?>
<!DOCTYPE html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>YeCha 安装向导</title>
<style>
body{font:15px/1.8 -apple-system,"PingFang SC","Microsoft YaHei",sans-serif;background:#f5f5f3;color:#1f2328;margin:0}
.card{max-width:640px;margin:48px auto;background:#fff;border:1px solid #e6e6e2;border-radius:10px;padding:32px 36px}
h1{font-size:22px;margin:0 0 4px} .sub{color:#6f767e;font-size:13px;margin-bottom:22px}
label{display:block;font-size:13px;color:#6f767e;margin:14px 0 4px}
input,select{width:100%;border:1px solid #e6e6e2;border-radius:6px;padding:8px 10px;font-size:14px;font-family:inherit;background:#fff}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:0 16px}
button{margin-top:22px;width:100%;background:#b3541e;color:#fff;border:0;border-radius:6px;padding:11px;font-size:15px;cursor:pointer}
button:hover{opacity:.92}
.err{background:#fdf0ec;border:1px solid #efc8ba;color:#9c3f1b;border-radius:6px;padding:8px 12px;font-size:13px;margin-top:16px}
.ok{background:#eef4ee;border:1px solid #cfe2d0;color:#2e6b34;border-radius:6px;padding:10px 14px;margin-top:16px}
.ok a{font-weight:700;color:#2e6b34}
.mono{font:12px Consolas,monospace;color:#6f767e}
</style></head><body>
<div class="card">
<h1>YeCha 夜叉 安装向导</h1>
<div class="sub">PHP + MySQL · Typecho 式极简博客。安装将创建数据库表并写入默认配置（表前缀默认 <span class="mono">ye_</span>）。</div>

<?php if ($done): ?>
<div class="ok"><b>安装成功。</b> 数据库表已创建、默认内容已写入。
<br><br><a href="/admin/">进入后台登录（使用刚设置的管理员账号）→</a> 或 <a href="/">查看前台首页 →</a></div>
<?php else: ?>
<?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<form method="post" autocomplete="off">
  <h3 style="font-size:15px;margin:18px 0 2px;">数据库</h3>
  <div class="grid">
    <div><label>主机</label><input name="db_host" value="mysql"></div>
    <div><label>端口</label><input name="db_port" value="3306"></div>
    <div><label>数据库名</label><input name="db_name" value="yecha"></div>
    <div><label>表前缀</label><input name="db_prefix" value="ye_"></div>
    <div><label>用户名</label><input name="db_user" value="yecha"></div>
    <div><label>密码</label><input type="password" name="db_pass" value="yecha123"></div>
  </div>
  <h3 style="font-size:15px;margin:20px 0 2px;">站点与管理员</h3>
  <label>站点名称</label><input name="site_name" value="YeCha 夜叉">
  <div class="grid">
    <div><label>管理员账号</label><input name="admin_user" value="admin"></div>
    <div><label>邮箱</label><input name="admin_email" value="admin@example.com"></div>
  </div>
  <label>管理员密码（≥6 位）</label><input type="password" name="admin_pass" value="">
  <label>时区</label>
  <select name="tz"><option>Asia/Shanghai</option><option>UTC</option></select>
  <button type="submit">开始安装</button>
  <p class="sub" style="margin:10px 0 0;">说明：Docker 环境默认连接 compose 内的 <span class="mono">mysql:3306</span>，库 <span class="mono">yecha</span>、账号 <span class="mono">yecha/yecha123</span>（见 docker/docker-compose.yml）。</p>
</form>
<?php endif; ?>
</div>
</body></html>
