<?php
require dirname(__DIR__, 2) . '/config.inc.php';
require dirname(__DIR__, 2) . '/core/bootstrap.php';
$t = Db::t('poly_contents');
$has = Db::one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name='description'", [$t]);
if ((int)$has === 0) {
    Db::run("ALTER TABLE $t ADD COLUMN description VARCHAR(300) NOT NULL DEFAULT '' AFTER keywords");
    echo "added\n";
} else {
    echo "exists\n";
}
