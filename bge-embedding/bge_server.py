"""
OpenAI 兼容的本地 embedding 服务
================================

用途：把本地 bge 向量模型包成一个 OpenAI 兼容的 /embeddings 接口，
     让 GEOFlow 的知识库能用上真向量化（否则它只能回退到无向量的降级模式）。

对接对象：GEOFlow `KnowledgeChunkSyncService::requestOpenAiCompatibleEmbeddings()`
  - 请求：POST {api_url}/embeddings
          body = {"model": "...", "input": ["文本1", "文本2", ...]}   ← input 是数组
  - 响应：{"data": [{"index": 0, "embedding": [ ... ]}, ...]}
          GEOFlow 按 data[].index 排序取值

启动：uvicorn bge_server:app --host 0.0.0.0 --port 8000
环境变量：
  BGE_MODEL_PATH  模型目录（默认 /model，容器内挂载点）
  BGE_DEVICE      cpu / cuda（默认 cpu）
"""

import logging
import os
import time
from contextlib import asynccontextmanager
from typing import Union

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
)
log = logging.getLogger("bge-embedding")

MODEL_PATH = os.environ.get("BGE_MODEL_PATH", "/model")
DEVICE = os.environ.get("BGE_DEVICE", "cpu")
# 显示用的模型名（挂载点是 /model，basename 会变成 "model"，所以允许显式指定）
MODEL_NAME = os.environ.get("BGE_MODEL_NAME", "") or "bge-small-zh-v1.5"

# 模型只在启动时加载一次，进程内常驻（避免每次请求都读盘）
_state: dict = {"model": None, "model_name": MODEL_NAME, "loaded_at": None}


def _embedding_dim(model) -> int | None:
    """兼容 sentence-transformers 新旧方法名（新版把 get_sentence_embedding_dimension 改名了）。"""
    for method in ("get_embedding_dimension", "get_sentence_embedding_dimension"):
        fn = getattr(model, method, None)
        if fn is not None:
            try:
                return int(fn())
            except Exception:  # noqa: BLE001
                return None
    return None


@asynccontextmanager
async def lifespan(_: FastAPI):
    """应用启动时加载模型，关闭时释放。"""
    from sentence_transformers import SentenceTransformer

    t0 = time.time()
    log.info("正在加载模型：%s（device=%s）", MODEL_PATH, DEVICE)
    _state["model"] = SentenceTransformer(MODEL_PATH, device=DEVICE)
    _state["loaded_at"] = time.time()
    log.info("模型就绪，耗时 %.1fs，向量维度 = %s", time.time() - t0, _embedding_dim(_state["model"]))
    yield
    _state["model"] = None
    log.info("模型已释放")


app = FastAPI(title="本地 bge embedding 服务（OpenAI 兼容）", lifespan=lifespan)


class EmbeddingRequest(BaseModel):
    model: Union[str, None] = None
    input: Union[str, list[str]]
    # 以下字段 GEOFlow 不会发，但其他客户端可能发；接受并忽略，避免报错
    encoding_format: Union[str, None] = None
    dimensions: Union[int, None] = None


def _encode(texts: list[str]):
    model = _state["model"]
    if model is None:
        raise HTTPException(status_code=503, detail="model not loaded yet, retry in a few seconds")
    if not texts:
        raise HTTPException(status_code=400, detail="input is empty")

    # normalize_embeddings=True：bge 官方建议，配合余弦相似度使用
    # 注意：**文档侧不加 query 前缀**（bge 的规范是只有查询侧加前缀）
    return model.encode(
        texts,
        normalize_embeddings=True,
        convert_to_numpy=True,
        show_progress_bar=False,
    )


@app.get("/")
def root():
    return {
        "service": "local bge embedding (OpenAI compatible)",
        "endpoints": ["POST /embeddings", "POST /v1/embeddings", "GET /health"],
    }


@app.get("/health")
def health():
    dim = _embedding_dim(_state["model"]) if _state["model"] is not None else None
    ready = _state["model"] is not None
    return {
        "status": "ok" if ready else "loading",
        "model": _state["model_name"],
        "dim": dim,
        "device": DEVICE,
        "loaded_seconds_ago": round(time.time() - _state["loaded_at"], 1) if _state["loaded_at"] else None,
    }


# 同时挂 /embeddings 和 /v1/embeddings：
# GEOFlow 拼的是 {api_url}/embeddings，若用户把 api_url 误填成带 /v1 的也能用
@app.post("/embeddings")
@app.post("/v1/embeddings")
async def embeddings(req: EmbeddingRequest):
    raw = [req.input] if isinstance(req.input, str) else list(req.input)
    texts = [t if isinstance(t, str) else str(t) for t in raw]

    t0 = time.time()
    vecs = _encode(texts)
    elapsed = time.time() - t0

    data = [
        {
            "object": "embedding",
            "index": i,
            "embedding": [float(x) for x in vector],
        }
        for i, vector in enumerate(vecs)
    ]

    # GEOFlow 只用 model + input，不看 usage；这里给个字符数近似，方便日志排查
    total_chars = sum(len(t) for t in texts)
    log.info(
        "编码 %d 条（共 %d 字符），耗时 %.2fs，维度 %d",
        len(texts), total_chars, elapsed, len(data[0]["embedding"]) if data else 0,
    )

    return {
        "object": "list",
        "data": data,
        "model": req.model or _state["model_name"] or "bge-small-zh-v1.5",
        "usage": {"prompt_tokens": total_chars, "total_tokens": total_chars},
    }
