<?php

namespace app\controller;

use app\model\Torrent;
use app\service\ManticoreClient;
use app\service\SearchFilters;
use support\Request;

class SearchController
{
    public function index(Request $request)
    {
        $latest = Torrent::where('status', '<>', 'spam')->order('created_at', 'desc')->limit(8)->select()->toArray();

        return view('search/index', [
            'q' => (string) $request->get('q', ''),
            'latest' => $latest,
        ]);
    }

    public function list(Request $request)
    {
        $get = $request->get();
        $rawQValue = $get['q'] ?? '';
        $rawQ = is_scalar($rawQValue) ? trim((string) $rawQValue) : '';
        $filters = SearchFilters::fromRequest($get, $rawQ !== '');
        $rawPage = $get['page'] ?? 1;
        $page = max(1, (int) (is_scalar($rawPage) ? $rawPage : 1));

        // 规范化 URL：剔除非法/空参数、统一默认值，保证分享出去的链接干净稳定
        $canonical = $filters->toQueryParams($rawQ);
        if ($page > 1) {
            $canonical['page'] = $page;
        }

        $normalized = [];
        $needsRedirect = false;
        foreach ($get as $key => $value) {
            if (is_array($value) || !is_scalar($value)) {
                $needsRedirect = true;
                break;
            }
            if ($key === 'page') {
                $p = (int) $value;
                if ($p > 1) {
                    $normalized['page'] = $p;
                } else {
                    // page=1 或非法页码不应出现在分享 URL 中
                    $needsRedirect = true;
                }
                continue;
            }
            if (isset($canonical[$key]) && (string) $canonical[$key] === (string) $value) {
                $normalized[$key] = (string) $value;
            } else {
                // 未识别的键、被清洗的值、默认值 -> 需要重定向
                $needsRedirect = true;
                break;
            }
        }
        ksort($normalized);
        $canonicalSorted = $canonical;
        ksort($canonicalSorted);
        if ($needsRedirect || http_build_query($normalized) !== http_build_query($canonicalSorted)) {
            return redirect('/search' . ($canonical ? '?' . http_build_query($canonical) : ''));
        }

        $pageSize = 20;
        $sort = $filters->sort();

        if ($rawQ === '') {
            $build = function () use ($filters, $rawQ) {
                $query = Torrent::where('status', '<>', 'spam');
                if ($rawQ !== '') {
                    $query->where('name', 'like', '%' . $rawQ . '%');
                }
                $filters->applyToMysqlQuery($query);
                return $query;
            };

            $total = (int) $build()->count();
            $itemsQuery = $build();
            if ($sort === 'size') {
                $itemsQuery->order('size_total', 'desc');
            } else {
                $itemsQuery->order('created_at', 'desc');
            }
            $items = $itemsQuery->page($page, $pageSize)->select()->toArray();

            return view('search/list', $this->viewData($rawQ, $filters, $items, $page, $pageSize, $total, 'mysql'));
        }

        $client = new ManticoreClient();
        try {
            $result = $client->search($rawQ, $page, $pageSize, $sort, $filters);

            return view('search/list', $this->viewData($rawQ, $filters, $result['items'], $page, $pageSize, $result['total'], 'manticore'));
        } catch (\Throwable $e) {
            // 搜索引擎不可用时降级到 MySQL（仅支持名称 LIKE，无相关度排序）
            $build = function () use ($filters, $rawQ) {
                $query = Torrent::where('status', '<>', 'spam')->where('name', 'like', '%' . $rawQ . '%');
                $filters->applyToMysqlQuery($query);
                return $query;
            };

            $total = (int) $build()->count();
            $itemsQuery = $build();
            if ($sort === 'size') {
                $itemsQuery->order('size_total', 'desc');
            } else {
                $itemsQuery->order('created_at', 'desc');
            }
            $items = $itemsQuery->page($page, $pageSize)->select()->toArray();

            return view('search/list', $this->viewData($rawQ, $filters, $items, $page, $pageSize, $total, 'mysql'));
        }
    }

    private function viewData(string $q, SearchFilters $filters, array $items, int $page, int $pageSize, int $total, string $from): array
    {
        return [
            'q' => $q,
            'items' => $items,
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'sort' => $filters->sort(),
            'filters' => $filters,
            'from' => $from,
        ];
    }
}
