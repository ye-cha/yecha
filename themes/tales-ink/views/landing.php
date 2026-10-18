<?php
/**
 * Tales · 首页门户（landing）· v2：八大类
 * Hero（大标题 + 竖排副题 + 八个可点分类字 + 数据条）→ 八大类色块 → 最新收录（按分类着色）→ 我们的做法 → 订阅
 */
require_once __DIR__ . '/../lib.php';

$GLOBALS['YC_HOME_HERO'] = true;
$GLOBALS['YC_NO_SIDE']   = true;

$curLangShort = Lang::short();
$L   = ($curLangShort === 'en');
$def = Poly::defaultLang();
$lp  = (Poly::on() && $curLangShort !== $def) ? '/' . $curLangShort : '';
$U   = $L ? $lp : '';
$T_  = function (string $zh, string $en) use ($L): string { return $L ? $en : $zh; };

/* 八大类 */
$cats = tales_cats($L);
$totalStories = 0;
foreach ($cats as $c) { $totalStories += $c['count']; }

/* 每类一句话（主题内定调） */
$blurb = [
    'myths'      => ['创世、洪水、日月与英雄——上古的宇宙解释。', 'Creation, flood, sun and moon: the ancient cosmos explained.'],
    'legends'    => ['附着于具体人物、地方与史事的讲述。', 'Stories anchored to a person, a place, an event.'],
    'wonder'     => ['宝物、变身、异境与动物丈夫／妻子。', 'Treasures, transformations, other worlds.'],
    'everyday'   => ['巧女、长工与地主、贪心与报应。', 'Clever wives, hired hands, greed and its price.'],
    'fables'     => ['短小有锋刃：动物寓言与文人笑话。', 'Short and sharp: animal fables and scholars\' jokes.'],
    'zhiguai'    => ['《搜神记》《聊斋》一路的文人怪谈。', 'Tales of the strange, from the Sou Shen Ji to Liaozhai.'],
    'ethnic'     => ['创世史诗、英雄史诗与地方异文。', 'Creation epics, heroic epics and local variants.'],
    'new-voices' => ['讲述人访谈、地方口述与读者来稿——我们的新采集。', 'Interviews, oral accounts and reader contributions.'],
];
$catDesc = function (string $slug) use ($blurb, $L): string {
    $b = $blurb[$slug] ?? ['', ''];
    return $L ? $b[1] : $b[0];
};
$catName = function (array $c) use ($L): string {
    return ($L && trim((string)$c['name_en']) !== '') ? (string)$c['name_en'] : (string)$c['name'];
};

