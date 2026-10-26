#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
GEO 示范页本地预览服务（HTTPS，自签名证书）

为什么用 HTTPS 而不是 HTTP：
  GEO 诊断的第一个维度就是「抓取入口」，HTTPS 那项占 1/24 分。
  用 HTTP 起服务会白丢一项，只能拿 96 分，看不出真实差距。

为什么自签名也能用：
  GEOFlow 诊断器的 curl 设了 CURLOPT_SSL_VERIFYPEER => false，
  所以自签名证书同样能抓取。

用法：
  cd D:\\GEOFlow\\geo-audit\\demo
  python serve.py            # 默认 3443 端口
  python serve.py 8443       # 自定义端口

浏览器打开：https://localhost:3443/  （会提示证书不受信任，点"继续访问"）
诊断用地址：https://host.docker.internal:3443/  （容器内访问宿主机）
"""

import http.server
import os
import ssl
import socketserver
import sys


PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 3443
ROOT = os.path.dirname(os.path.abspath(__file__))


class Handler(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=ROOT, **kwargs)

    def end_headers(self):
        # 让浏览器和抓取器都拿到干净的 HTML
        self.send_header("Cache-Control", "no-store")
        super().end_headers()

    def log_message(self, fmt, *args):
        print("  %s - %s" % (self.address_string(), fmt % args))


def main():
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    ctx.load_cert_chain(os.path.join(ROOT, "cert.pem"), os.path.join(ROOT, "key.pem"))

    socketserver.TCPServer.allow_reuse_address = True
    with socketserver.TCPServer(("0.0.0.0", PORT), Handler) as httpd:
        httpd.socket = ctx.wrap_socket(httpd.socket, server_side=True)
        print("GEO demo serving at https://localhost:%d/  (root=%s)" % (PORT, ROOT))
        print("diagnose url: https://host.docker.internal:%d/" % PORT)
        print("Ctrl+C to stop")
        try:
            httpd.serve_forever()
        except KeyboardInterrupt:
            print("\nstopped")


if __name__ == "__main__":
    main()
