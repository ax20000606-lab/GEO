<?php

namespace App\Support\GeoAudit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 「内容工坊 → AI 生成文章」：在内容管理里选题、选平台，直接生成待发布草稿。
 *
 * 为什么取代原来的独立工坊：GEO 真正产文章的地方就是这里。
 * 原来要「工坊生成 → 复制 → 内容管理粘贴」，多一步搬运；现在生成完就是草稿，点开就能改。
 *
 * 一篇 = 一个平台（各平台结构差别太大，合并会互相将就）。
 * 勾 3 个平台就建 3 篇草稿。
 */
final class GeoArticleGenReport
{
    private const INPUT = 'width:100%;margin-top:6px;padding:9px 11px;border:1px solid #d1d5db;border-radius:9px;font-size:13px;font-family:inherit';

    private const LABEL = 'display:block;font-size:12px;color:#6b7280;font-weight:600';

    /**
     * 生成表单。
     *
     * @param  list<array{slug:string,name:string,words:array,daily:int}>  $platforms
     * @param  iterable  $categories
     */
    public static function formPage(array $platforms, $categories, array $old = [], string $error = ''): string
    {
        $base = self::base();
        $topic = self::e((string) ($old['topic'] ?? ''));
        $picked = (array) ($old['platforms'] ?? []);
        $catId = (int) ($old['category_id'] ?? 0);

        $err = $error !== ''
            ? '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:10px 14px;font-size:13px;color:#b91c1c;margin-bottom:14px">'.self::e($error).'</div>'
            : '';

        $cards = '';
        foreach ($platforms as $p) {
            $slug = (string) ($p['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $on = in_array($slug, $picked, true) || $picked === [];
            $words = (array) ($p['words'] ?? [0, 0]);
            $range = ((int) ($words[0] ?? 0)).'–'.((int) ($words[1] ?? 0)).' 字';

            $cards .= '<label class="ag-plat" style="display:block;border:1px solid '.($on ? '#4f46e5' : '#d1d5db').';'
                .'background:'.($on ? '#f5f3ff' : '#fff').';border-radius:10px;padding:10px 13px;cursor:pointer">'
                .'<div style="display:flex;align-items:center;gap:8px">'
                .'<input type="checkbox" class="ag-plat-box" name="platforms[]" value="'.self::e($slug).'"'.($on ? ' checked' : '').' style="margin:0">'
                .'<b style="font-size:13px">'.self::e((string) ($p['name'] ?? $slug)).'</b>'
                .'<span style="font-size:10.5px;color:#6b7280">'.$range.'</span></div>'
                .'</label>';
        }

        $catOpts = '';
        foreach ($categories as $c) {
            $id = (int) ($c->id ?? 0);
            if ($id <= 0) {
                continue;
            }
            $sel = $catId === $id ? ' selected' : '';
            $catOpts .= '<option value="'.$id.'"'.$sel.'>'.self::e((string) ($c->name ?? '')).'</option>';
        }

        $body = '<div class="g-hd"><h2 style="margin:0 0 4px;font-size:19px">AI 生成文章</h2>'
            .'<p class="g-cap" style="margin:0">选题 + 平台 → 直接生成待发布草稿，一篇一个平台。'
            .'生成完在列表里就能看到，点开可改可发。</p></div>'
            .$err
            .'<form method="post" action="'.$base.'" id="ag-form">'
            .self::csrf()
            .'<div class="g-card"><h3>1 · 写什么</h3>'
            .'<p class="g-cap">直接写用户会搜的那句话。建议从「声量对标」里 B 品类场景的缺口题里挑——'
            .'那是唯一没被占住的位置。</p>'
            .'<label style="'.self::LABEL.'">主题 / 用户问题'
            .'<input type="text" name="topic" value="'.$topic.'" required placeholder="例如：敏感肌能不能用酸、秋季干燥敏感肌用什么护肤品" style="'.self::INPUT.'"></label>'
            .'<label style="'.self::LABEL.';margin-top:14px">分类'
            .'<select name="category_id" style="'.self::INPUT.'">'.$catOpts.'</select></label>'
            .'</div>'
            .'<div class="g-card"><h3>2 · 发到哪些平台</h3>'
            .'<p class="g-cap">每个平台单独生成一篇（结构、字数、语气都不一样）。勾几个就出几篇。</p>'
            .'<div class="ag-plats" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin-top:12px">'.$cards.'</div>'
            .'</div>'
            .'<div class="g-card"><div style="display:flex;align-items:center;gap:14px">'
            .'<button type="submit" class="g-btn g-btn--primary" id="ag-submit">开始生成</button>'
            .'<span class="g-cap" style="margin:0" id="ag-eta">约 25–45 秒 / 每篇</span>'
            .'</div></div></form>'
            .'<div id="ag-progress" style="display:none"></div>'
            .'<script>(function(){'
            .'var boxes=document.querySelectorAll(".ag-plat-box");'
            .'function sync(){var n=0;for(var i=0;i<boxes.length;i++){if(boxes[i].checked)n++;}'
            .'var e=document.getElementById("ag-eta"),b=document.getElementById("ag-submit");'
            .'if(e){e.textContent=n===0?"请至少勾一个平台":("将生成 "+n+" 篇，约 "+(n*35)+" 秒");}'
            .'if(b){b.textContent=n>1?("生成 "+n+" 篇草稿"):"开始生成";}}'
            .'for(var i=0;i<boxes.length;i++){(function(b){b.addEventListener("change",function(){'
            .'var lb=b.closest("label");lb.style.borderColor=b.checked?"#4f46e5":"#d1d5db";'
            .'lb.style.background=b.checked?"#f5f3ff":"#fff";sync();});})(boxes[i]);}'
            .'sync();'
            .'var f=document.getElementById("ag-form");'
            .'if(f)f.addEventListener("submit",function(ev){'
            .'if(document.querySelectorAll(".ag-plat-box:checked").length===0){ev.preventDefault();return;}'
            .'var b=document.getElementById("ag-submit");if(b){b.disabled=true;b.style.opacity=".65";b.textContent="生成中…";}'
            .'if(window.geoBusy){window.geoBusy("正在生成文章","每个平台单独生成，请勿关闭页面");}});'
            .'})();</script>';

        return self::shell($body, 'AI 生成文章');
    }

    /**
     * 生成一个平台的文章并建草稿。
     *
     * @return array{ok:bool,article_id:int,platform:string,chars:int,error:string}
     */
    public function generateOne(string $topic, string $platformSlug, int $categoryId, int $authorId): array
    {
        $topic = trim($topic);
        if ($topic === '') {
            return ['ok' => false, 'article_id' => 0, 'platform' => '', 'chars' => 0, 'error' => '主题不能为空'];
        }

        $platforms = GeoPlatforms::all();
        // 空平台 = 通用写法，不套任何平台的字数/骨架约束
        if ($platformSlug !== '' && ! isset($platforms[$platformSlug])) {
            return ['ok' => false, 'article_id' => 0, 'platform' => '', 'chars' => 0, 'error' => '平台不存在'];
        }
        $platformName = $platformSlug === ''
            ? '通用'
            : (string) ($platforms[$platformSlug]['name'] ?? $platformSlug);

        try {
            $studio = new GeoContentStudio();
            $res = $studio->explain($topic, $platformSlug === ''
                ? ['model_id' => 0]
                : ['platform' => $platformSlug, 'model_id' => 0]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'article_id' => 0, 'platform' => $platformName, 'chars' => 0,
                'error' => mb_substr($e->getMessage(), 0, 120, 'UTF-8')];
        }

        // explain() 返回的正文字段叫 markdown，不叫 content
        $content = (string) ($res['markdown'] ?? '');
        if (trim($content) === '') {
            return ['ok' => false, 'article_id' => 0, 'platform' => $platformName, 'chars' => 0, 'error' => '模型没有返回内容'];
        }

        $title = $this->extractTitle($content, $topic);
        // 摘要/meta 取正文第一段的实义内容，而不是整篇压平后的前 150 字
        // （那样会把"核心摘要"标题和列表符号都带进去，很脏）
        $plain = $this->extractExcerpt($content);
        $catId = $categoryId > 0 ? $categoryId : $this->defaultCategoryId();

        try {
            $now = now();
            $id = (int) DB::table('articles')->insertGetId([
                'title' => mb_substr($title, 0, 480, 'UTF-8'),
                'slug' => Str::slug(mb_substr($title, 0, 60, 'UTF-8')).'-'.substr(md5($title.$platformSlug.microtime(true)), 0, 6),
                'excerpt' => mb_substr($plain, 0, 150, 'UTF-8'),
                'content' => self::markdownToHtml($content),
                'category_id' => $catId,
                'author_id' => $authorId > 0 ? $authorId : 1,
                'original_keyword' => mb_substr($topic, 0, 190, 'UTF-8'),
                'keywords' => '',
                'meta_description' => mb_substr($plain, 0, 150, 'UTF-8'),
                'status' => 'draft',
                'review_status' => 'pending',
                'is_ai_generated' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // 十维评分 + 平台合规检查一起存下来，文章里就能看到"这篇够不够 AI 友好"
            $scoring = (array) ($res['scoring'] ?? []);
            $check = (array) ($res['platform_check'] ?? []);
            if ($scoring !== [] || $check !== []) {
                DB::table('articles')->where('id', $id)->update([
                    'geo_scoring' => json_encode([
                        'scoring' => $scoring,
                        'platform_check' => $check,
                        'stats' => (array) ($res['stats'] ?? []),
                        'platform' => $platformSlug,
                        'graded_at' => now()->toDateTimeString(),
                    ], JSON_UNESCAPED_UNICODE),
                ]);
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'article_id' => 0, 'platform' => $platformName, 'chars' => 0,
                'error' => '建草稿失败：'.mb_substr($e->getMessage(), 0, 100, 'UTF-8')];
        }

        return [
            'ok' => true,
            'article_id' => $id,
            'platform' => $platformName,
            'chars' => (int) ($res['stats']['chars'] ?? mb_strlen($plain, 'UTF-8')),
            'error' => '',
        ];
    }

    /**
     * 摘要：优先取正文里第一段连续实义文字（跳过标题、列表符号、加粗标记）。
     */
    private function extractExcerpt(string $content): string
    {
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $t = trim($line);
            if ($t === '' || preg_match('/^#{1,6}\s/', $t) || preg_match('/^\s*[-*+]\s/', $t) || preg_match('/^\s*\d+\.\s/', $t)) {
                continue;
            }
            // 跳过模型常输出的元信息行（"标题：xxx""副标题：xxx"），这些不是正文
            if (preg_match('/^(标题|副标题|题目|导语|摘要)\s*[:：]/u', $t)) {
                continue;
            }
            $t = preg_replace('/[*_`>]/u', '', $t) ?? $t;
            $t = trim(preg_replace('/\s+/u', ' ', $t) ?? '');
            // 标题类短句通常 < 30 字，取第一段真正成句的内容
            if (mb_strlen($t, 'UTF-8') >= 30) {
                return mb_substr($t, 0, 150, 'UTF-8');
            }
        }

        // 兜底：整篇压平取前 150
        return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($content)) ?? ''), 0, 150, 'UTF-8');
    }