/* 最新 6 则（含英文标题） */
$latest = Db::rows(
    'SELECT c.id, c.slug, c.title, c.excerpt, COALESCE(c.published_at, c.created_at) AS dt FROM ' . Db::t('contents') . ' c '
    . "WHERE c.type='post' AND c.status='publish' AND c.deleted_at IS NULL "
    . 'ORDER BY COALESCE(c.published_at, c.created_at) DESC, c.id DESC LIMIT 6'
);
$latestEn = [];
if ($latest && $L) {
    $ids = array_map(static fn(array $r): int => (int)$r['id'], $latest);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    foreach (Db::rows('SELECT group_id, title FROM ' . Db::t('poly_contents') . " WHERE lang='en' AND status='publish' AND group_id IN ($in)", $ids) as $er) {
        $latestEn[(int)$er['group_id']] = (string)$er['title'];
    }
}
?>
<div class="wrap tales-home">

  <section class="hero">
    <div class="hero-grid">
      <div>
        <div class="kicker"><?= e($T_('中国民间故事 · 神话传说 · 志怪笔记 · 双语', 'CHINESE FOLK TALES · MYTHS · LEGENDS · BILINGUAL')) ?></div>
        <h1><?= e($T_('讲给世界听的', 'Told for the world')) ?><br><em><?= e($T_('中国故事', 'Chinese stories')) ?></em></h1>
        <p class="sub"><?= e($T_(
            '先以中文好好讲一遍，再译成世界的语言。八大类收全，每则标注出处、流传地与讲述人，口传与文献分开。',
            'Told properly in Chinese first, then carried into other languages. Eight categories, every story sourced.'
        )) ?></p>
        <form class="tales-search hero-search" method="get" action="<?= e($U . '/search') ?>" role="search">
          <input type="search" name="q" placeholder="<?= e($T_('搜索故事、母题或讲述地…', 'Search stories, motifs or places…')) ?>" autocomplete="off" aria-label="<?= e($T_('搜索', 'Search')) ?>">
          <button type="submit"><?= e($T_('搜索', 'Search')) ?></button>
        </form>
        <div class="cta">
          <a class="btn btn-acc" href="<?= e($U . '/category/') ?>"><?= e($T_('按类别浏览 →', 'Browse by category →')) ?></a>
          <?php if ($latest): ?>
          <a class="btn" href="<?= e($U . '/' . rawurlencode((string)$latest[0]['slug']) . '.html') ?>"><?= e($T_('读最新一则', 'Read the latest')) ?></a>
          <?php endif; ?>
        </div>
      </div>
      <div class="hero-side">
        <div class="hero-v"><?= e($T_('民间 · 神话 · 与还在讲述它们的人', 'FOLK · MYTH · AND THOSE STILL TELLING')) ?></div>
        <!-- 八个分类字：可点，颜色即分类色（稳定，不随内容更新） -->
        <div class="hero-tower" aria-label="<?= e($T_('八大类', 'Eight categories')) ?>">
          <?php foreach ($cats as $c):
              $href = $U . '/category/' . rawurlencode($c['slug']) . '/';
          ?>
          <a href="<?= e($href) ?>" title="<?= e($catName($c)) ?>" style="background:<?= e($c['color']) ?>"><?= e(tales_mono($c['slug'], $L, $c['name_zh'], $c['name'])) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="stats">
      <div><b><?= count($cats) ?></b><span><?= e($T_('个大类', 'CATEGORIES')) ?></span></div>
      <div><b><?= (int)$totalStories ?></b><span><?= e($T_('则故事', 'STORIES')) ?></span></div>
      <div><b>ZH · EN</b><span><?= e($T_('双语版本', 'BILINGUAL')) ?></span></div>
      <div><b><?= e($T_('每周', 'WEEKLY')) ?></b><span><?= e($T_('更新一则', 'ONE A WEEK')) ?></span></div>
    </div>
  </section>

  <section class="sec">
    <div class="sec-hd">
      <h2><?= e($T_('中国故事的版图', 'The map of Chinese story')) ?></h2>
      <p class="lead"><?= e($T_('八类，各有其色；颜色即分类，全站一致。', 'Eight kinds, each with its own colour — used consistently site-wide.')) ?></p>
      <a class="more" href="<?= e($U . '/pinyin/') ?>"><?= e($T_('全部索引 →', 'Full index →')) ?></a>
    </div>
    <div class="cat8">
      <?php foreach ($cats as $i => $c): ?>
      <a href="<?= e($U . '/category/' . rawurlencode($c['slug']) . '/') ?>" style="background:<?= e($c['color']) ?>">
        <span class="no"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
        <h3><?= e($c['name']) ?></h3>
        <p><?= e($catDesc($c['slug'])) ?></p>
        <span class="n"><?= $c['count'] > 0 ? (int)$c['count'] . ' ' . e($T_('则', 'stories')) : e($T_('征稿中', 'open')) ?></span>
        <span class="go">→</span>
      </a>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="sec">
    <div class="sec-hd">
      <h2><?= e($T_('最新收录', 'Recently added')) ?></h2>
      <p class="lead"><?= e($T_('字牌颜色＝所属大类。', 'The plate colour marks its category.')) ?></p>
      <a class="more" href="<?= e($U . '/category/') ?>"><?= e($T_('全部 →', 'All →')) ?></a>
    </div>
    <div class="atlas">
      <?php if ($latest): foreach ($latest as $i => $p):
          $cid = (int)$p['id'];
          $cat = tales_cat_of($cid);
          $ttl = ($L && isset($latestEn[$cid])) ? $latestEn[$cid] : (string)$p['title'];
          $ex  = Str::excerpt((string)($p['excerpt'] ?? ''), 90);
      ?>
      <a class="cell" href="<?= e($U . '/' . rawurlencode((string)$p['slug']) . '.html') ?>" style="--c:<?= e($cat['color'] ?? 'var(--acc)') ?>">
        <span class="idx"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
        <span class="plate"><b><?= e(mb_substr($ttl, 0, 1)) ?></b></span>
        <h3><?= e($ttl) ?></h3>
        <p><?= e($ex) ?></p>
        <span class="meta"><?php if ($cat): ?><em><?= e($catName(['name' => $cat['name'], 'name_en' => $cat['name_en']])) ?></em><i>·</i><?php endif; ?><?= e(substr((string)$p['dt'], 0, 10)) ?></span>
      </a>
      <?php endforeach; else: ?>
      <div class="cell"><h3><?= e($T_('新故事正在路上', 'Fresh stories on the way')) ?></h3><p><?= e($T_('先按类别逛逛。', 'Browse the categories first.')) ?></p></div>
      <?php endif; ?>
    </div>
  </section>

  <section class="sec">
    <div class="sec-hd"><h2><?= e($T_('我们的做法', 'How we work')) ?></h2></div>
    <div class="promise">
      <div><h4><s></s><?= e($T_('两源核验', 'Two sources')) ?></h4><p><?= e($T_('每则故事至少两个来源对照；冲突处如实写出，不替读者抹平。', 'Checked against two sources; conflicts stated, not smoothed over.')) ?></p></div>
      <div><h4><s></s><?= e($T_('著录到人', 'Attributed')) ?></h4><p><?= e($T_('流传地 · 讲述人 · 文献出处 · 版本异文，标注在文首。', 'Place, teller, source text and variants are marked.')) ?></p></div>
      <div><h4><s></s><?= e($T_('不猎奇，不简化', 'No exoticism')) ?></h4><p><?= e($T_('不把中国故事压成符号。翻译的不只是语言，更是语境。', 'We translate context, not just language.')) ?></p></div>
    </div>
  </section>
</div>

<?php if (class_exists('Plugins') && Plugins::isOn('subscribe')): ?>
<section class="subband" id="sub">
  <div class="band-in">
    <div>
      <h3><?= e($T_('每周一则故事', 'One story a week')) ?></h3>
      <p><?= e($T_('发到邮箱。无噪音，无立场。随时退订。', 'In your inbox. No noise. Unsubscribe anytime.')) ?></p>
    </div>
    <form action="<?= e($U . '/subscribe.html') ?>" method="post">
      <input type="email" name="email" required placeholder="<?= e($T_('你的邮箱', 'Your email')) ?>" aria-label="<?= e($T_('你的邮箱', 'Your email')) ?>">
      <button type="submit"><?= e($T_('订阅', 'Subscribe')) ?></button>
    </form>
  </div>
</section>
<?php endif; ?>
