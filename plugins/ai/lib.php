<?php
declare(strict_types=1);
/**
 * Ai：AI 能力插件核心（M1/M4）
 * - 服务商：SenseNova（默认）+ 智谱 GLM 备选，OpenAI 兼容 chat-completions 形态，Bearer API Key。
 * - 能力：chat() / translate() / kbSearch()（MySQL FULLTEXT ngram RAG 检索）/ 前台客服接口 / 调用日志。
 * - 路由：内核 case 'ai' 分发多级路径到 Ai::dispatch()（/ai/api/chat 为客服 JSON 接口）。
 * - 配置存 options（ai.*），后台「AI 能力」页维护；API Key 只落库、绝不回显。
 */
final class Ai
{
    public const OPT_PREFIX = 'ai.';

    /* ================= 敏感项：只走站上既有加密设施（★ 2026-10-04 明文泄露整改） =========
       全站长期规则：涉及密钥的一律【后台录入 + 加密存储】。故本项：
         · 录入通道 = 后台表单 → Ai::setCfg() → yc_opt_set()（core/helpers.php 既有设施）
         · 存储形态 = `enc:` + base64(iv12|tag16|ct)，AES-256-GCM，密钥由 config.inc.php 的
           SECRET 派生（与站上 ai.key / mail.pass / sms.key 同一套；**不自创第二套加密、不新建密钥文件**）
         · 对外输出（后台表单 / HTML / JSON API / 日志 / 报告）一律不出现明文
       本项为何要多一道"非 enc: 即拒绝"的判据：`yc_decrypt()` 有一句"非 enc: 前缀即原样返回"的
       **明文兼容直通** —— 若直接沿用它，则"有人又把明文写回库"会永远不被发现，等于默许明文。
       故：读到非 `enc:` 前缀（或解密为空）⇒ error_log 记一笔 + 拒绝使用（绝不把明文当 key 用）。 */
    public const SECRET_OPT = self::OPT_PREFIX . 'sensenova.key';

    /** 表名：ai_knowledge → ye_ai_knowledge（跟随宿主前缀机制） */
    public static function t(string $name): string
    {
        return Db::t('ai_' . $name);
    }

    /* ================= 配置 ================= */

    public static function cfg(string $key, string $default = ''): string
    {
        $name = self::OPT_PREFIX . $key;
        if ($name === self::SECRET_OPT) {
            return self::secretCfg($default);
        }
        return (string)get_option($name, $default);
    }

    /** 读敏感项：只认站上既有加密设施产出的 `enc:` 密文；空=未配置（合法态）；非密文/解不出=记日志+拒绝 */
    private static function secretCfg(string $default = ''): string
    {
        $raw = (string)get_option(self::SECRET_OPT, '');
        if ($raw === '') { return $default; }
        if (!str_starts_with($raw, 'enc:')) {
            error_log('[YeCha/AI] ' . self::SECRET_OPT . ' 非密文形态（检测到明文），已拒绝使用；请到后台「AI 能力 → 服务商设置」重新保存一次');
            return $default;
        }
        $plain = yc_decrypt($raw);   // 站上既有解密函数（密钥由 SECRET 派生）
        if ($plain === '') {
            error_log('[YeCha/AI] ' . self::SECRET_OPT . ' 解密失败（SECRET 变更或密文损坏），已拒绝使用');
            return $default;
        }
        return $plain;
    }

    public static function setCfg(string $key, string $value): void
    {
        $name = self::OPT_PREFIX . $key;
        if ($name === self::SECRET_OPT) {
            if ($value === '') { set_option($name, ''); return; }   // 后台显式填 '-' ⇒ 清除
            yc_opt_set($name, $value);   // ★ 站上既有设施：加密后落库（写侧也不落明文）
            return;
        }
        set_option($name, $value);
    }

    public static function provider(): string
    {
        return self::cfg('provider', 'sensenova');
    }

    public static function isConfigured(): bool
    {
        $p = self::provider();
        return self::cfg($p . '.key') !== '';
    }

