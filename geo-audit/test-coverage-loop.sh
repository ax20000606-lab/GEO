#!/usr/bin/env bash
# =============================================================================
# 覆盖核查闭环 · 端到端模拟测试
#
# 验证这条链路能不能真的跑通：
#   覆盖核查（真实搜索排位）→ 数据进数据中心 → 报告统计 → 未覆盖建任务草稿
#
# 用法（Git Bash）：
#   bash /d/GEOFlow/geo-audit/test-coverage-loop.sh
#
# 说明：
#   · 会真的调 DeepSeek 搜索 API（每题 1 次，4 题约 0.02 元）
#   · 会真的建 1 个标题库 + 1 个任务，测完自动清理
#   · 全程只读+建测试数据，不碰已有业务数据
# =============================================================================

set -u

HOST="localhost"
BASE="http://localhost:18080"
ADMIN="$BASE/geo_admin"
COOKIE="/tmp/geoloop.cookie"
BRAND="冰溪"
SITE="https://www.binsis.cn/"
COMP="薇诺娜,理肤泉"
LIMIT=4
# 每题材采样轮数：1 = 快（约 15 秒跑完）；3 = 看命中率与波动（约 45 秒）
# 可用环境变量覆盖：ROUNDS=3 bash test-coverage-loop.sh
ROUNDS="${ROUNDS:-1}"

PASS=0
FAIL=0
ok()   { echo "  [PASS] $1"; PASS=$((PASS+1)); }
bad()  { echo "  [FAIL] $1"; FAIL=$((FAIL+1)); }
info() { echo "        $1"; }

psql_q() {
  # 注意：不要 tr -d ' '，psql -t -A 本身不带对齐空格；只清 Windows 的 \r
  docker exec geoflow-postgres-prod psql -U geo_user -d geo_flow -t -A -c "$1" 2>/dev/null | tr -d '\r'
}

echo "=============================================================="
echo " 覆盖核查闭环 · 端到端模拟测试"
echo " 品牌：$BRAND   站点：$SITE   题数：$LIMIT   采样 $ROUNDS 轮/题"
echo "=============================================================="

# ---------------------------------------------------------------------------
echo ""
echo "【0】基线快照"
# ---------------------------------------------------------------------------
RUNS0=$(psql_q "select count(*) from ai_visibility_runs;")
TASKS0=$(psql_q "select count(*) from tasks;")
LIBS0=$(psql_q "select count(*) from title_libraries;")
info "ai_visibility_runs=$RUNS0  tasks=$TASKS0  title_libraries=$LIBS0"

# ---------------------------------------------------------------------------
echo ""
echo "【1】登录后台"
# ---------------------------------------------------------------------------
rm -f "$COOKIE"
TOKEN=$(curl -s -c "$COOKIE" -H "Host: $HOST" "$ADMIN/login" \
  | grep -oE 'name="_token" value="[^"]+"' | head -1 | sed 's/.*value="//;s/"//')
CODE=$(curl -s -b "$COOKIE" -c "$COOKIE" -o /dev/null -w "%{http_code}" -H "Host: $HOST" \
  -d "_token=$TOKEN&username=admin&password=GeoFlow@2026" "$ADMIN/login")
if [ "$CODE" = "302" ]; then ok "登录成功 (302)"; else bad "登录失败 (HTTP $CODE)"; exit 1; fi

# ---------------------------------------------------------------------------
echo ""
echo "【2】覆盖核查表单页"
# ---------------------------------------------------------------------------
CODE=$(curl -s -b "$COOKIE" -H "Host: $HOST" -o /tmp/loop_form.html -w "%{http_code}" "$ADMIN/coverage-check")
SIZE=$(wc -c < /tmp/loop_form.html)
if [ "$CODE" = "200" ] && grep -q 'name="brand"' /tmp/loop_form.html; then
  ok "表单页渲染正常 (HTTP $CODE, $SIZE 字节)"
else
  bad "表单页异常 (HTTP $CODE)"
fi
if grep -q "检索层" /tmp/loop_form.html; then ok "页面已标注为「检索层」（不是语料层）"; else bad "未标注检索层"; fi

