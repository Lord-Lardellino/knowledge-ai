#!/usr/bin/env python3
import json
import os
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

os.environ.setdefault("TOKENIZERS_PARALLELISM", "false")

from sentence_transformers import SentenceTransformer

HOST = os.environ.get("KNOWLEDGE_BAAI_HOST", "127.0.0.1")
PORT = int(os.environ.get("KNOWLEDGE_BAAI_PORT", "8765"))
MODEL_NAME = os.environ.get("KNOWLEDGE_BAAI_MODEL", "BAAI/bge-m3")
DEVICE = os.environ.get("KNOWLEDGE_BAAI_DEVICE", "cpu")

model = SentenceTransformer(MODEL_NAME, device=DEVICE)


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path != "/health":
            self.send_error(404)
            return

        self.respond(200, {"ok": True, "model": MODEL_NAME, "device": DEVICE})

    def do_POST(self):
        if self.path != "/embed":
            self.send_error(404)
            return

        try:
            length = int(self.headers.get("Content-Length", "0"))
            payload = json.loads(self.rfile.read(length))
            texts = payload.get("texts") or []
            batch_size = int(payload.get("batch_size") or 8)

            if not isinstance(texts, list) or any(not isinstance(text, str) for text in texts):
                raise ValueError("texts must be a list of strings")

            embeddings = model.encode(
                texts,
                batch_size=batch_size,
                normalize_embeddings=True,
                convert_to_numpy=True,
                show_progress_bar=False,
            )

            self.respond(200, {"embeddings": embeddings.tolist()})
        except Exception as exc:
            self.respond(500, {"error": str(exc)})

    def log_message(self, fmt, *args):
        return

    def respond(self, status, payload):
        body = json.dumps(payload, separators=(",", ":")).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)


if __name__ == "__main__":
    ThreadingHTTPServer((HOST, PORT), Handler).serve_forever()
