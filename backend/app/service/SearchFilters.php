<?php

namespace app\service;

/**
 * 搜索过滤条件（文件类型 / 大小区间 / 收录时间 / 标签 / 排序）。
 *
 * URL 参数约定：
 *   q      关键词
 *   types  文件类型，逗号分隔（video,audio,image,archive,document,software,other）
 *   smin   最小体积，字节数（由前端 MB 输入换算）
 *   smax   最大体积，字节数
 *   since  收录起始时间，Unix 时间戳
 *   until  收录截止时间，Unix 时间戳
 *   tags   内容标签，逗号分隔（movie,tv,anime,music,game,software,book）
 *   sort   排序：relevance | new | size
 *   page   页码
 *
 * 多个类型/标签之间为 OR 关系；类型与标签、大小、时间之间为 AND 关系。
 */
class SearchFilters
{
    public const TYPES = ['video', 'audio', 'image', 'archive', 'document', 'software', 'other'];
    public const TAGS = ['movie', 'tv', 'anime', 'music', 'game', 'software', 'book'];
    public const SORTS = ['relevance', 'new', 'size'];

    /** @var string[] */
    private array $types = [];
    /** @var string[] */
    private array $tags = [];
    private ?int $sizeMin = null;
    private ?int $sizeMax = null;
    private ?int $since = null;
    private ?int $until = null;
    private string $sort = 'relevance';

    public static function fromRequest(array $get, bool $hasQuery): self
    {
        $f = new self();

        $str = static fn ($key) => isset($get[$key]) && is_scalar($get[$key]) ? (string) $get[$key] : '';

        $f->types = self::parseList($str('types'), self::TYPES);
        $f->tags = self::parseList($str('tags'), self::TAGS);

        $min = self::parseBytes($str('smin'));
        $max = self::parseBytes($str('smax'));
        if ($min !== null) {
            $f->sizeMin = $min;
        }
        if ($max !== null) {
            $f->sizeMax = $max;
        }
        // 保证 min <= max
        if ($f->sizeMin !== null && $f->sizeMax !== null && $f->sizeMin > $f->sizeMax) {
            $tmp = $f->sizeMin;
            $f->sizeMin = $f->sizeMax;
            $f->sizeMax = $tmp;
        }

        $since = self::parseTs($str('since'));
        $until = self::parseTs($str('until'));
        $f->since = $since;
        $f->until = $until;

        $sort = strtolower(trim($str('sort')));
        if (!in_array($sort, self::SORTS, true)) {
            // 无关键词时相关度没有意义，默认按收录时间
            $sort = $hasQuery ? 'relevance' : 'new';
        }
        if ($sort === 'relevance' && !$hasQuery) {
            $sort = 'new';
        }
        $f->sort = $sort;

        return $f;
    }

    /**
     * @return string[]
     */
    public function types(): array
    {
        return $this->types;
    }

    /**
     * @return string[]
     */
    public function tags(): array
    {
        return $this->tags;
    }

    public function sizeMin(): ?int
    {
        return $this->sizeMin;
    }

    public function sizeMax(): ?int
    {
        return $this->sizeMax;
    }

    public function since(): ?int
    {
        return $this->since;
    }

    public function until(): ?int
    {
        return $this->until;
    }

    public function sort(): string
    {
        return $this->sort;
    }

    public function isEmpty(): bool
    {
        return !$this->types && !$this->tags
            && $this->sizeMin === null && $this->sizeMax === null
            && $this->since === null && $this->until === null;
    }

    /**
     * 生成可分享、稳定有序的查询参数（不包含 page）。
     */
    public function toQueryParams(string $q): array
    {
        $params = [];
        if ($q !== '') {
            $params['q'] = $q;
        }
        if ($this->types) {
            $params['types'] = implode(',', $this->types);
        }
        if ($this->tags) {
            $params['tags'] = implode(',', $this->tags);
        }
        if ($this->sizeMin !== null) {
            $params['smin'] = (string) $this->sizeMin;
        }
        if ($this->sizeMax !== null) {
            $params['smax'] = (string) $this->sizeMax;
        }
        if ($this->since !== null) {
            $params['since'] = (string) $this->since;
        }
        if ($this->until !== null) {
            $params['until'] = (string) $this->until;
        }
        $defaultSort = $q !== '' ? 'relevance' : 'new';
        if ($this->sort !== $defaultSort) {
            $params['sort'] = $this->sort;
        }
        return $params;
    }