# ---------------------------------------------------------------------------
echo ""
echo "【3】必填校验（不填 URL 应被拦下）"
# ---------------------------------------------------------------------------
curl -s -b "$COOKIE" -H "Host: $HOST" -o /tmp/loop_nourl.html \
  "$ADMIN/coverage-check?plan=1&brand=%E5%86%B0%E6%BA%AA&lib=0&limit=4&questions=%E5%86%B0%E6%BA%AA%E6%80%8E%E4%B9%88%E6%A0%B7"
if grep -q "请填写自有站点 URL" /tmp/loop_nourl.html; then
  ok "缺 URL 被正确拦截"
else
  bad "缺 URL 竟然放行了"
fi

# ---------------------------------------------------------------------------
echo ""
echo "【4】发起核查 → 生成清单"
# ---------------------------------------------------------------------------
Q1="%E5%86%B0%E6%BA%AA%E6%8A%A4%E8%82%A4%E5%93%81%E6%80%8E%E4%B9%88%E6%A0%B7"      # 冰溪护肤品怎么样
Q2="%E6%95%8F%E6%84%9F%E8%82%8C%E6%8A%A4%E8%82%A4%E5%93%81%E6%8E%A8%E8%8D%90"      # 敏感肌护肤品推荐
Q3="%E5%86%B0%E6%BA%AA%E9%80%82%E5%90%88%E4%BB%80%E4%B9%88%E8%82%A4%E8%B4%A8"      # 冰溪适合什么肤质
Q4="%E6%95%8F%E6%84%9F%E8%82%8C%E8%83%BD%E4%B8%8D%E8%83%BD%E7%94%A8%E9%85%B8"      # 敏感肌能不能用酸
SITE_ENC="https%3A%2F%2Fwww.binsis.cn%2F"
BRAND_ENC="%E5%86%B0%E6%BA%AA"
COMP_ENC="%E8%96%87%E8%AF%BA%E5%A8%9C%2C%E7%90%86%E8%82%A4%E6%B3%89"
QUESTIONS="$Q1%0A$Q2%0A$Q3%0A$Q4"

CODE=$(curl -s -b "$COOKIE" -H "Host: $HOST" -o /tmp/loop_run.html -w "%{http_code}" \
  "$ADMIN/coverage-check?plan=1&brand=$BRAND_ENC&url=$SITE_ENC&competitors=$COMP_ENC&lib=0&limit=$LIMIT&rounds=$ROUNDS&questions=$QUESTIONS")
TOTAL=$(grep -oE 'var TOTAL=[0-9]+' /tmp/loop_run.html | head -1 | grep -oE '[0-9]+')
if [ "$CODE" = "200" ] && [ "${TOTAL:-0}" = "$LIMIT" ]; then
  ok "核查清单生成成功（TOTAL=$TOTAL）"
else
  bad "清单生成异常 (HTTP $CODE, TOTAL=${TOTAL:-无})"
fi

