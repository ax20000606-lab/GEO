#!/usr/bin/env bash
# =============================================================================
# 覆盖核查「结果落库 + 历史报告下拉」自测
#
# 验证：
#   1. 核查开始时建 ai_visibility_batches 记录（品牌/题数/采样次数/官网状态快照）
#   2. 每跑完一题把 run 挂进 ai_visibility_batch_runs
#   3. 跑完 syncBatch → runs_count / valid_count / status=completed
#   4. 报告页 ?batch=<id> 只统计该批 run；不带 batch 统计该品牌全部历史（两组数字不同）
#   5. 报告页顶部历史下拉（<select> + option 数）
#   6. 会话过期（新 cookie）后仍能按 batch 读历史报告
#   7. 回归：表单页 / 报告页 / to-tasks
#   8. 数据基线：tasks 9 / prompts 11 / geo_ai_outputs 10
#
# 会真的调搜索 API（3 题 + 2 题，各 1 次 ≈ ¥0.02）
# =============================================================================

set -u

HOST="localhost"
BASE="http://localhost:18080"
ADMIN="$BASE/geo_admin"
COOKIE="/tmp/covbatch.cookie"

PASS=0
FAIL=0
ok()   { echo "  [PASS] $1"; PASS=$((PASS+1)); }
bad()  { echo "  [FAIL] $1"; FAIL=$((FAIL+1)); }
info() { echo "        $1"; }

psql_q() { docker exec geoflow-postgres-prod psql -U geo_user -d geo_flow -t -A -c "$1" 2>/dev/null | tr -d '\r'; }
psql_t() { docker exec geoflow-postgres-prod psql -U geo_user -d geo_flow -c "$1" 2>/dev/null | tr -d '\r'; }

echo "=============================================================="
echo " 覆盖核查 · 批次落库 + 历史报告下拉 自测"
echo "=============================================================="

echo ""
echo "【0】基线"
B0=$(psql_q "select count(*) from ai_visibility_batches;")
BR0=$(psql_q "select count(*) from ai_visibility_batch_runs;")
RUNS0=$(psql_q "select count(*) from ai_visibility_runs;")
TASKS0=$(psql_q "select count(*) from tasks;")
PROMPTS0=$(psql_q "select count(*) from prompts;")
OUT0=$(psql_q "select count(*) from geo_ai_outputs;")
info "batches=$B0  batch_runs=$BR0  runs=$RUNS0  tasks=$TASKS0  prompts=$PROMPTS0  geo_ai_outputs=$OUT0"
[ "$B0" = "0" ] && ok "核查前 ai_visibility_batches = 0" || info "（已有 $B0 条，是上一轮测试留下的）"

echo ""
echo "【1】登录"
rm -f "$COOKIE"
TOKEN=$(curl -s -c "$COOKIE" -H "Host: $HOST" "$ADMIN/login" \
  | grep -oE 'name="_token" value="[^"]+"' | head -1 | sed 's/.*value="//;s/"//')
CODE=$(curl -s -b "$COOKIE" -c "$COOKIE" -o /dev/null -w "%{http_code}" -H "Host: $HOST" \
  -d "_token=$TOKEN&username=admin&password=GeoFlow@2026" "$ADMIN/login")
if [ "$CODE" = "302" ]; then ok "登录成功 (302)"; else bad "登录失败 (HTTP $CODE)"; exit 1; fi

# ---------------------------------------------------------------------------
# 批次 1：3 题 × 1 次
# ---------------------------------------------------------------------------
Q3="%E5%86%B0%E6%BA%AA%E6%8A%A4%E8%82%A4%E5%93%81%E6%80%8E%E4%B9%88%E6%A0%B7%0A%E6%95%8F%E6%84%9F%E8%82%8C%E6%8A%A4%E8%82%A4%E5%93%81%E6%8E%A8%E8%8D%90%0A%E6%95%8F%E6%84%9F%E8%82%8C%E8%83%BD%E4%B8%8D%E8%83%BD%E7%94%A8%E9%85%B8"
echo ""
echo "【2】批次 1：发起核查（3 题 × 1 次）"
CODE=$(curl -s -b "$COOKIE" -H "Host: $HOST" -o /tmp/cov_run.html -w "%{http_code}" \
  "$ADMIN/coverage-check?plan=1&brand=%E5%86%B0%E6%BA%AA&url=https%3A%2F%2Fwww.binsis.cn%2F&competitors=%E8%96%87%E8%AF%BA%E5%A8%9C%2C%E7%90%86%E8%82%A4%E6%B3%89&lib=0&rounds=1&questions=$Q3")
TOTAL=$(grep -oE 'var TOTAL=[0-9]+' /tmp/cov_run.html | head -1 | grep -oE '[0-9]+')
info "HTTP=$CODE  清单题数=${TOTAL:-无}"
BID1=$(psql_q "select id from ai_visibility_batches order by id desc limit 1;")
info "新建批次 id=$BID1"
psql_t "select id, brand_slug, brand_name, has_site, questions_count, rounds, runs_count, valid_count, status, source, created_by, created_at from ai_visibility_batches where id=$BID1;"

