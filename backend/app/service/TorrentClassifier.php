<?php

namespace app\service;

/**
 * 资源分类器：
 * - 按文件扩展名推断文件类型（video/audio/image/archive/document/software/iso/other）
 * - 按资源名称中的关键词推断内容标签（movie/tv/anime/music/game/software/book）
 *
 * 分类结果会随索引写入 MySQL 与 Manticore，供搜索页过滤使用。
 */
class TorrentClassifier
{
    /**
     * 扩展名 => 文件类型
     */
    private const EXT_TYPES = [
        // 视频
        'mp4' => 'video', 'mkv' => 'video', 'avi' => 'video', 'mov' => 'video',
        'wmv' => 'video', 'flv' => 'video', 'webm' => 'video', 'm4v' => 'video',
        'mpg' => 'video', 'mpeg' => 'video', 'rmvb' => 'video', 'rm' => 'video',
        'ts' => 'video', '3gp' => 'video', 'f4v' => 'video',
        // 音频
        'mp3' => 'audio', 'flac' => 'audio', 'wav' => 'audio', 'aac' => 'audio',
        'ogg' => 'audio', 'wma' => 'audio', 'm4a' => 'audio', 'ape' => 'audio',
        'opus' => 'audio',
        // 图片
        'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image',
        'bmp' => 'image', 'webp' => 'image', 'svg' => 'image', 'ico' => 'image',
        'tiff' => 'image', 'tif' => 'image', 'heic' => 'image', 'psd' => 'image',
        // 压缩包
        'zip' => 'archive', 'rar' => 'archive', '7z' => 'archive', 'tar' => 'archive',
        'gz' => 'archive', 'bz2' => 'archive', 'xz' => 'archive', 'tgz' => 'archive',
        'iso' => 'archive',
        // 文档 / 电子书
        'pdf' => 'document', 'doc' => 'document', 'docx' => 'document',
        'xls' => 'document', 'xlsx' => 'document', 'ppt' => 'document',
        'pptx' => 'document', 'txt' => 'document', 'md' => 'document',
        'rtf' => 'document', 'csv' => 'document', 'epub' => 'document',
        'mobi' => 'document', 'azw3' => 'document', 'chm' => 'document',
        // 软件 / 安装包
        'exe' => 'software', 'msi' => 'software', 'dmg' => 'software',
        'pkg' => 'software', 'deb' => 'software', 'rpm' => 'software',
        'apk' => 'software', 'appimage' => 'software', 'bat' => 'software',
        'dll' => 'software',
    ];

    /**
     * 内容标签 => 名称关键词（小写匹配）
     */
    private const TAG_KEYWORDS = [
        'movie' => [
            'movie', 'film', 'bluray', 'blu-ray', 'bdremux', 'remux', 'bdrip',
            'dvdrip', 'web-dl', 'webdl', 'webrip', 'hdtv', '1080p', '2160p',
            '4k', 'uhd', 'x264', 'x265', 'h264', 'h265', 'hevc', '电影', '高清',
        ],
        'tv' => [
            'season', 's01', 's02', 's03', 's04', 's05', 's06', 's07', 's08',
            's09', 'complete', 'episode', 'tv series', '剧集', '电视剧', '美剧',
            '英剧', '日剧', '韩剧', '季', '全集', '连续剧',
        ],
        'anime' => [
            'anime', 'bdrip', 'ova', 'oad', 'tv动画', '动画', '动漫', '番剧',
            '新番', '完结动画',
        ],
        'music' => [
            'album', 'flac', 'lossless', 'discography', 'soundtrack', 'ost',
            'music', 'audio cd', '音乐', '专辑', '无损', '演唱会', 'live',
            '歌曲', '合集cd',
        ],
        'game' => [
            'game', 'games', 'repack', 'crack', 'patch', 'dlc', 'gog', 'steam',
            '游戏', '汉化', '破解版', '绿色版', '免安装',
        ],
        'software' => [
            'windows', 'macos', 'linux', 'software', 'portable', 'pro', 'suite',
            'x64', 'x86', '激活', '破解', '软件', '安装包', '专业版',
        ],
        'book' => [
            'ebook', 'e-book', 'epub', 'mobi', 'azw3', 'pdf', '图书', '书籍',
            '教程', '教材', '电子书', '扫描版', 'kindle',
        ],
    ];

    /**
     * 根据文件列表推断资源的文件类型。
     *
     * @param array $files [['path' => string, 'size' => int], ...]
     */
    public static function classifyType(array $files, string $singleExtension = ''): string
    {
        $singleExtension = strtolower(trim($singleExtension, ". \t\n\r\0\x0B"));
        if (count($files) <= 1 && $singleExtension !== '') {
            return self::EXT_TYPES[$singleExtension] ?? 'other';
        }

        $counts = [];
        $checked = 0;
        foreach ($files as $f) {
            if (!is_array($f)) {
                continue;
            }
            if (++$checked > 1000) {
                break;
            }
            $path = (string) ($f['path'] ?? '');
            $pos = strrpos($path, '.');
            if ($pos === false || $pos + 1 >= strlen($path)) {
                continue;
            }
            $ext = strtolower(substr($path, $pos + 1));
            // 去掉可能的路径分隔残留
            $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?? '';
            if ($ext === '' || !isset(self::EXT_TYPES[$ext])) {
                continue;
            }
            $type = self::EXT_TYPES[$ext];
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        if (!$counts) {
            return 'other';
        }
        arsort($counts);
        $topType = (string) array_key_first($counts);
        $topCount = (int) reset($counts);

        // 多数文件属于同一类型才归类，否则视为混合资源（other）
        return $topCount * 2 >= $checked ? $topType : 'other';
    }

    /**
     * 根据资源名称推断内容标签（可能多个）。
     *
     * @return string[]
     */
    public static function classifyTags(string $name): array
    {
        $haystack = function_exists('mb_strtolower')
            ? mb_strtolower(trim($name), 'UTF-8')
            : strtolower(trim($name));
        if ($haystack === '') {
            return [];
        }

        $tags = [];
        foreach (self::TAG_KEYWORDS as $tag => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($haystack, $kw)) {
                    $tags[] = $tag;
                    break;
                }
            }
        }
        return $tags;
    }

    /**
     * 一次性返回类型与标签。
     *
     * @param array $files [['path' => string, 'size' => int], ...]
     * @return array{type:string,tags:string[]}
     */
    public static function classify(string $name, array $files, string $singleExtension = ''): array
    {
        return [
            'type' => self::classifyType($files, $singleExtension),
            'tags' => self::classifyTags($name),
        ];
    }
}
