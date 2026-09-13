# 详细设计文档

## 1. 数据库设计 (MySQL)

### 1.1 `torrents` 表
存储种子的核心元数据。

| 字段 | 类型 | 说明 |
| :--- | :--- | :--- |
| `infohash` | CHAR(40) | 主键，磁力链接哈希值 |
| `name` | VARCHAR(1024) | 种子名称 |
| `size_total` | BIGINT | 总大小 (字节) |
| `file_count` | INT | 文件数量 |
| `extension` | VARCHAR(16) | 单文件资源的扩展名 |
| `file_type` | VARCHAR(16) | 文件类型归类 (video/audio/image/archive/document/software/other) |
| `tags_csv` | VARCHAR(128) | 内容标签，逗号分隔 (movie/tv/anime/music/game/software/book) |
| `files_json` | JSON | 文件列表详情 |
| `status` | VARCHAR(32) | 状态 (active, dead, suspect, fetched, spam) |
| `created_at` | TIMESTAMP | 收录时间 |

`file_type` 由文件扩展名统计推断（多文件取占比过半的类型），`tags_csv` 由资源名称中的关键词
（如 `1080p`、`bluray`、`Season`、`FLAC`、`游戏`、`电子书` 等）推断，分类逻辑统一收敛在
`app/service/TorrentClassifier.php`。MySQL 兜底查询用 `FIND_IN_SET(tags_csv)` 过滤标签。

### 1.2 `torrent_peers` 表
记录发现种子的节点信息（用于热度分析）。

| 字段 | 类型 | 说明 |
| :--- | :--- | :--- |
| `infohash` | CHAR(40) | 关联种子 |
| `ip` | VARCHAR(45) | 节点 IP |
| `port` | INT | 端口 |
| `last_seen_at` | TIMESTAMP | 最后发现时间 |

### 1.3 `crawl_queue` 表
爬虫任务队列，管理待抓取任务。

## 2. 搜索引擎设计 (Manticore)

### 2.1 索引结构 (`torrents_rt`)
- **类型**: Real-time Index (及该索引支持实时写入)
- **分词器**: `jieba_chinese` (支持中文分词)
- **字段**:
  - `name`: 全文索引字段
  - `infohash`: string 属性字段
  - `size_total`: bigint 属性字段 (大小过滤/排序)
  - `created_at`: timestamp 属性字段 (时间过滤/排序)
  - `file_type`: string 属性字段 (文件类型过滤)
  - `tags`: multi 整数属性 (内容标签 ID 过滤，多值任一命中)

## 3. 接口设计
主要由 `SearchController` 和 `TorrentController` 处理：
- `GET /`: 首页
- `GET /search?q=keyword`: 搜索结果页
- `GET /torrent/{infohash}`: 详情页

### 3.1 `/search` 查询参数（全部体现在 URL 中，可直接复制分享）

| 参数 | 说明 | 示例 |
| :--- | :--- | :--- |
| `q` | 关键词（切换过滤器时始终保留） | `q=Ubuntu` |
| `types` | 文件类型，逗号分隔多选（OR），取值 video/audio/image/archive/document/software/other | `types=video,audio` |
| `tags` | 内容标签，逗号分隔多选（OR），取值 movie/tv/anime/music/game/software/book | `tags=movie,tv` |
| `smin` / `smax` | 大小区间（字节），由前端 MB 输入换算 | `smin=104857600` |
| `since` / `until` | 收录时间区间（Unix 时间戳），前端用日期/快捷预设生成 | `since=1717200000` |
| `sort` | 排序：`relevance`(相关度，默认) / `new`(时间) / `size`(大小)；无关键词时相关度降级为时间 | `sort=new` |
| `page` | 页码 | `page=2` |

非法参数、空值与默认值会由服务端 302 重定向到规范化 URL，保证分享链接干净稳定。
Manticore 查询用 `bool.must` 组合全文 `match` 与 `in`/`range` 过滤；Manticore 不可用时
降级为 MySQL（标签走 `FIND_IN_SET`，关键词走 `LIKE`，不支持相关度排序）。

## 4. 前端设计
- **布局**: 基于 Tailwind CSS 的响应式布局。
- **风格**: 现代深色模式风格 (Business Theme)。
- **交互**: 服务端渲染 (SSR)，少量原生 JS 处理交互（如复制链接）。