for i in 0 1 2; do
  curl -s -b "$COOKIE" -H "Host: $HOST" -H "Accept: application/json" \
    "$ADMIN/coverage-check?probe=1&i=$i" -o "/tmp/cov_p$i.json"
  cat "/tmp/cov_p$i.json" | python -c "
import json,sys
try:
    d=json.load(sys.stdin)
except Exception as e:
    print('    PARSE_FAIL', e); sys.exit()
print('    题%s: type=%-10s rank=%-4s run_ids=%s err=%s' % (
    d.get('i'), d.get('covered_type') or '未进前10', d.get('best_rank'),
    d.get('run_ids'), str(d.get('error') or '')[:36]))
"
done

echo "  批次 1 关联表："
psql_t "select br.batch_id, br.run_id, (select count(*) from ai_visibility_sources s where s.ai_visibility_run_id=br.run_id) as srcs from ai_visibility_batch_runs br where br.batch_id=$BID1 order by br.run_id;"
echo "  批次 1 最终字段："
psql_t "select id, questions_count, rounds, runs_count, valid_count, status, updated_at from ai_visibility_batches where id=$BID1;"

# ---------------------------------------------------------------------------
# 批次 2：2 题 × 1 次（让下拉里有多条）
# ---------------------------------------------------------------------------
Q2="%E5%86%B0%E6%BA%AA%E9%80%82%E5%90%88%E4%BB%80%E4%B9%88%E8%82%A4%E8%B4%A8%0A%E6%B2%B9%E7%9A%AE%E6%95%8F%E6%84%9F%E8%82%8C%E6%8A%A4%E8%82%A4%E6%B5%81%E7%A8%8B"
echo ""
echo "【3】批次 2：再来一批（2 题 × 1 次）"
CODE=$(curl -s -b "$COOKIE" -H "Host: $HOST" -o /tmp/cov_run2.html -w "%{http_code}" \
  "$ADMIN/coverage-check?plan=1&brand=%E5%86%B0%E6%BA%AA&url=https%3A%2F%2Fwww.binsis.cn%2F&competitors=%E8%96%87%E8%AF%BA%E5%A8%9C&lib=0&rounds=1&questions=$Q2")
BID2=$(psql_q "select id from ai_visibility_batches order by id desc limit 1;")
info "HTTP=$CODE  批次 id=$BID2"
for i in 0 1; do
  curl -s -b "$COOKIE" -H "Host: $HOST" -H "Accept: application/json" \
    "$ADMIN/coverage-check?probe=1&i=$i" -o "/tmp/cov2_p$i.json"
  cat "/tmp/cov2_p$i.json" | python -c "
import json,sys
try:
    d=json.load(sys.stdin)
except Exception as e:
    print('    PARSE_FAIL', e); sys.exit()
print('    题%s: type=%-10s rank=%-4s run_ids=%s' % (
    d.get('i'), d.get('covered_type') or '未进前10', d.get('best_rank'), d.get('run_ids')))
"
done
psql_t "select br.batch_id, br.run_id, (select count(*) from ai_visibility_sources s where s.ai_visibility_run_id=br.run_id) as srcs from ai_visibility_batch_runs br where br.batch_id=$BID2 order by br.run_id;"
psql_t "select id, questions_count, rounds, runs_count, valid_count, status from ai_visibility_batches where id=$BID2;"

# ---------------------------------------------------------------------------
echo ""
echo "【4】报告页按批次过滤"
C1=$(curl -s -b "$COOKIE" -H "Host: $HOST" -o /tmp/cov_all.html -w "%{http_code}" \
  "$ADMIN/coverage-check?report=1&brand=binsis")
C2=$(curl -s -b "$COOKIE" -H "Host: $HOST" -o /tmp/cov_b1.html -w "%{http_code}" \
  "$ADMIN/coverage-check?report=1&brand=binsis&batch=$BID1")
info "不带 batch HTTP=$C1   带 batch=$BID1 HTTP=$C2"
if [ "$C1" = "200" ] && [ "$C2" = "200" ]; then ok "两种口径都 200"; else bad "报告页异常"; fi

echo "  ---- 不带 batch（本品牌全部历史）----"
grep -oE '[0-9]+ 题 / [0-9]+ 次采样（其中 [0-9]+ 次有信源）|官网覆盖 [0-9]+ / [0-9]+' /tmp/cov_all.html | head -10
echo "  ---- 带 batch=$BID1（只算这一批）----"
grep -oE '[0-9]+ 题 / [0-9]+ 次采样（其中 [0-9]+ 次有信源）|官网覆盖 [0-9]+ / [0-9]+' /tmp/cov_b1.html | head -10