    /**
     * 构造 Manticore JSON 查询中的 bool 过滤条件（and/or/range/in）。
     */
    public function toManticoreFilters(): array
    {
        $and = [];

        if ($this->types) {
            $and[] = ['in' => ['file_type' => array_values($this->types)]];
        }
        if ($this->tags) {
            // tags 是 multi 属性，任一命中即可
            $ids = array_map(static fn ($t) => self::tagId($t), $this->tags);
            $and[] = ['in' => ['tags' => array_map('intval', $ids)]];
        }

        $sizeRange = [];
        if ($this->sizeMin !== null) {
            $sizeRange['gte'] = $this->sizeMin;
        }
        if ($this->sizeMax !== null) {
            $sizeRange['lte'] = $this->sizeMax;
        }
        if ($sizeRange) {
            $and[] = ['range' => ['size_total' => $sizeRange]];
        }

        $timeRange = [];
        if ($this->since !== null) {
            $timeRange['gte'] = $this->since;
        }
        if ($this->until !== null) {
            $timeRange['lte'] = $this->until;
        }
        if ($timeRange) {
            $and[] = ['range' => ['created_at' => $timeRange]];
        }

        return $and ? ['bool' => ['must' => $and]] : [];
    }

    /**
     * 应用到 think-orm 查询构建器（MySQL 兜底路径，q 为空时使用）。
     * 标签在 MySQL 中以逗号分隔字符串保存（tags_csv），用 FIND_IN_SET。
     *
     * @param \think\db\Query $query
     * @return \think\db\Query
     */
    public function applyToMysqlQuery($query)
    {
        if ($this->types) {
            $query->whereIn('file_type', $this->types);
        }
        if ($this->tags) {
            // 多标签为 OR 关系；标签取自白名单，用参数绑定
            $ors = [];
            $bindings = [];
            foreach ($this->tags as $tag) {
                $ors[] = 'FIND_IN_SET(?, tags_csv)';
                $bindings[] = $tag;
            }
            $query->whereRaw('(' . implode(' OR ', $ors) . ')', $bindings);
        }
        if ($this->sizeMin !== null) {
            $query->where('size_total', '>=', $this->sizeMin);
        }
        if ($this->sizeMax !== null) {
            $query->where('size_total', '<=', $this->sizeMax);
        }
        if ($this->since !== null) {
            $query->where('created_at', '>=', date('Y-m-d H:i:s', $this->since));
        }
        if ($this->until !== null) {
            $query->where('created_at', '<=', date('Y-m-d H:i:s', $this->until));
        }
        return $query;
    }

    /**
     * 标签到数值 ID（Manticore multi 属性能存整数）。
     */
    public static function tagId(string $tag): int
    {
        $idx = array_search($tag, self::TAGS, true);
        return $idx === false ? 0 : $idx + 1;
    }

    /**
     * @param string[] $allowed
     * @return string[]
     */
    private static function parseList(string $raw, array $allowed): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = strtolower(trim($p));
            if (in_array($p, $allowed, true) && !in_array($p, $out, true)) {
                $out[] = $p;
            }
        }
        return $out;
    }

    private static function parseBytes(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '' || !preg_match('/^\d{1,12}$/', $raw)) {
            return null;
        }
        $v = (int) $raw;
        return $v > 0 ? $v : null;
    }

    private static function parseTs(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '' || !preg_match('/^\d{1,11}$/', $raw)) {
            return null;
        }
        $v = (int) $raw;
        // 合理范围：2000-01-01 ~ 2100-01-01
        if ($v < 946684800 || $v > 4102444800) {
            return null;
        }
        return $v;
    }
}