# ---------------------------------------------------------------------------
echo ""
echo "【5】逐题真实搜索（每题一次 DeepSeek 搜索调用）"
# ---------------------------------------------------------------------------
for i in 0 1 2 3; do
  curl -s -b "$COOKIE" -H "Host: $HOST" -H "Accept: application/json" \
    "$ADMIN/coverage-check?probe=1&i=$i" -o "/tmp/loop_p$i.json"
  # 用管道喂给 python：Windows 版 Python 不认 Git Bash 的 /tmp 绝对路径
  LINE=$(cat "/tmp/loop_p$i.json" | python -c "
import json,sys
try:
    d=json.load(sys.stdin)
except Exception:
    print('PARSE_FAIL')
    sys.exit()
if d.get('error'):
    print('ERROR | ' + str(d['error'])[:60]); sys.exit()
print('%-22s | %-8s | %-6s | 第1名=%-24s | 竞品=%s' % (
    str(d.get('question'))[:20],
    d.get('covered_type') or '未进前10',
    ('第%s位' % d.get('best_rank')) if d.get('best_rank') else '-',
    str(d.get('top1_domain'))[:22],
    ','.join((d.get('hit_competitors') or {}).keys()) or '-'))
")
  echo "  题$((i+1)): $LINE"
  if echo "$LINE" | grep -qE "PARSE_FAIL|ERROR"; then
    bad "题$((i+1)) 核查失败"
  else
    ok "题$((i+1)) 核查成功"
  fi
done

# ---------------------------------------------------------------------------
echo ""
echo "【6】数据是否进了数据中心"
# ---------------------------------------------------------------------------
RUNS1=$(psql_q "select count(*) from ai_visibility_runs;")
DELTA=$((RUNS1 - RUNS0))
if [ "$DELTA" -ge "$LIMIT" ]; then
  ok "ai_visibility_runs 新增 $DELTA 条（核查即采样，不用另跑采集）"
else
  bad "ai_visibility_runs 只新增 $DELTA 条，期望 ≥ $LIMIT"
fi

SRC=$(psql_q "select count(*) from ai_visibility_sources s join ai_visibility_runs r on r.id=s.ai_visibility_run_id where r.id > $RUNS0;")
info "新增结果条目（sources）=$SRC"
if [ "$SRC" -gt 0 ]; then ok "搜索结果明细已入库"; else bad "sources 为空"; fi

# ---------------------------------------------------------------------------
echo ""
echo "【7】报告页统计"
# ---------------------------------------------------------------------------
CODE=$(curl -s -b "$COOKIE" -H "Host: $HOST" -o /tmp/loop_rep.html -w "%{http_code}" "$ADMIN/coverage-check?report=1")
if [ "$CODE" = "200" ] && grep -q "核查结论" /tmp/loop_rep.html; then
  ok "报告页渲染成功"
else
  bad "报告页异常 (HTTP $CODE)"
fi
for K in "前三率" "自有页面进前 10" "仅被提及" "未进前 10" "去数据中心看趋势"; do
  if grep -q "$K" /tmp/loop_rep.html; then ok "报告含「$K」"; else bad "报告缺「$K」"; fi
done
TODO=$(grep -oE '待补问题：[0-9]+ 条' /tmp/loop_rep.html | head -1)
info "$TODO"

# ---------------------------------------------------------------------------
echo ""
echo "【8】未覆盖 → 建任务草稿"
# ---------------------------------------------------------------------------
CODE=$(curl -s -b "$COOKIE" -H "Host: $HOST" -o /tmp/loop_task.html -w "%{http_code}" \
  "$ADMIN/coverage-check?to-tasks=1&picked=0,1")
if grep -q "任务草稿已生成" /tmp/loop_task.html; then
  ok "任务草稿创建成功"
else
  MSG=$(grep -oE 'g-err">[^<]*' /tmp/loop_task.html | head -1 | sed 's/g-err">//')
  bad "任务草稿创建失败：${MSG:-未知}"
fi

TASKS1=$(psql_q "select count(*) from tasks;")
LIBS1=$(psql_q "select count(*) from title_libraries;")
info "tasks: $TASKS0 → $TASKS1   title_libraries: $LIBS0 → $LIBS1"

NEWTASK=$(psql_q "select id from tasks order by id desc limit 1;")
NEWLIB=$(psql_q "select id from title_libraries order by id desc limit 1;")
if [ "$TASKS1" -gt "$TASKS0" ]; then
  ok "任务已建 (id=$NEWTASK)"
  STATUS=$(psql_q "select status from tasks where id=$NEWTASK;")
  LIM=$(psql_q "select article_limit from tasks where id=$NEWTASK;")
  TLB=$(psql_q "select title_library_id from tasks where id=$NEWTASK;")
  [ "$STATUS" = "paused" ] && ok "任务为暂停态（不会自动开跑）" || bad "任务状态=$STATUS，应为 paused"
  [ "$LIM" = "2" ] && ok "article_limit=2（等于勾选的问题数）" || info "article_limit=$LIM"
  [ "$TLB" = "$NEWLIB" ] && ok "任务引用了新建的标题库 #$NEWLIB" || bad "标题库未绑定"
else
  bad "任务没有创建"
fi

TCOUNT=$(psql_q "select title_count from title_libraries where id=$NEWLIB;")
[ "$TCOUNT" = "2" ] && ok "标题库写入 2 条标题（问题即标题）" || info "标题库 title_count=$TCOUNT"
psql_q "select title from titles where library_id=$NEWLIB order by id;" | while read -r t; do
  [ -n "$t" ] && echo "        · $t"
done

# ---------------------------------------------------------------------------
echo ""
echo "【9】数据中心 KPI（按品牌口径）"
# ---------------------------------------------------------------------------
curl -s -b "$COOKIE" -H "Host: $HOST" -o /tmp/loop_av.html "$ADMIN/analytics/ai-visibility?brand=binsis"
VIS=$(cat /tmp/loop_av.html | python -c "
import re,sys
h=sys.stdin.read()
m=re.search(r'品牌可见度.{0,400}?([0-9]+(?:\.[0-9])?)%', h, re.S)
print(m.group(1) if m else '')
")
if [ -z "$VIS" ]; then
  bad "数据中心页面没解析出品牌可见度（检查 analytics 页是否可访问）"
else
  info "品牌可见度（brand=binsis）= ${VIS}%"
  if [ "$VIS" != "0.0" ]; then
    ok "数据中心已能反映冰溪的真实可见度"
  else
    bad "品牌可见度是 0.0%，品牌口径可能没生效"
  fi
fi

# ---------------------------------------------------------------------------
echo ""
echo "【10】发布追踪：内容资产 → 登记发布 → 轮询收录 → 归因"
# ---------------------------------------------------------------------------
# 发布追踪页
PT_CODE=$(curl -s -b "$COOKIE" -H "Host: localhost" -o /tmp/loop_pt.html -w "%{http_code}" "$ADMIN/publish-track")
if [ "$PT_CODE" = "200" ]; then ok "发布追踪页可访问"; else bad "发布追踪页返回 $PT_CODE"; fi

# 两张表是否就位
TBL=$(psql_q "select count(*) from information_schema.tables where table_name in ('geo_content_assets','geo_publish_records');")
if [ "$TBL" = "2" ]; then ok "发布追踪数据表已就位（assets + records）"; else bad "数据表缺失，只有 $TBL/2 张"; fi

# 内容工坊产出自带资产？用一条测试资产验证整条链路（不依赖用户是否生成过）
psql_q "insert into geo_content_assets (platform, platform_name, mode, title, topic, brand, words, status, created_at, updated_at) values ('zhihu','知乎','explain','[E2E测试] 发布追踪','测试主题','测试品牌',1200,'draft',now(),now());" > /dev/null
TESTASSET=$(psql_q "select id from geo_content_assets order by id desc limit 1;")
info "测试内容资产 id=$TESTASSET（模拟内容工坊产出）"

# 找一条真实采到的知乎链接，用它验证 URL 归一化 + 命中判定
ZHURL=$(psql_q "select url from ai_visibility_sources where domain='zhuanlan.zhihu.com' order by id desc limit 1;")
if [ -n "$ZHURL" ]; then
  # 关键测试：故意把 utm 参数去掉再登记，看能不能匹配到带 utm 的采集记录
  CLEANURL=$(echo "$ZHURL" | sed 's/?.*$//')
  info "登记链接（已去掉 utm 参数）：$CLEANURL"

  curl -s -b "$COOKIE" -H "Host: localhost" -o /dev/null -G "$ADMIN/publish-track" \
    --data-urlencode "add=1" --data-urlencode "asset=$TESTASSET" \
    --data-urlencode "url=$CLEANURL"

  RECCNT=$(psql_q "select count(*) from geo_publish_records where asset_id=$TESTASSET;")
  if [ "$RECCNT" = "1" ]; then ok "发布记录已登记"; else bad "发布记录数异常：$RECCNT"; fi

  RECPLAT=$(psql_q "select platform from geo_publish_records where asset_id=$TESTASSET limit 1;")
  if [ "$RECPLAT" = "zhihu" ]; then ok "平台自动识别正确（zhihu）"; else bad "平台识别错误：$RECPLAT"; fi

  # 重复登记应被挡下
  curl -s -b "$COOKIE" -H "Host: localhost" -o /dev/null -G "$ADMIN/publish-track" \
    --data-urlencode "add=1" --data-urlencode "asset=$TESTASSET" \
    --data-urlencode "url=$CLEANURL?utm_source=dup"
  DUPCNT=$(psql_q "select count(*) from geo_publish_records where asset_id=$TESTASSET;")
  if [ "$DUPCNT" = "1" ]; then ok "重复登记被拦下（URL 指纹去重生效）"; else bad "重复登记没拦住，记录数=$DUPCNT"; fi

  # 轮询收录
  curl -s -b "$COOKIE" -H "Host: localhost" -o /dev/null "$ADMIN/publish-track?poll=1"
  RECSTAT=$(psql_q "select status from geo_publish_records where asset_id=$TESTASSET limit 1;")
  RECRANK=$(psql_q "select coalesce(best_rank::text,'-') from geo_publish_records where asset_id=$TESTASSET limit 1;")
  if [ "$RECSTAT" = "cited" ] || [ "$RECSTAT" = "dropped" ]; then
    ok "轮询命中：状态=$RECSTAT，最好排位=$RECRANK（说明去掉 utm 后仍匹配到原始采集记录）"
  else
    bad "轮询未命中：状态=$RECSTAT（URL 归一化可能失效）"
  fi

  # 归因页
  curl -s -b "$COOKIE" -H "Host: localhost" -o /tmp/loop_pd.html "$ADMIN/publish-track?asset=$TESTASSET"
  if grep -q "被这些提问采到了" /tmp/loop_pd.html; then
    ok "归因明细正常渲染（能看到被哪个提问采到）"
  else
    bad "归因明细没渲染出来"
  fi
else
  info "采集数据里没有知乎链接，跳过命中验证（仍验证登记与去重）"
  curl -s -b "$COOKIE" -H "Host: localhost" -o /dev/null -G "$ADMIN/publish-track" \
    --data-urlencode "add=1" --data-urlencode "asset=$TESTASSET" \
    --data-urlencode "url=https://zhuanlan.zhihu.com/p/000000"
  RECCNT=$(psql_q "select count(*) from geo_publish_records where asset_id=$TESTASSET;")
  if [ "$RECCNT" = "1" ]; then ok "发布记录已登记（命中验证跳过）"; else bad "发布记录登记失败"; fi
fi

# ---------------------------------------------------------------------------
echo ""
echo "【11】清理测试数据"
# ---------------------------------------------------------------------------
# 任务和标题库是假的，必须删（否则污染任务列表）。
# ai_visibility_runs 保留 —— 那是真实搜索采样，留着正好让数据中心有数据可看。
# 想连采样一起清掉，把下面第 3 行 psql_q 取消注释即可。
psql_q "delete from tasks where id=$NEWTASK and name like 'GEO｜%未覆盖问题%';" > /dev/null
psql_q "delete from title_libraries where id=$NEWLIB and name like 'GEO未覆盖｜%';" > /dev/null
psql_q "delete from geo_publish_records where asset_id=$TESTASSET;" > /dev/null
psql_q "delete from geo_content_assets where id=$TESTASSET and title like '[E2E测试]%';" > /dev/null
# psql_q "delete from ai_visibility_runs where id > $RUNS0;" > /dev/null
AFTER_T=$(psql_q "select count(*) from tasks;")
AFTER_L=$(psql_q "select count(*) from title_libraries;")
AFTER_R=$(psql_q "select count(*) from ai_visibility_runs;")
info "清理后：tasks=$AFTER_T (原 $TASKS0)  libs=$AFTER_L (原 $LIBS0)  runs=$AFTER_R (原 $RUNS0，本次采样已保留)"
[ "$AFTER_T" = "$TASKS0" ] && [ "$AFTER_L" = "$LIBS0" ] && ok "测试建的任务/标题库已清理干净" || bad "清理后任务或标题库数与基线不一致"

# ---------------------------------------------------------------------------
echo ""
echo "=============================================================="
echo " 结果：PASS=$PASS  FAIL=$FAIL"
if [ "$FAIL" -eq 0 ]; then
  echo " 闭环 ✅ 全链路通"
else
  echo " 闭环 ❌ 有 $FAIL 项没过，看上面 FAIL 行"
fi
echo "=============================================================="