    /** 当前服务商的接口地址（后台总览/日志需要展示，故为 public） */
    public static function endpoint(): string
    {
        $p = self::provider();
        return self::cfg($p . '.base', self::defaultBase($p));
    }

    /** 当前服务商的模型名（后台总览/日志需要展示，故为 public） */
    public static function model(): string
    {
        $p = self::provider();
        return self::cfg($p . '.model', self::defaultModel($p));
    }

    /** 各服务商的默认端点（后台表单、文档、默认值统一走这里，避免多处硬编码漂移） */
    public static function defaultBase(string $provider): string
    {
        return $provider === 'zhipu'
            ? 'https://open.bigmodel.cn/api/paas/v4/chat/completions'
            : 'https://token.sensenova.cn/v1/chat/completions';
    }

    /** 各服务商的默认模型 */
    public static function defaultModel(string $provider): string
    {
        return $provider === 'zhipu' ? 'glm-4-flash' : 'sensenova-6.8-flash-lite';
    }

    /* ================= 能力：chat / translate ================= */

    /**
     * 对话补全。$messages: [['role'=>'system|user|assistant','content'=>...], ...]
     * 返回 ['ok'=>bool, 'text'=>string, 'err'=>string, 'usage'=>['in'=>int,'out'=>int]]
     */
    public static function chat(array $messages, array $opts = []): array
    {
        $scene = (string)($opts['scene'] ?? 'chat');
        if (!self::isConfigured()) {
            return ['ok' => false, 'text' => '', 'err' => 'AI 未配置 API Key', 'usage' => ['in' => 0, 'out' => 0]];
        }
        $maxTokens = (int)($opts['max_tokens'] ?? 4096);
        $t0 = microtime(true);
        $res = ['ok' => false, 'text' => '', 'err' => '', 'usage' => ['in' => 0, 'out' => 0]];
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $body = [
                'model' => self::model(),
                'messages' => $messages,
                'max_tokens' => $maxTokens,
                'temperature' => (float)($opts['temperature'] ?? 0.7),
                'stream' => false,
            ];
            [$ok, $data, $err] = self::http(self::endpoint(), self::cfg(self::provider() . '.key'), $body);
            $res['err'] = $err;
            if (!$ok) { break; }
            $msg = (array)($data['choices'][0]['message'] ?? []);
            $text = trim((string)($msg['content'] ?? ''));
            $reason = trim((string)($msg['reasoning'] ?? ''));
            $finish = (string)($data['choices'][0]['finish_reason'] ?? '');
            $res['usage'] = [
                'in' => (int)($data['usage']['prompt_tokens'] ?? 0),
                'out' => (int)($data['usage']['completion_tokens'] ?? 0),
            ];
            if ($text !== '') { $res['ok'] = true; $res['text'] = $text; $res['err'] = ''; break; }
            /* 推理型模型（sensenova-6.8-flash-lite 等）会把额度全烧在 reasoning 上，此时 message 里只有
               reasoning 没有 content、finish_reason=length。**绝不能把思考过程当正文**（会把英文推理写进
               业务字段），正确做法是翻倍 max_tokens 重试一次；仍失败则明确报错。 */
            if ($attempt === 1 && $reason !== '' && $finish === 'length') { $maxTokens = min(16000, $maxTokens * 2); continue; }
            $res['err'] = $reason !== '' ? '模型只返回了思考内容（max_tokens 不足，已自动重试仍失败）' : '模型未返回正文';
            break;
        }
        $ms = (int)((microtime(true) - $t0) * 1000);
        self::logCall($scene, self::provider(), self::model(), $res['usage']['in'], $res['usage']['out'], $ms, $res['ok'], $res['err']);
        return $res;
    }

    /** 翻译：把 $text 译为目标语言 */
    public static function translate(string $text, string $toLang, string $scene = 'translate'): array
    {
        $sys = '你是专业翻译。把用户内容翻译成 ' . $toLang . '，只输出译文，不解释、不加前缀。保留专有名词与数字。';
        return self::chat([
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => $text],
        ], ['scene' => $scene, 'temperature' => 0.3]);
    }

    /** 统一 HTTP（JSON POST） */
    private static function http(string $url, string $key, array $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
            ],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno || !is_string($raw) || $raw === '') {
            return [false, [], '网络请求失败（errno=' . $errno . '）'];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [false, [], '响应非 JSON：' . mb_substr($raw, 0, 180)];
        }
        if (isset($data['error']) || isset($data['message']) && isset($data['code']) && !isset($data['choices'])) {
            $msg = is_array($data['error'] ?? null) ? (string)($data['error']['message'] ?? '') : (string)($data['message'] ?? json_encode($data['error'] ?? $data));
            return [false, [], 'API 错误：' . mb_substr($msg, 0, 180)];
        }
        return [true, $data, ''];
    }

    private static function logCall(string $scene, string $provider, string $model, int $in, int $out, int $ms, bool $ok, string $err): void
    {
        try {
            Db::insert('ai_log', [
                'scene' => mb_substr($scene, 0, 32), 'provider' => mb_substr($provider, 0, 32),
                'model' => mb_substr($model, 0, 64), 'in_tokens' => $in, 'out_tokens' => $out,
                'cost_ms' => $ms, 'ok' => $ok ? 1 : 0, 'err' => mb_substr($err, 0, 255),
            ]);
        } catch (Throwable $e) { /* 日志失败不阻塞主流程 */ }
    }

    /* ================= 知识库（RAG 检索） ================= */

    public static function kbAdd(string $title, string $content, string $tags = '', int $sort = 0): int
    {
        return Db::insert('ai_knowledge', [
            'title' => mb_substr($title, 0, 128), 'content' => $content, 'tags' => mb_substr($tags, 0, 255), 'sort' => $sort,
        ]);
    }

    public static function kbUpdate(int $id, string $title, string $content, string $tags = ''): void
    {
        Db::update('ai_knowledge', [
            'title' => mb_substr($title, 0, 128), 'content' => $content, 'tags' => mb_substr($tags, 0, 255),
        ], 'id = ?', [$id]);
    }

    public static function kbDelete(int $id): void
    {
        Db::update('ai_knowledge', ['is_delete_time' => time()], 'id = ?', [$id]);
    }

    /**
     * RAG 检索：FULLTEXT ngram 相关性优先，LIKE 兜底；返回可用语料拼接（供 system prompt 注入）。
     */
    public static function kbSearch(string $query, int $limit = 4): array
    {
        $q = trim($query);
        if ($q === '') { return []; }
        // ★ 剔除口语停用词后再检索。
        //   原因（实测）：ngram 会把「怎么」切成 token，而「怎么退货」这类口语提问里
        //   「怎么」与「退货」权重相同 → FULLTEXT 命中的全是靠「怎么」匹配的
        //   「古树茶怎么选」「口粮茶怎么选」，真正相关的「退换货政策说明」被挤掉，
        //   导致 AI 答「没有相关资料」。剔除后检索串只剩实词，相关性才正常。
        $q = self::stripStopwords($q);
        $t = self::t('knowledge');
        $rows = [];
        try {
            $rows = Db::rows(
                "SELECT id, title, content, MATCH(title, content) AGAINST(? IN NATURAL LANGUAGE MODE) AS score
                 FROM {$t} WHERE is_show = 1 AND is_delete_time = 0
                 AND MATCH(title, content) AGAINST(? IN NATURAL LANGUAGE MODE)
                 ORDER BY score DESC, sort DESC LIMIT " . max(1, min(10, $limit)),
                [$q, $q]
            );
        } catch (Throwable $e) { /* FULLTEXT 不可用时走 LIKE 兜底 */ }
        // ★ 相对阈值过滤：ngram 会把「怎么」这类通用词也切进去，导致
        //   「怎么退货」命中一堆靠「怎么」匹配的无关条目（score 0.0368 vs 0.0736），
        //   真正相关的「退换货政策说明」反被 top-N 挤掉 → AI 答「没有相关资料」。
        //   判据：保留 score >= 最高分 × 40% 的条目；全被滤掉则回退原结果。
        if ($rows && count($rows) > 1) {
            $max = 0.0;
            foreach ($rows as $r) { $max = max($max, (float)($r['score'] ?? 0)); }
            if ($max > 0) {
                $keep = [];
                foreach ($rows as $r) {
                    if ((float)($r['score'] ?? 0) >= $max * 0.4) { $keep[] = $r; }
                }
                if ($keep) { $rows = $keep; }
            }
        }
        if (!$rows) {
            // ★ 兜底改【分词】匹配：原实现拿整个查询串做 LIKE，
            //   中文里「怎么退货」作为整体子串在正文中几乎不可能存在（正文写的是「退换货」「退款」），
            //   所以必然 0 命中。改为按 2 字滑窗切词，任一片段命中即可。
            $clean = trim((string)preg_replace('/[\'"%\\\\\s]+/u', '', $q));
            $kw = mb_substr($clean, 0, 32);
            $terms = [];
            if (mb_strlen($clean) >= 2) {
                $n = mb_strlen($clean);
                for ($i = 0; $i + 2 <= $n; $i++) { $terms[] = mb_substr($clean, $i, 2); }
            } elseif ($kw !== '') {
                $terms[] = $kw;
            }
            $terms = array_values(array_unique($terms));
            if ($terms) {
                $w = []; $a = [];
                foreach ($terms as $tm) { $w[] = '(title LIKE ? OR content LIKE ?)'; $a[] = '%' . $tm . '%'; $a[] = '%' . $tm . '%'; }
                $rows = Db::rows(
                    "SELECT id, title, content FROM {$t} WHERE is_show = 1 AND is_delete_time = 0
                     AND (" . implode(' OR ', $w) . ") ORDER BY sort DESC LIMIT " . max(1, min(10, $limit)),
                    $a
                );
            }
            // ★ 二级兜底：单字匹配。
            //   中文里「退货」这个词在正文中可能并不存在（正文写的是「退换货」「退款」），
            //   ngram 与 2 字 LIKE 都会落空。退到单字即可命中（「退」能匹配「退换货」）。
            //   精度低于 2 字，故仅在上一级 0 命中时启用。
            if (!$rows && $kw !== '') {
                $chars = [];
                $n2 = mb_strlen($kw);
                for ($j = 0; $j < $n2; $j++) { $chars[] = mb_substr($kw, $j, 1); }
                $chars = array_values(array_unique($chars));
                $w2 = []; $a2 = [];
                foreach ($chars as $ch) { $w2[] = '(title LIKE ? OR content LIKE ?)'; $a2[] = '%' . $ch . '%'; $a2[] = '%' . $ch . '%'; }
                // 排序按相关性而非 sort：单字兜底命中面很宽，
                // 让「标题或正文里含完整 2 字词」的条目优先（如「退货」优先于只含「退」的）。
                $ord = [];
                $oArgs = [];
                foreach ($terms as $tm) {
                    $ord[] = '(title LIKE ? OR content LIKE ?)';
                    $oArgs[] = '%' . $tm . '%'; $oArgs[] = '%' . $tm . '%';
                }
                $orderSql = $ord ? ('(' . implode(' + ', array_map(fn($x) => "($x)", $ord)) . ') DESC, sort DESC') : 'sort DESC';
                $rows = Db::rows(
                    "SELECT id, title, content FROM {$t} WHERE is_show = 1 AND is_delete_time = 0
                     AND (" . implode(' OR ', $w2) . ") ORDER BY " . $orderSql . " LIMIT " . max(1, min(10, $limit)),
                    array_merge($oArgs, $a2)
                );
            }
        }
        return $rows;
    }

