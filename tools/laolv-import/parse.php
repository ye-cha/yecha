<?php
declare(strict_types=1);
/**
 * laolv 解析器：raw/*.html → posts.json + images.tsv
 * 提取：标题 / 日期(meta 首个 YYYY-MM-DD) / 分类(a.cat) / 标签(div.post-tags a) / #article-content
 * HTML→Markdown 转换（DOM），收集正文图片（/usr/uploads 等相对路径，转绝对源址留待本地化）
 * 用法：docker compose exec -T php php tools/laolv-import/parse.php
 */
$ROOT = dirname(__DIR__, 2);           // blog 根（挂载 /var/www/html）
$dir = $ROOT . '/data/laolv';
$rawDir = $dir . '/raw';
$base = 'https://laolv.org';

function xpath_text(DOMXPath $xp, string $query, ?DOMElement $ctx = null): string
{
    $node = $xp->query($query, $ctx)->item(0);
    return $node === null ? '' : trim($node->textContent);
}

/** 块级/行内 → Markdown（子集，宽容处理未知标签） */
function node2md(DOMNode $node, bool $block = false): string
{
    $out = '';
    foreach (iterator_to_array($node->childNodes) as $ch) {
        if ($ch instanceof DOMText) { $out .= trim($ch->textContent, " \t") === '' ? '' : $ch->textContent; continue; }
        if (!$ch instanceof DOMElement) { continue; }
        $tag = strtolower($ch->tagName);
        $inner = fn() => node2md($ch);
        switch ($tag) {
            case 'script': case 'style': case 'noscript': case 'iframe': break;
            case 'p': $t = trim($inner()); if ($t !== '') { $out .= $t . "\n\n"; } break;
            case 'h1': $out .= '# ' . trim($inner()) . "\n\n"; break;
            case 'h2': $out .= '## ' . trim($inner()) . "\n\n"; break;
            case 'h3': $out .= '### ' . trim($inner()) . "\n\n"; break;
            case 'h4': $out .= '#### ' . trim($inner()) . "\n\n"; break;
            case 'h5': $out .= '##### ' . trim($inner()) . "\n\n"; break;
            case 'h6': $out .= '###### ' . trim($inner()) . "\n\n"; break;
            case 'br': $out .= "  \n"; break;
            case 'hr': $out .= "---\n\n"; break;
            case 'ul': foreach ($ch->getElementsByTagName('li') as $li) { $out .= '- ' . trim(node2md($li)) . "\n"; } $out .= "\n"; break;
            case 'ol': foreach ($ch->getElementsByTagName('li') as $li) { $out .= '1. ' . trim(node2md($li)) . "\n"; } $out .= "\n"; break;
            case 'li': break; // 由父级处理
            case 'blockquote': $out .= '> ' . trim(str_replace("\n", "\n> ", trim($inner()))) . "\n\n"; break;
            case 'pre': case 'code': {
                $code = $ch->textContent;
                $tagFence = strtolower($tag) === 'pre' ? true : false;
                if ($tagFence) { $out .= "```\n" . rtrim($code) . "\n```\n\n"; } else { $out .= '`' . $code . '`'; }
                break;
            }
            case 'img': {
                $src = $ch->getAttribute('src');
                $alt = $ch->getAttribute('alt');
                if ($src !== '') { $out .= '![' . $alt . '](' . $src . ')'; }
                break;
            }
            case 'a': {
                $href = $ch->getAttribute('href');
                $text = trim($inner());
                $out .= $text !== '' ? '[' . $text . '](' . ($href === '' ? '#' : $href) . ')' : ($href !== '' ? $href : '');
                break;
            }
            case 'strong': case 'b': { $t = trim($inner()); if ($t !== '') { $out .= '**' . $t . '**'; } break; }
            case 'em': case 'i': { $t = trim($inner()); if ($t !== '') { $out .= '*' . $t . '*'; } break; }
            case 'del': case 's': case 'strike': { $t = trim($inner()); if ($t !== '') { $out .= '~~' . $t . '~~'; } break; }
            case 'table': {
                $rows = [];
                foreach ($ch->getElementsByTagName('tr') as $tr) {
                    $cells = [];
                    foreach ($tr->childNodes as $td) {
                        if ($td instanceof DOMElement && in_array(strtolower($td->tagName), ['td', 'th'], true)) {
                            $cells[] = trim(node2md($td));
                        }
                    }
                    $rows[] = '| ' . implode(' | ', $cells) . ' |';
                }
                if ($rows) { $out .= implode("\n", $rows) . "\n\n"; }
                break;
            }
            default:
                $out .= $inner();
        }
    }
    // 连续空行收敛
    if ($block) { $out = preg_replace('/\n{3,}/', "\n\n", $out); }
    return $out;
}

