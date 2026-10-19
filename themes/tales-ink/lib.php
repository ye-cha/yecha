<?php
/**
 * Tales 主题共享库：八大类色板、分类取数、拼音首字母、著录卡数据
 * 用法：在视图中 require_once __DIR__ . '/../lib.php';（从 views/ 下）
 */

/** 八大国色（分类 slug → 颜色） */
function tales_palette(): array
{
    return [
        'myths'      => '#2b5d8a', // 石青
        'legends'    => '#5a4a80', // 黛紫
        'wonder'     => '#c2952c', // 藤黄
        'everyday'   => '#c8402f', // 朱砂
        'fables'     => '#2f7d5f', // 松绿
        'zhiguai'    => '#4a5568', // 松烟
        'ethnic'     => '#a8642a', // 赭石
        'new-voices' => '#b03a5b', // 胭脂
    ];
}

/** 分类色（未知 slug 回落强调色） */
function tales_color(string $slug): string
{
    $p = tales_palette();
    return $p[$slug] ?? 'var(--acc)';
}

/** 八大类（按 order；含 zh/en 名、slug、篇数） */
function tales_cats(bool $en = false): array
{
    $rows = Db::rows('SELECT slug, name, name_en, count FROM ' . Db::t('metas')
        . " WHERE type='category' ORDER BY `order` ASC, id ASC");
    $out = [];
    foreach ($rows as $r) {
        $slug = (string)$r['slug'];
        $out[] = [
            'slug'  => $slug,
            'name'  => ($en && trim((string)$r['name_en']) !== '') ? (string)$r['name_en'] : (string)$r['name'],
            'name_zh' => (string)$r['name'],
            'count' => (int)$r['count'],
            'color' => tales_color($slug),
        ];
    }
    return $out;
}

/** 某篇 story 的分类（第一条分类关系） */
function tales_cat_of(int $cid): ?array
{
    static $cache = [];
    if (array_key_exists($cid, $cache)) { return $cache[$cid]; }
    $r = Db::rows('SELECT m.slug, m.name, m.name_en FROM ' . Db::t('relationships') . ' r '
        . 'JOIN ' . Db::t('metas') . " m ON m.id=r.meta_id AND m.type='category' WHERE r.content_id=? LIMIT 1", [$cid]);
    if (!$r) { return $cache[$cid] = null; }
    $slug = (string)$r[0]['slug'];
    return $cache[$cid] = [
        'slug' => $slug, 'name' => (string)$r[0]['name'],
        'name_en' => (string)$r[0]['name_en'], 'color' => tales_color($slug),
    ];
}

