<?php
declare(strict_types=1);
/**
 * 迁移（2026-09-10 · 1.0.0-beta.3）：清理"对外文案里的模仿对象产品名"。
 * 幂等：按精确串替换，重复执行无副作用；只动系统生成的文案（选项值 + 安装向导示例页），不碰作者手写正文。
 */
return function (): void {
    $map = [
        '（PHP + MySQL，Typecho 式极简）' => '（PHP + MySQL 的极简博客系统）',
        'Typecho 式极简博客系统' => '极简博客系统',
        'Typecho 式极简博客' => '极简博客',
        'Typecho 式极简' => '极简',
        'Typecho-style' => 'single-entry',
        'WordPress 式一键升级' => '一键升级',
        '类似 Drupal 的思路：' => '核心思路：',
        '类似 Drupal：' => '',
        '（Drupal 式节点翻译）' => '（翻译组模型）',
        'Drupal 式' => '翻译组模型',
        'MySQL 的 极简' => 'MySQL 的极简',
        '的 极简博客系统' => '的极简博客系统',
        '后台 → 密钥管理 填' => '多语 → AI 设置 填',
    ];
    $clean = static function (string $v) use ($map): string {
        foreach ($map as $a => $b) { if (strpos($v, $a) !== false) { $v = str_replace($a, $b, $v); } }
        return $v;
    };

    $hitOpt = 0;
    foreach (Db::rows('SELECT name, value FROM ' . Db::t('options')) as $o) {
        $v = (string)$o['value'];
        if (!preg_match('/Typecho|Drupal|WordPress 式|密钥管理/', $v)) { continue; }
        $nv = $clean($v);
        if ($nv !== $v) {
            Db::run('UPDATE ' . Db::t('options') . ' SET value=? WHERE name=?', [$nv, $o['name']]);
            $hitOpt++;
        }
    }

    $hitPage = 0;
    foreach (Db::rows('SELECT id, content FROM ' . Db::t('contents') . " WHERE type='page' AND content LIKE '%由安装向导自动创建%'") as $p) {
        $c = (string)$p['content'];
        $nc = $clean($c);
        if ($nc !== $c) {
            Db::run('UPDATE ' . Db::t('contents') . ' SET content=? WHERE id=?', [$nc, (int)$p['id']]);
            $hitPage++;
        }
    }
    Cache::flush('pages');
    Cache::flush('blocks');
    Cache::flush('misc');
    echo '  对外文案清理：options ' . $hitOpt . ' 项 / 示例页 ' . $hitPage . " 处\n";
};
