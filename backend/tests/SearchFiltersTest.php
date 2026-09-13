<?php
/**
 * 轻量自检（无框架依赖）：php tests/SearchFiltersTest.php
 * 覆盖 SearchFilters 与 TorrentClassifier 的核心行为。
 */
require_once __DIR__ . '/../app/service/SearchFilters.php';
require_once __DIR__ . '/../app/service/TorrentClassifier.php';

use app\service\SearchFilters;
use app\service\TorrentClassifier;

$failures = 0;
function assertSame($expected, $actual, string $message): void
{
    global $failures;
    if ($expected === $actual) {
        echo "PASS: {$message}\n";
        return;
    }
    $failures++;
    echo "FAIL: {$message} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ")\n";
}

// 1. 默认排序：有关键词 -> relevance，无关键词 -> new
$f = SearchFilters::fromRequest(['q' => 'ubuntu'], true);
assertSame('relevance', $f->sort(), 'default sort with query is relevance');
$f = SearchFilters::fromRequest([], false);
assertSame('new', $f->sort(), 'default sort without query is new');
$f = SearchFilters::fromRequest(['q' => 'x', 'sort' => 'relevance'], false);
assertSame('new', $f->sort(), 'relevance downgrades to new when no keyword');

// 2. 非法/重复的类型与标签会被过滤
$f = SearchFilters::fromRequest(['q' => 'x', 'types' => 'video,foo,video,audio', 'tags' => 'movie,bar'], true);
assertSame(['video', 'audio'], $f->types(), 'types whitelist + dedupe');
assertSame(['movie'], $f->tags(), 'tags whitelist');

// 3. 大小区间解析与自动交换
$f = SearchFilters::fromRequest(['q' => 'x', 'smin' => '200', 'smax' => '100'], true);
assertSame(100, $f->sizeMin(), 'size min/max swapped (min)');
assertSame(200, $f->sizeMax(), 'size min/max swapped (max)');
assertSame(null, SearchFilters::fromRequest(['q' => 'x', 'smin' => 'abc'], true)->sizeMin(), 'invalid smin ignored');

// 4. 时间戳白名单
assertSame(null, SearchFilters::fromRequest(['q' => 'x', 'since' => '123'], true)->since(), 'out-of-range ts ignored');

// 5. toQueryParams 稳定且省略默认排序
$p = SearchFilters::fromRequest(['q' => 'ubuntu', 'sort' => 'relevance', 'types' => 'video'], true)->toQueryParams('ubuntu');
assertSame(['q' => 'ubuntu', 'types' => 'video'], $p, 'default sort omitted from query params');
$p = SearchFilters::fromRequest(['q' => 'ubuntu', 'sort' => 'size'], true)->toQueryParams('ubuntu');
assertSame('size', $p['sort'] ?? '', 'non-default sort kept');

// 6. Manticore 过滤结构
$f = SearchFilters::fromRequest(['q' => 'x', 'types' => 'video,audio', 'tags' => 'movie', 'smin' => '1000', 'until' => '2000000000'], true);
$mf = $f->toManticoreFilters();
assertSame(true, isset($mf['bool']['must']), 'manticore filters use bool.must');
assertSame(4, count($mf['bool']['must']), 'manticore filters count: types,tags,size,time');

// 7. 标签 ID 稳定映射
assertSame(1, SearchFilters::tagId('movie'), 'movie tag id = 1');
assertSame(2, SearchFilters::tagId('tv'), 'tv tag id = 2');

// 8. 文件类型分类
assertSame('video', TorrentClassifier::classifyType([['path' => 'a.mkv', 'size' => 1]], ''), 'single mkv -> video');
assertSame('archive', TorrentClassifier::classifyType([['path' => 'x.iso', 'size' => 1]], 'iso'), 'iso -> archive');
$manyMp3 = array_fill(0, 9, ['path' => 't.mp3', 'size' => 1]);
$manyMp3[] = ['path' => 'readme.txt', 'size' => 1];
assertSame('audio', TorrentClassifier::classifyType($manyMp3, ''), 'majority mp3 -> audio');
assertSame('other', TorrentClassifier::classifyType([['path' => 'weird.zzz', 'size' => 1]], 'zzz'), 'unknown ext -> other');

// 9. 名称标签分类
assertSame(true, in_array('movie', TorrentClassifier::classifyTags('Some.Movie.2024.1080p.BluRay')), 'movie tag from keywords');
assertSame(true, in_array('music', TorrentClassifier::classifyTags('歌手 专辑 FLAC 无损')), 'music tag from chinese keywords');
assertSame([], TorrentClassifier::classifyTags(''), 'empty name -> no tags');

echo $failures === 0 ? "\nALL TESTS PASSED\n" : "\n{$failures} TEST(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