/** 标题目拼音首字母（无外部库：用常见字表 + 边界回退到 '#'） */
function tales_initial(string $title): string
{
    $t = trim($title);
    if ($t === '') { return '#'; }
    $ch = mb_substr($t, 0, 1);
    static $map = null;
    if ($map === null) {
        $map = [
            'A' => '阿哀爱安按暗昂', 'B' => '八巴白百摆败拜班版半伴帮包宝保报抱悲北被本比彼必毕边变便遍标别冰并波伯驳补不布步',
            'C' => '才材财嫦采彩参残苍藏曹草册侧测层插查茶差柴长常场唱超朝潮车彻沉陈称成诚城乘程吃池迟持赤充冲虫仇丑出初除楚处川穿传船窗创吹春纯词慈此刺从粗促崔村存错',
            'D' => '答达打董大呆带代待单担但淡当党刀导到倒道得德的灯等低敌底地弟帝第点电殿刁雕吊跌丁顶定丢东冬懂动都斗豆独读杜肚度端短断对多夺',
            'E' => '额恶饿恩儿而尔耳二', 'F' => '发法帆番凡反返犯饭方房防访放飞非肥废费分坟粉奋愤丰风封疯峰锋冯凤佛否夫伏扶服浮福抚府父付妇负附复副富',
            'G' => '该改盖干甘刚钢高告哥歌割革格个各给根跟更工弓公功攻供宫恭共贡勾姑孤古谷股骨鼓固故顾瓜刮寡怪关观官冠惯光广归龟贵桂跪滚锅国果过',
            'H' => '哈孩海害含寒喊汉汗航好号喝合何和河荷核贺黑恨红洪猴厚后呼狐胡湖虎互户花华划画化怀坏欢还环缓换荒皇黄谎灰回悔会惠昏婚活火货获',
            'J' => '击机鸡积姬基激及吉即极急疾集几己挤计记纪技忌季既济继寄加夹佳家嘉甲假嫁尖奸坚间肩监拣捡简见建剑荐贱江姜将讲交骄胶角脚叫教阶接街节结解姐介戒借今金津筋仅紧近进晋禁京经惊精井景警净竟竞敬静九久酒旧救就居局举巨句拒具俱剧据聚卷决绝军君',
            'K' => '卡开凯看康抗考靠科壳可克刻客课肯空孔口哭苦库快宽狂况亏困扩',
            'L' => '拉来兰拦蓝懒烂郎狼浪劳老乐雷累冷离梨黎礼李里理力历立利例丽连怜莲联脸练良凉梁粮两亮量辽了料列烈猎林临邻吝灵铃岭令刘流留六龙笼楼漏露炉鲁陆路乱论罗萝落',
            'M' => '妈麻马埋买卖满慢忙猫毛矛茅冒贸么没眉梅媒每美妹门闷蒙孟梦弥迷米密绵面苗妙庙灭民敏名明鸣命摸摩磨末陌莫墨谋母亩木目牧墓',
            'N' => '拿哪那纳乃奶男南难囊脑闹内能尼泥你年念娘鸟尿捏您宁牛农弄奴女暖虐',
            'O' => '哦欧偶',
            'P' => '爬怕拍排牌派攀盘判叛旁胖抛跑泡陪配喷朋蓬碰批披皮疲匹屁偏片骗飘票贫品平评苹凭泼婆迫破铺葡普',
            'Q' => '七妻期欺齐其奇骑棋旗乞岂起气弃器恰千迁牵铅谦前钱浅遣欠枪强墙抢悄桥巧切且怯窃亲秦琴勤青轻倾清情晴请庆穷秋求球区曲取娶去全权泉拳犬劝缺却鹊群',
            'R' => '然染让饶绕惹热人仁忍认任日容荣肉如儒乳入软若弱',
            'S' => '撒洒三散桑扫色森僧杀沙纱傻山删闪善伤商赏上尚烧少绍舌蛇舍设社射申伸身深神审甚慎生声甥绳省圣胜盛尸失师诗施十石时识实拾食史使始士氏示世市事侍势视试是适室逝收手守首寿受兽书叔舒疏输熟暑鼠属术树竖数刷衰双霜谁水税顺说司私思斯死四寺似松宋送诵搜苏俗诉素速酸算虽随岁孙损缩所锁',
            'T' => '他她它塔踏台抬太态贪摊坛谈弹坦叹汤唐堂塘糖躺逃桃陶讨套特疼提题蹄体替天田甜填挑条贴铁听亭庭停挺通同铜童统痛偷头投透突图徒涂土吐兔团推腿退吞托拖脱驼',
            'U' => '', 'V' => '',
            'W' => '挖娃瓦歪外弯完玩顽晚碗万王网往忘望危威微为围唯尾委未位味畏胃谓温文闻问我卧握乌污屋无五午伍武舞勿物误雾',
            'X' => '夕西吸刑希昔析息惜稀溪熄膝习席洗喜系细戏虾瞎下夏仙先纤闲贤弦显险县现限线宪陷相香乡想向项象像消萧销小孝笑些歇鞋写泄谢心辛欣新信星兴凶兄雄熊休修羞朽秀袖须虚需徐许序叙畜宣玄悬选靴学雪寻巡询训',
            'Y' => '压押鸦鸭牙芽崖哑雅亚烟淹延严言岩沿炎研盐颜眼演宴雁央羊阳杨洋仰养样妖腰邀尧摇遥咬药要爷也冶野业叶夜一衣医依仪移遗疑以已蚁倚义忆艺议亦异役意因阴音银引饮隐印应英樱迎盈蝇赢影硬庸永勇用优忧幽尤由犹油游友有又右幼于余鱼渔愚舆与宇羽雨语玉育狱浴预域欲遇御寓愈元员园原圆援缘源远怨院愿约月岳越云匀允运',
            'Z' => '杂灾再在咱攒暂赞脏遭早枣灶造则责怎增赠扎渣眨炸摘宅窄债沾粘展占战张章涨丈帐招昭找召照赵折哲者这贞针珍真诊枕阵振镇争征挣睁蒸整正证郑政之支只汁芝知织直侄值职植止只旨纸指至志制治致秩智置中忠终钟种众重舟周州洲粥轴昼皱朱珠猪竹逐主煮嘱助住注驻祝抓专转赚庄装壮状追准捉桌琢资子自字宗总纵横走奏租足族祖阻组钻嘴最罪尊昨左作坐座做',
        ];
    }
    foreach ($map as $letter => $chars) {
        if ($chars !== '' && mb_strpos($chars, $ch) !== false) { return $letter; }
    }
    // 拼音字母/数字开头
    if (preg_match('/^[A-Za-z]/', $ch)) { return strtoupper($ch); }
    if (preg_match('/^[0-9]/', $ch)) { return '#'; }
    return '#';
}


/** hero 分类字：中文＝中文名首字；英文＝专属字母（避免 Everyday/Ethnic 撞 E 之类） */
function tales_mono(string $slug, bool $en, string $nameZh, string $nameEn): string
{
    if (!$en) { return mb_substr($nameZh, 0, 1); }
    static $map = [
        'myths'      => 'M',
        'legends'    => 'L',
        'wonder'     => 'W',
        'everyday'   => 'E',
        'fables'     => 'F',
        'zhiguai'    => 'Z',
        'ethnic'     => 'R',
        'new-voices' => 'N',
    ];
    if (isset($map[$slug])) { return $map[$slug]; }
    $src = trim($nameEn) !== '' ? $nameEn : $nameZh;
    return strtoupper(mb_substr($src, 0, 1));
}