A_NUMS=$(grep -oE '官网覆盖 [0-9]+ / [0-9]+' /tmp/cov_all.html | tr '\n' ' ')
B_NUMS=$(grep -oE '官网覆盖 [0-9]+ / [0-9]+' /tmp/cov_b1.html | tr '\n' ' ')
info "all=[$A_NUMS]"
info "bat=[$B_NUMS]"
if [ "$A_NUMS" != "$B_NUMS" ]; then ok "两组数字不同（按批次过滤生效）"; else bad "两组数字相同（可能没过滤）"; fi

# ---------------------------------------------------------------------------
echo ""
echo "【5】历史下拉"
grep -oE '<select id="cov-batch"[^>]*>' /tmp/cov_all.html | head -1
echo "  option 数（含「全部历史」）：$(grep -oE '<option value="[0-9]*"' /tmp/cov_all.html | wc -l)"
grep -oE '<option value="[0-9]*"[^>]*>[^<]*</option>' /tmp/cov_all.html | head -6
echo "  页头文案（不带 batch）："
grep -oE '统计范围：<b>本品牌全部历史采样</b>[^<]{0,70}' /tmp/cov_all.html | head -1
echo "  页头文案（带 batch）："
grep -oE '本次核查：<b>[^<]*</b>[^<]{0,150}' /tmp/cov_b1.html | head -1

# ---------------------------------------------------------------------------
echo ""
echo "【6】会话过期后按批次看（新 cookie，模拟浏览器关掉）"
rm -f /tmp/cov_expired.cookie
TOKEN2=$(curl -s -c /tmp/cov_expired.cookie -H "Host: $HOST" "$ADMIN/login" \
  | grep -oE 'name="_token" value="[^"]+"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b /tmp/cov_expired.cookie -c /tmp/cov_expired.cookie -o /dev/null -H "Host: $HOST" \
  -d "_token=$TOKEN2&username=admin&password=GeoFlow@2026" "$ADMIN/login"
CODE=$(curl -s -b /tmp/cov_expired.cookie -H "Host: $HOST" -o /tmp/cov_hist.html -w "%{http_code}" \
  "$ADMIN/coverage-check?report=1&brand=binsis&batch=$BID1")
info "新会话 + batch=$BID1 HTTP=$CODE  大小=$(wc -c < /tmp/cov_hist.html)"
if grep -q '会话已过期' /tmp/cov_hist.html; then
  bad "新会话仍然报会话过期（历史报告读不到）"
else
  ok "新会话能按批次读出历史报告"
fi
echo "  只读提示 / 页头："
grep -oE '历史批次 · 只读|这是历史核查的留档' /tmp/cov_hist.html | head -2
grep -oE '本次核查：<b>[^<]*</b>[^<]{0,150}' /tmp/cov_hist.html | head -1
echo "  逐题结果区是否重建出题数："
grep -oE '本次核查题数</div><div[^>]*>[0-9]+' /tmp/cov_hist.html | head -1

echo ""
echo "同品牌不同批次对比（batch=$BID2）："
curl -s -b "$COOKIE" -H "Host: $HOST" -o /tmp/cov_b2.html -w "  HTTP=%{http_code}\n" \
  "$ADMIN/coverage-check?report=1&brand=binsis&batch=$BID2"
grep -oE '官网覆盖 [0-9]+ / [0-9]+' /tmp/cov_b2.html | head -6

# ---------------------------------------------------------------------------
echo ""
echo "【7】回归"
for U in "coverage-check" "coverage-check?report=1&brand=binsis" "coverage-check?report=1&brand=binsis&batch=$BID2" "coverage-check?to-tasks=1&picked=1"; do
  C=$(curl -s -b "$COOKIE" -H "Host: $HOST" -o /tmp/cov_reg.html -w "%{http_code}" "$ADMIN/$U")
  info "$U → $C"
done

echo ""
echo "【8】数据基线"
TASKS1=$(psql_q "select count(*) from tasks;")
PROMPTS1=$(psql_q "select count(*) from prompts;")
OUT1=$(psql_q "select count(*) from geo_ai_outputs;")
RUNS1=$(psql_q "select count(*) from ai_visibility_runs;")
info "tasks $TASKS0→$TASKS1   prompts $PROMPTS0→$PROMPTS1   geo_ai_outputs $OUT0→$OUT1   runs $RUNS0→$RUNS1"
[ "$TASKS1" = "$TASKS0" ] && ok "tasks 未变" || bad "tasks 变了"
[ "$PROMPTS1" = "$PROMPTS0" ] && ok "prompts 未变" || bad "prompts 变了"
[ "$OUT1" = "$OUT0" ] && ok "geo_ai_outputs 未变" || bad "geo_ai_outputs 变了"

echo ""
echo "=============================================================="
echo " PASS=$PASS  FAIL=$FAIL   测试批次：batch1=$BID1  batch2=$BID2"
echo "=============================================================="