$manifest = file($dir . '/manifest.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$posts = [];
$images = [];   // url -> dest-rel（后续下载）
$imgsSeen = [];
$stats = ['files' => 0, 'parsed' => 0, 'fail' => 0];

foreach ($manifest as $line) {
    [$slug, $url, $lastmod] = array_pad(explode("\t", $line), 3, '');
    $htmlFile = $rawDir . '/' . $slug . '.html';
    if (!is_file($htmlFile)) { continue; }
    $stats['files']++;
    $html = file_get_contents($htmlFile);
    if ($html === false) { $stats['fail']++; continue; }

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);

    $title = $xp->query('//article//h1')->item(0);
    $title = $title !== null ? trim($title->textContent) : '';
    $meta = $xp->query('//article//div[contains(@class,"post-meta")]')->item(0);
    $date = '';
    $views = 0;
    if ($meta !== null) {
        $metaText = $meta->textContent;
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $metaText, $m)) { $date = $m[1] . '-' . $m[2] . '-' . $m[3]; }
        if (preg_match('/(\d+)\s*阅读/', $metaText, $vm)) { $views = (int)$vm[1]; }
    }
    if ($date === '') { $date = $lastmod; }

    $catSlug = ''; $catName = '';
    if ($meta !== null) {
        $a = $xp->query('.//a[contains(@class,"cat")]', $meta)->item(0);
        if ($a !== null) {
            if (preg_match('#/category/([A-Za-z0-9\-_]+)#', $a->getAttribute('href'), $cm)) { $catSlug = $cm[1]; }
            $catName = trim($a->textContent);
        }
    }

    $tags = [];
    $tagBox = $xp->query('//article//div[contains(@class,"post-tags")]')->item(0);
    if ($tagBox !== null) {
        foreach ($xp->query('.//a', $tagBox) as $a) {
            $name = trim($a->textContent);
            if ($name !== '') { $tags[] = ltrim($name, '#'); }
        }
    }
    $tags = array_values(array_unique(array_filter($tags)));

    $contentNode = $xp->query('//*[@id="article-content"]')->item(0);
    if ($contentNode === null) { $stats['fail']++; continue; }
    $md = node2md($contentNode, true);
    $md = preg_replace('/[ \t]+\n/', "\n", $md) ?? $md;

    // 收集图片
    $postImgs = [];
    foreach ($xp->query('.//img', $contentNode) as $img) {
        $src = trim($img->getAttribute('src'));
        if ($src === '') { continue; }
        if (str_starts_with($src, '/')) { $src = $base . $src; }
        $clean = preg_replace('/^https?:\/\//', '', $src);
        $parsed = parse_url('https://' . $clean);
        $path = $parsed['path'] ?? '';
        if ($path === '' || preg_match('~(\.(png|jpe?g|gif|webp|svg)|/usr/uploads/)~i', $path) !== 1) { continue; }
        $destRel = 'laolv' . preg_replace('#^/usr/uploads#', '', $path);
        $destRel = ltrim($destRel, '/');
        if (!isset($imgsSeen[$src])) {
            $imgsSeen[$src] = true;
            $images[$src] = $destRel;
        }
        // 正文内把相对图片路径改成本地化后的相对地址（uploads 外链由渲染统一处理）
        $postImgs[] = ['src' => $src, 'dest' => '/uploads/' . $destRel];
    }
    foreach ($postImgs as $im) {
        $md = str_replace('](' . $im['src'] . ')', '](' . $im['dest'] . ')', $md);
        $md = str_replace('](' . str_replace($base, '', $im['src']) . ')', '](' . $im['dest'] . ')', $md);
    }

    if ($title === '' || $md === '') { $stats['fail']++; continue; }
    $posts[] = [
        'slug' => $slug, 'url' => $url, 'date' => $date, 'title' => $title,
        'cat_slug' => $catSlug, 'cat_name' => $catName, 'tags' => $tags, 'content' => $md,
        'views' => $views,
    ];
    $stats['parsed']++;
}
file_put_contents($dir . '/posts.json', json_encode($posts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$imgLines = [];
foreach ($images as $src => $dest) { $imgLines[] = $src . "\t" . $dest; }
file_put_contents($dir . '/images.tsv', implode("\n", $imgLines));
echo json_encode($stats + ['images' => count($images)], JSON_UNESCAPED_UNICODE) . "\n";