/** 口语停用词表（对检索无区分度，且会被 ngram 切成高权重 token 污染结果） */
    private const STOPWORDS = [
        '怎么', '什么', '哪些', '哪个', '可以', '请问', '你们', '我们', '这个', '那个',
        '一下', '有没有', '是否', '能否', '想要', '我想', '帮我', '介绍', '告诉', '需求',
        '的话', '吗', '呢', '吧', '啊', '的', '了', '是', '有', '和', '与', '或',
    ];

    /** 剔除停用词（保留原串若剔完为空，避免检索彻底失效） */
    public static function stripStopwords(string $q): string
    {
        $out = $q;
        foreach (self::STOPWORDS as $w) {
            $out = str_replace($w, ' ', $out);
        }
        $out = trim((string)preg_replace('/\s+/u', ' ', $out));
        return $out === '' ? $q : $out;
    }

    /** 检索结果拼成 system 上下文块（条数可由后台 ai.rag.limit 配置，默认 3） */
    public static function kbContext(string $query, int $limit = 0): string
    {
        if ($limit <= 0) { $limit = max(1, min(6, (int)self::cfg('rag.limit', '3'))); }
        $rows = self::kbSearch($query, $limit);
        if (!$rows) { return ''; }
        $per = max(200, min(1200, (int)self::cfg('rag.per', '600')));
        $parts = [];
        foreach ($rows as $r) {
            $parts[] = '【' . (string)$r['title'] . "】\n" . mb_substr((string)$r['content'], 0, $per);
        }
        return "以下是企业知识库中与问题可能相关的资料：\n\n" . implode("\n\n----\n\n", $parts);
    }

