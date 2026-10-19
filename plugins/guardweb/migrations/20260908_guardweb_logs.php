<?php
declare(strict_types=1);
// 迁移（幂等）：访客防线命中日志表
$t = Db::t('guard_logs');
$cols = Db::rows('SHOW TABLES LIKE "' . $t . '"');
if (!$cols) {
    Db::run("CREATE TABLE {$t} (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      day DATE NOT NULL,
      ip VARCHAR(64) NOT NULL DEFAULT '',
      ua VARCHAR(255) NOT NULL DEFAULT '',
      path VARCHAR(255) NOT NULL DEFAULT '',
      reason VARCHAR(24) NOT NULL DEFAULT '' COMMENT 'ai_bot|scraper|no_ua|rate',
      n INT NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL DEFAULT NULL,
      UNIQUE KEY uk_gl_day (day, ip, reason),
      KEY idx_gl_day (day)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
// 默认关闭（站长后台手动开启，开箱不影响任何流量）
if (get_option('guardweb.cfg', null) === null) {
    set_option('guardweb.cfg', json_encode([
        'on' => '0', 'mode' => 'block', 'robots' => '1', 'rate' => '0',
        'rate_n' => '120', 'rate_win' => '60', 'log' => '1', 'trust_cf' => '1',
        'allow_ips' => '', 'notice' => 'Access denied by guard.',
    ], JSON_UNESCAPED_UNICODE));
}
