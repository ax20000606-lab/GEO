<?php

namespace App\Support\GeoAudit;

use Illuminate\Support\Facades\DB;

/**
 * 「为这道题生成标题」：把声量对标里发现缺口的那道题，变成可发布的文章标题。
 *
 * 为什么要这一步：问题库存的是"用户会怎么搜"（口语、疑问句），
 * 标题库存的是"文章叫什么"（要有钩子、符合平台调性）。
 * 直接把问题当标题，发出去没人点，AI 也抓不到重点。
 *
 * 产物写进 titles 表，并把原问题写进 `keyword` 字段——
 * 这样后面生成文章、发布追踪都能顺着这条线回溯到"补的是哪道缺口"。
 */
final class GeoTitleGen
{
    private const MAX_TOKENS = 900;

    private const TIMEOUT = 90;

    /**
     * 为一道问题生成标题。
     *
     * @return array{ok:bool,titles:list<string>,library_id:int,model:string,error:string}
     */
    public function generate(string $keyword, string $platformSlug = '', int $libraryId = 0): array
    {
        $fail = static fn (string $msg): array => [
            'ok' => false, 'titles' => [], 'library_id' => 0, 'model' => '', 'error' => $msg,
        ];

        $keyword = trim($keyword);
        if (mb_strlen($keyword, 'UTF-8') < 4) {
            return $fail('问题太短，至少 4 个字');
        }

        $platforms = GeoPlatforms::all();
        $platform = $platformSlug !== '' && isset($platforms[$platformSlug]) ? $platforms[$platformSlug] : null;
        $platformName = $platform !== null ? (string) ($platform['name'] ?? $platformSlug) : '通用';

        // 平台标题风格：字数上限、语气，都从配置里取，保持和正文生成一致
        $styleHint = '';
        if ($platform !== null) {
            $words = (array) ($platform['words'] ?? [0, 0]);
            $styleHint = "目标平台：{$platformName}\n"
                .'平台标题习惯：'.($platformSlug === 'xiaohongshu'
                    ? '偏口语、可以用数字和情绪词，但不要浮夸'
                    : ($platformSlug === 'zhihu'
                        ? '偏理性、信息量大，常用"为什么/怎么/有哪些"这类结构'
                        : '信息明确、说清利益点，不要标题党'))
                ."\n正文长度参考：{$words[0]}–{$words[1]} 字（标题要能撑起这个体量的正文）\n";
        }

        $sys = '你是 GEO 内容编辑，擅长把用户的口语提问改写成适合平台发布、同时容易被 AI 搜索引用的标题。只输出 JSON。';
        $user = "用户问题：{$keyword}\n\n{$styleHint}\n"
            ."请把这个用户问题改写成 3 个标题。要求：\n"
            ."1. 保留原问题的核心意图——用户想知道什么，标题就要回应什么，不能偷换\n"
            ."2. 每个标题 15–30 字，要有具体信息量（数字、条件、方法），不要空泛\n"
            ."3. 不要标题党、不要夸大、不要用「震惊」「必看」这类词\n"
            ."4. 三个标题角度要有区别：一个直答型、一个方法型、一个避坑型\n"
            ."5. 标题里不要出现品牌名（正文里再提）\n\n"
            .'只输出 JSON：{"titles":["标题1","标题2","标题3"]}';

        try {
            $res = GeoChat::complete($sys, $user, self::MAX_TOKENS, 0.7, self::TIMEOUT);
        } catch (\Throwable $e) {
            return $fail('模型调用失败：'.mb_substr($e->getMessage(), 0, 120, 'UTF-8'));
        }

        $raw = trim((string) $res['content']);
        $titles = [];

        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $json = json_decode($m[0], true);
            foreach ((array) ($json['titles'] ?? []) as $t) {
                $t = trim((string) $t);
                if ($t !== '' && mb_strlen($t, 'UTF-8') >= 8) {
                    $titles[] = $t;
                }
            }
        }
        // 兜底：模型没给 JSON 时，按行取
        if ($titles === []) {
            foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
                $line = trim(preg_replace('/^[\d\.\-\*\s]+/u', '', $line) ?? '');
                if (mb_strlen($line, 'UTF-8') >= 8 && mb_strlen($line, 'UTF-8') <= 40) {
                    $titles[] = $line;
                }
            }
        }

        $titles = array_slice(array_values(array_unique($titles)), 0, 3);
        if ($titles === []) {
            return $fail('模型没有返回可用标题，可以重试一次');
        }

        // 落到标题库
        $targetLib = $libraryId > 0 ? $libraryId : $this->pickLibrary();
        if ($targetLib <= 0) {
            return $fail('还没建标题库，先去内容资产建一个');
        }

        $saved = 0;
        foreach ($titles as $t) {
            try {
                $exists = DB::table('titles')
                    ->where('library_id', $targetLib)
                    ->where('title', mb_substr($t, 0, 480, 'UTF-8'))
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('titles')->insert([
                    'library_id' => $targetLib,
                    'title' => mb_substr($t, 0, 480, 'UTF-8'),
                    'keyword' => mb_substr($keyword, 0, 190, 'UTF-8'),
                    'is_ai_generated' => true,
                    'used_count' => 0,
                    'usage_count' => 0,
                    'created_at' => now(),
                ]);
                $saved++;
            } catch (\Throwable $e) {
                // 单条失败不影响其他
            }
        }

        if ($saved === 0) {
            return $fail('标题都已在库中（或写入失败），换个角度再试');
        }

        return [
            'ok' => true,
            'titles' => $titles,
            'library_id' => $targetLib,
            'model' => (string) ($res['model'] ?? ''),
            'error' => '',
        ];
    }

    /** 挑一个标题库存放：优先名字里带「缺口」或「声量」的，否则用第一个 */
    private function pickLibrary(): int
    {
        try {
            $lib = DB::table('title_libraries')->where('name', 'like', '%缺口%')->first()
                ?? DB::table('title_libraries')->where('name', 'like', '%声量%')->first()
                ?? DB::table('title_libraries')->orderBy('id')->first();

            return (int) ($lib->id ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }
}