/* ================= ★ 导购增强（本次改造新增） ================= */

    /** 默认 system prompt：导购口吻（原默认是「企业官网客服」，商城场景不适用） */
    public static function defaultPrompt(): string
    {
        return '你是本站（茶叶商城）的导购助手，帮访客在站内选到合适的茶。规则：'
            . "\n" . '1. 只依据下面提供的知识库资料回答，绝不编造商品、价格、年份、树龄、产地。'
            . "\n" . '2. 不提及任何健康功效（减肥、降三高、降血压、治疗等），不暗示茶叶有药用价值。'
            . "\n" . '3. 不使用投资、收藏增值、升值保值类表述。'
            . "\n" . '4. 不使用「最好」「第一」「唯一」「最低价」等绝对化用语。'
            . "\n" . '5. 推荐商品时在该商品名后用锚点标记，格式为两个竖线包住的商品 ID，例如：中木易武正山 2015 ||goods:3373||。'
            . "\n" . '6. 锚点里的 ID 只能取自知识库资料中明确写出的商品 ID，不得自己编造。'
            . "\n" . '7. 价格可能变动，涉及价格时提示以商品页为准。'
            . "\n" . '8. 资料没有涉及的，直接说不知道，并建议留言或联系人工；不要猜。'
            . "\n" . '9. 用简洁中文，不要使用 Markdown 标记（如 ** 加粗、# 标题、表格）。'
            . "\n" . '10. 单次回答控制在 300 字以内，先给结论再给理由。';
    }

    /**
     * ★ 输出侧合规审查。
     * 难点：用户问「能降三高吗」时，模型的【正确拒绝】也含「功效/治疗」等词，
     * 若直接按敏感词拦截，会把正确的拒绝也拦掉（误伤）。
     * 因此判据是「是否作出宣称」而非「是否出现词」：
     *   - 出现【免责/拒绝句式】→ 视为安全
     *   - 出现【宣称句式】→ 拦截
     * 返回命中的敏感词（空串 = 通过）。
     */
    public static function scanCompliance(string $text): string
    {
        $t = trim($text);
        if ($t === '') { return ''; }

        // ① 免责/拒绝句式：出现这些说明模型在撇清关系，安全
        $refuse = [
            '无法提供', '不能提供', '不便展开', '不方便', '无法回答', '不能回答',
            '建议您咨询', '建议咨询', '咨询专业', '咨询医生', '咨询医师',
            '不替代药物', '不能替代', '不具有', '没有治疗', '不涉及', '不予置评',
            '我无法', '我不能', '抱歉', '仅供', '请注意', '请咨询',
        ];
        foreach ($refuse as $r) {
            if (mb_strpos($t, $r) !== false) { return ''; }
        }

        // ② 医疗/健康宣称（出现即拦）
        $health = [
            '降三高', '降血压', '降血糖', '降血脂', '降胆固醇', '降尿酸',
            '减肥', '瘦身', '燃脂', '排毒', '抗癌', '防癌', '抗肿瘤',
            '治疗', '疗效', '药用', '药效', '治病', '医治', '痊愈', '根治',
            '防治', '缓解病症', '养生保健', '保健功效', '医疗作用',
        ];
        // ★ 否定距离判断：避免误拦「不具有治疗功能」「不承诺收藏升值」这类正确的免责表述。
        //   判据：敏感词出现位置之前 8 字内若含否定词，视为「否认该宣称」而非「作出宣称」。
        $neg = ['不', '无', '没', '非', '未', '勿', '别'];
        // 分组策略：
        //  - 医疗类($health) 与 绝对化类($extreme)：无条件拦截（绝不能让「这是最好的茶」漏过）
        //  - 投资/收藏类($invest)：允许否定语境（「不承诺收藏升值」是正确免责）
        $extreme = ['最好', '第一', '唯一', '最低价', '全网最', '绝对', '百分之百', '100%有效'];
        $invest  = ['升值', '保值', '增值', '收藏价值', '投资价值', '稳赚'];
        foreach (array_merge($health, $extreme) as $w) {
            if (mb_strpos($t, $w) !== false) { return $w; }
        }
        foreach ($invest as $w) {
            $pos = mb_strpos($t, $w);
            while ($pos !== false) {
                $before = mb_substr($t, max(0, $pos - 8), min(8, $pos));
                $denied = false;
                foreach ($neg as $ng) {
                    if ($before !== '' && mb_strpos($before, $ng) !== false) { $denied = true; break; }
                }
                if (!$denied) { return $w; }
                $pos = mb_strpos($t, $w, $pos + mb_strlen($w));
            }
        }
        return '';
    }

    /**
     * ★ 从回复里提取商品锚点 ||goods:123||（在 JSON 里是 ｜｜goods:123｜｜，故同时兼容全角竖线）。
     * 兼职容错：兼容早期设计的 [goods:123] 方括号写法。
     * 返回去重后的商品 ID 数组（最多 12 个，与 /mall/api/cards 上限一致）。
     */
    public static function extractAnchors(string $text): array
    {
        $ids = [];
        if (preg_match_all('/(?:\|\||\[)\s*goods\s*[:：]\s*(\d{1,9})\s*(?:\|\||\])/u', $text, $m)) {
            foreach ($m[1] as $id) {
                $i = (int)$id;
                if ($i > 0 && !in_array($i, $ids, true)) { $ids[] = $i; }
                if (count($ids) >= 12) { break; }
            }
        }
        return $ids;
    }

    /** 把锚点标记从展示文本里去掉（前端用 anchors 渲染商品卡，正文不该露出 ||goods:1||） */
    public static function stripAnchors(string $text): string
    {
        return preg_replace('/(?:\|\||\[)\s*goods\s*[:：]\s*\d{1,9}\s*(?:\|\||\])/u', '', $text) ?? $text;
    }

    /** 供业务插件（如 mall 导购）直接调用：一次问答，返回结构化结果 */
    public static function guide(string $question, string $sceneHint = '', int $kbLimit = 0): array
    {
        if (!self::isConfigured()) {
            return ['ok' => false, 'text' => '', 'anchors' => [], 'err' => 'AI 未配置 API Key'];
        }
        $ctx = self::kbContext($question, $kbLimit);
        $sys = (string)self::cfg('system.prompt', self::defaultPrompt());
        if ($sceneHint !== '') { $sys .= "\n\n【本次场景】" . mb_substr($sceneHint, 0, 300); }
        if ($ctx !== '') { $sys .= "\n\n" . $ctx; }
        $r = self::chat([
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => mb_substr($question, 0, 1000)],
        ], ['scene' => 'guide']);
        if (!$r['ok']) { return ['ok' => false, 'text' => '', 'anchors' => [], 'err' => $r['err']]; }
        $viol = self::scanCompliance($r['text']);
        if ($viol !== '') {
            self::logCall('compliance.block', self::provider(), self::model(), 0, 0, 0, false, '命中:' . $viol);
            return ['ok' => true, 'blocked' => true, 'anchors' => [],
                    'text' => '这个问题我这边不方便展开，建议联系人工客服确认。'];
        }
        return ['ok' => true, 'text' => $r['text'], 'anchors' => self::extractAnchors($r['text']), 'err' => ''];
    }

    /* ================= AI 客服（前台 JSON 接口） ================= */

    private static function clientIp(): string
    {
        return (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    }

    /** 频控：每 IP 每小时 N 次（默认 20） */
    private static function rateLimited(): bool
    {
        $limit = max(1, (int)self::cfg('rate.hour', '20'));
        $ip = self::clientIp();
        // ★ 原实现按「消息数」计数（dispatch 每收一条消息就 insert 一行 session），
        //   导致「每小时 20 次」实际是「20 条消息」，用户连问几句就被拦。
        //   改为按【去重会话数】计数，语义才与「次」相符。
        $n = Db::one("SELECT COUNT(DISTINCT sid) FROM " . self::t('chat_session') . " WHERE ip = :ip AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)", [':ip' => $ip]);
        return (int)$n >= $limit;
    }

    /**
     * POST /ai/api/chat  {sid?: string, message: string}
     * 返回 JSON：{ok, reply, sid}
     */
    public static function dispatch(array $path, array $query): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $seg = $path[0] ?? '';
        // 内核 case 'ai' 传入的是 ['api','chat']；plugin_page 兜底可能传 ['ai','api','chat']
        if ($seg === 'ai' && ($path[1] ?? '') === 'api') { array_shift($path); $seg = 'api'; }
        if ($seg !== 'api' || ($path[1] ?? '') !== 'chat') {
            http_response_code(404);
            echo json_encode(['ok' => false, 'err' => 'not found']);
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'err' => 'method not allowed']);
            return;
        }
        if (!self::isConfigured()) {
            http_response_code(503);
            echo json_encode(['ok' => false, 'err' => 'AI 暂未开通，请稍后再试或使用留言']);
            return;
        }
        $in = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($in)) { $in = $_POST; }
        $message = trim((string)($in['message'] ?? ''));
        $sid = preg_replace('/[^a-zA-Z0-9]/', '', (string)($in['sid'] ?? '')) ?: bin2hex(random_bytes(16));
        if ($message === '' || mb_strlen($message) > 1000) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'err' => '消息为空或过长']);
            return;
        }
        if (self::rateLimited()) {
            http_response_code(429);
            echo json_encode(['ok' => false, 'err' => '提问太频繁，请稍后再试']);
            return;
        }
        $uid = (int)(function_exists('Auth') && \Auth::user() ? (\Auth::user()['id'] ?? 0) : 0);
        Db::insert('ai_chat_session', ['sid' => $sid, 'uid' => $uid, 'ip' => self::clientIp()]);
        Db::insert('ai_chat_message', ['sid' => $sid, 'role' => 'user', 'content' => $message]);

        // RAG：知识库上下文 + 人设（后台可配 system prompt）
        // 检索词 = 本轮问题 + 上一轮用户提问（追问常很短，如「那个多少钱」，单独检索会跑偏）
        // ★ 取上一轮用户提问（必须在下面使用之前取）
        //   原代码：$lastUser 在 L515 初始化、L529 赋值，而此处已使用
        //   → 永远为空，追问合并（「那个多少钱」这类短追问）从未生效
        //   注意：上面刚插入的是【当前】消息，所以取 role=user 的【上一条】(OFFSET 1)
        $lastUser = '';
        if ($sid !== '') {
            $prev = Db::row('SELECT content FROM ' . Db::t('ai_chat_message')
                . " WHERE sid=? AND role='user' ORDER BY id DESC LIMIT 1 OFFSET 1", [$sid]);
            if ($prev !== null) { $lastUser = (string)$prev['content']; }
        }
        $q2 = $message;
        if ($lastUser !== '' && mb_strlen($message) <= 12) { $q2 = $lastUser . ' ' . $message; }
        $ctx = self::kbContext($q2);
        $sys = self::cfg('system.prompt', self::defaultPrompt());
        // 场景附加提示：导购场景由 mall 传入，用于收窄回答范围（如「你正在介绍 XX 专题」）
        $sceneHint = trim((string)($in['scene_hint'] ?? ''));
        if ($sceneHint !== '') { $sys .= "\n\n【本次场景】" . mb_substr($sceneHint, 0, 300); }
        if ($ctx !== '') { $sys .= "\n\n" . $ctx; }
        $messages = [['role' => 'system', 'content' => $sys]];
        // 历史裁剪：条数与单条长度均可配（成本大头，默认 3 条 × 300 字）
        $hMax = max(0, min(6, (int)self::cfg('history.n', '3')));
        $hLen = max(80, min(800, (int)self::cfg('history.len', '300')));
        $lastUser = '';
        if ($hMax > 0) {
            // ★ 顺序必须是【时间正序】（旧→新），且最后一条是上一轮用户提问。
            //   原实现 array_reverse(id DESC) = [最新,...,最旧]，把最旧的问题紧贴在当前提问之前，
            //   模型会以为要回答那个旧问题 → 实测出现「问退货答送礼」的错位。
            $hist = Db::rows("SELECT role, content FROM " . self::t('chat_message') . " WHERE sid = :sid ORDER BY id DESC LIMIT " . ($hMax * 2 + 1), [':sid' => $sid]);
            $picked = [];
            foreach ($hist as $h) {           // id DESC → 新到旧，取最近 $hMax 条
                if (count($picked) >= $hMax) { break; }
                $picked[] = ['role' => $h['role'] === 'assistant' ? 'assistant' : 'user', 'content' => mb_substr((string)$h['content'], 0, $hLen)];
            }
            $picked = array_reverse($picked);  // → 旧到新（正确的时间正序）
            foreach ($picked as $p) {
                $messages[] = $p;
                if ($p['role'] === 'user') { $lastUser = (string)$p['content']; }
            }
        }
        $res = self::chat($messages, ['scene' => 'chat.web']);
        if (!$res['ok']) {
            http_response_code(502);
            echo json_encode(['ok' => false, 'err' => 'AI 服务暂时不可用', 'sid' => $sid]);
            return;
        }
        // ★ 输出侧合规审查（不信任模型自律，实测它确实会讲健康功效）
        $viol = self::scanCompliance($res['text']);
        $reply = $res['text'];
        $anchors = self::extractAnchors($reply);
        if ($viol !== '') {
            // 记录违规原文，便于运营复盘；对外返回安全话术
            self::logCall('compliance.block', self::provider(), self::model(), 0, 0, 0, false, '命中:' . $viol);
            Db::insert('ai_chat_message', ['sid' => $sid, 'role' => 'assistant', 'content' => '[已拦截] ' . mb_substr($res['text'], 0, 500)]);
            echo json_encode(['ok' => true, 'blocked' => true, 'anchors' => [], 'sid' => $sid,
                'reply' => '这个问题我这边不方便展开，建议您直接联系人工客服确认；如果您想了解茶的产地、工艺、口感、冲泡或选购建议，我很乐意帮您。'], JSON_UNESCAPED_UNICODE);
            return;
        }
        Db::insert('ai_chat_message', ['sid' => $sid, 'role' => 'assistant', 'content' => $reply]);
        echo json_encode(['ok' => true, 'reply' => $reply, 'anchors' => $anchors, 'sid' => $sid], JSON_UNESCAPED_UNICODE);
    }
}
