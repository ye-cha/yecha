<?php
declare(strict_types=1);
/**
 * 图片优化后台页：/admin/?s=plug_imgopt
 * 展示外部图片残留统计 + 编码器探测状态，提供「开始优化」按钮触发本地化 + WebP + 缩略图。
 * POST 由宿主 admin 段统一 Csrf::check()，本页表单只需 \Csrf::field()。
 */

function yc_imgopt_admin(): string
{
    $base = '/admin/?s=plug_imgopt';
    $msg = '';
    $stat = null;

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['act'] ?? '') === 'run') {
        // 宿主已 Csrf::check()；这里直接执行
        $t0 = microtime(true);
        ImgOpt::run($stat);
        $sec = round(microtime(true) - $t0, 1);
        $msg = 'ok::优化完成，用时 ' . $sec . 's —— 商品主图 ' . $stat['goods'] . ' / 图册 ' . $stat['goods_imgs']
            . ' / 品牌 ' . $stat['brand'] . ' / 分类 ' . $stat['cat'] . ' / 评价 ' . $stat['review']
            . '；失败 ' . $stat['failed'] . '；本地图片目录约 ' . human_size($stat['bytes']);
    }

    $rem = ImgOpt::remainingExternal();
    $totalExt = $rem['goods_cover'] + $rem['goods_gallery'] + $rem['brand'] + $rem['cat'] + $rem['review'];
    $cw = ImgOpt::cwebpPath();
    $g2w = ImgOpt::gif2webpPath();
    $gd = function_exists('imagewebp') ? '可用' : '不可用';
    $enc = ($cw ? 'cwebp(' . basename($cw) . ')' : '无') . ' / gif2webp(' . ($g2w ? basename($g2w) : '无') . ') / GD ' . $gd;

    $toolOk = ($cw || $g2w || function_exists('imagewebp'));

    $h = '';
    $h .= '<div class="card"><h3>图片优化（ImgOpt）</h3>';
    $h .= '<p class="muted">把商城外部图片下载到本地 <code>uploads/mall</code>，统一转 WebP，并为商品卡生成 <code>_t.webp</code> 缩略图。列表/卡片加载缩略图，详情主图加载原图。</p>';

    $h .= '<table class="mla-tbl" style="width:auto">';
    $h .= '<tr><th>编码器</th><td>' . e($enc) . ($toolOk ? ' <span class="ok">✓</span>' : ' <span class="err">✗ 无可用编码器</span>') . '</td></tr>';
    $h .= '<tr><th>商品主图(外链)</th><td>' . $rem['goods_cover'] . '</td></tr>';
    $h .= '<tr><th>商品图册(含外链)</th><td>' . $rem['goods_gallery'] . '</td></tr>';
    $h .= '<tr><th>品牌 logo(外链)</th><td>' . $rem['brand'] . '</td></tr>';
    $h .= '<tr><th>分类 icon(外链)</th><td>' . $rem['cat'] . '</td></tr>';
    $h .= '<tr><th>评价图(含外链)</th><td>' . $rem['review'] . '</td></tr>';
    $h .= '<tr><th>外部图合计</th><td><b>' . $totalExt . '</b></td></tr>';
    $h .= '</table>';

    if ($totalExt > 0) {
        $h .= '<form method="post" action="' . $base . '" style="margin-top:12px">';
        $h .= \Csrf::field();
        $h .= '<input type="hidden" name="act" value="run">';
        $h .= '<button class="mla-btn" ' . ($toolOk ? '' : 'disabled') . '>开始优化（本地化 + WebP + 缩略图）</button>';
        $h .= '</form>';
    } else {
        $h .= '<p class="ok" style="margin-top:12px">所有商城图片均已本地化为 WebP，无需再优化。</p>';
    }
    $h .= '</div>';

    if ($msg !== '') {
        [$cls, $txt] = explode('::', $msg, 2);
        $h = '<div class="' . ($cls === 'ok' ? 'ok' : 'err') . '">' . e($txt) . '</div>' . $h;
    }
    return $h;
}

function human_size(int $bytes): string
{
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $n = (float)$bytes;
    while ($n >= 1024 && $i < count($u) - 1) { $n /= 1024; $i++; }
    return round($n, 1) . $u[$i];
}