    /** 标题：优先取正文第一个 # 标题，没有就用主题 + 平台名 */
    private function extractTitle(string $content, string $topic): string
    {
        if (preg_match('/^#{1,2}\s+(.+)$/mu', $content, $m)) {
            return trim($m[1]);
        }

        return $topic;
    }

    private function defaultCategoryId(): int
    {
        try {
            $id = (int) (DB::table('categories')->orderBy('id')->value('id') ?? 0);

            return $id > 0 ? $id : 1;
        } catch (\Throwable) {
            return 1;
        }
    }

    /** 极简 markdown → HTML（够编辑器显示，不做完整解析） */
    private static function markdownToHtml(string $md): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $md) ?: [];
        $html = [];
        $inList = false;

        foreach ($lines as $line) {
            $t = rtrim($line);
            if (preg_match('/^(#{1,6})\s+(.+)$/u', $t, $m)) {
                if ($inList) {
                    $html[] = '</ul>';
                    $inList = false;
                }
                $lv = strlen($m[1]);
                $html[] = '<h'.$lv.'>'.htmlspecialchars($m[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</h'.$lv.'>';
                continue;
            }
            if (preg_match('/^\s*[-*]\s+(.+)$/u', $t, $m)) {
                if (! $inList) {
                    $html[] = '<ul>';
                    $inList = true;
                }
                $html[] = '<li>'.self::inlineMd($m[1]).'</li>';
                continue;
            }
            if ($inList) {
                $html[] = '</ul>';
                $inList = false;
            }
            if (trim($t) === '') {
                continue;
            }
            $html[] = '<p>'.self::inlineMd($t).'</p>';
        }
        if ($inList) {
            $html[] = '</ul>';
        }

        return implode("\n", $html);
    }

    private static function inlineMd(string $s): string
    {
        $s = htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $s = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s) ?? $s;

        return preg_replace('/`(.+?)`/u', '<code>$1</code>', $s) ?? $s;
    }

    private static function base(): string
    {
        return url(rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/').'/articles/ai-generate');
    }

    private static function csrf(): string
    {
        try {
            $t = (string) csrf_token();

            return $t === '' ? '' : '<input type="hidden" name="_token" value="'.self::e($t).'">';
        } catch (\Throwable) {
            return '';
        }
    }

    private static function shell(string $body, string $title): string
    {
        return '<style>'.GeoUi::css().'</style>'
            .'<div class="geo-ui" data-geo-title="'.self::e($title).'" '
            .'style="max-width:980px;margin:0 auto;padding:4px 4px 48px;font:14px/1.7 var(--g-font);color:#111827">'
            .'<div class="g-toolbar"><a class="g-btn g-btn--ghost" href="'.self::e(url(rtrim((string) config('geoflow.admin_base_path', '/geo_admin'), '/').'/articles')).'">← 返回内容工坊</a></div>'
            .$body.'</div>'.GeoUi::busyOverlay();
    }

    private static function e(?string $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
