#!/usr/bin/env bash
# 第二批验证：用「冰溪 A 品牌认知」库里**已存在**的 3 道题跑一批，
# 这样 categoryBreakdown() 的 A 块和 authorityGapSamples()（信源缺口）都能对上关键词，
# 才能看出「带 batch / 不带 batch」两组数字的真实差别。
set -u
HOST="localhost"; BASE="http://localhost:18080"; ADMIN="$BASE/geo_admin"; COOKIE="/tmp/covbatch.cookie"
psql_q() { docker exec geoflow-postgres-prod psql -U geo_user -d geo_flow -t -A -c "$1" 2>/dev/null | tr -d '\r'; }
psql_t() { docker exec geoflow-postgres-prod psql -U geo_user -d geo_flow -c "$1" 2>/dev/null | tr -d '\r'; }

echo "== 发起核查：3 道已存在于词库的 A 类题 × 1 次 =="
curl -s -b "$COOKIE" -H "Host: $HOST" -o /tmp/cov3_run.html -w "plan HTTP=%{http_code}\n" -G "$ADMIN/coverage-check" \
  --data-urlencode "plan=1" \
  --data-urlencode "brand=冰溪" \
  --data-urlencode "url=https://www.binsis.cn/" \
  --data-urlencode "competitors=薇诺娜,理肤泉" \
  --data-urlencode "rounds=1" \
  --data-urlencode "lib=0" \
  --data-urlencode "questions=冰溪是哪个国家的护肤品牌？
冰溪这个牌子成立多少年了？
冰溪是和哪家医院或大学一起研发的？"

BID3=$(psql_q "select id from ai_visibility_batches order by id desc limit 1;")
echo "批次 id=$BID3"
psql_t "select id, brand_slug, brand_name, has_site, questions_count, rounds, runs_count, valid_count, status from ai_visibility_batches where id=$BID3;"

for i in 0 1 2; do
  curl -s -b "$COOKIE" -H "Host: $HOST" -H "Accept: application/json" \
    "$ADMIN/coverage-check?probe=1&i=$i" -o "/tmp/cov3_p$i.json"
  python -c "
import json
d=json.load(open('/tmp/cov3_p$i.json',encoding='utf-8'))
print('  题%s %-28s type=%-10s rank=%-4s run_ids=%s' % (d.get('i'), str(d.get('question'))[:26], d.get('covered_type') or '未进前10', d.get('best_rank'), d.get('run_ids')))
"
done

echo ""
echo "== 批次最终状态 =="
psql_t "select id, questions_count, rounds, runs_count, valid_count, status, updated_at from ai_visibility_batches where id=$BID3;"
psql_t "select br.run_id, r.keyword, (select count(*) from ai_visibility_sources s where s.ai_visibility_run_id=br.run_id) as srcs from ai_visibility_batch_runs br join ai_visibility_runs r on r.id=br.run_id where br.batch_id=$BID3 order by br.run_id;"

echo ""
echo "== 报告页：不带 batch（全部历史） =="
curl -s -b "$COOKIE" -H "Host: $HOST" -o /tmp/cov3_all.html -w "HTTP=%{http_code}\n" "$ADMIN/coverage-check?report=1&brand=binsis"
echo "-- A 块 --"
grep -oE 'A 品牌认知</b>[^<]*' /tmp/cov3_all.html | head -2
grep -oE '[0-9]+ 题 / [0-9]+ 次采样（其中 [0-9]+ 次有信源）' /tmp/cov3_all.html | head -6
grep -oE '官网第 1 名 [0-9]+%（[0-9]+/[0-9]+）|完全缺位 [0-9]+%（[0-9]+/[0-9]+）' /tmp/cov3_all.html | head -4
echo "-- 信源缺口 --"
grep -oE '<b>[0-9]+</b> 次有效采样 / <b>[0-9]+</b> 道题' /tmp/cov3_all.html | head -1

echo ""
echo "== 报告页：带 batch=$BID3（只算这一批） =="
curl -s -b "$COOKIE" -H "Host: $HOST" -o /tmp/cov3_b.html -w "HTTP=%{http_code}\n" "$ADMIN/coverage-check?report=1&brand=binsis&batch=$BID3"
echo "-- A 块 --"
grep -oE 'A 品牌认知</b>[^<]*' /tmp/cov3_b.html | head -2
grep -oE '[0-9]+ 题 / [0-9]+ 次采样（其中 [0-9]+ 次有信源）' /tmp/cov3_b.html | head -6
grep -oE '官网第 1 名 [0-9]+%（[0-9]+/[0-9]+）|完全缺位 [0-9]+%（[0-9]+/[0-9]+）' /tmp/cov3_b.html | head -4
echo "-- 信源缺口 --"
grep -oE '<b>[0-9]+</b> 次有效采样 / <b>[0-9]+</b> 道题' /tmp/cov3_b.html | head -1

echo ""
echo "== 下拉（当前品牌 binsis） =="
grep -oE '<option value="[0-9]*"[^>]*>[^<]*</option>' /tmp/cov3_all.html | head -6
echo "== 下拉选中态（batch=$BID3） =="
grep -oE '<option value="[0-9]*" selected>[^<]*</option>' /tmp/cov3_b.html | head -2
echo ""
echo "BID3=$BID3"
