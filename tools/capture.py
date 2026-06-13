# -*- coding: utf-8 -*-
"""
capture.py — Captura PNG de páginas TRIVIAX controlando Chrome por su
protocolo DevTools (CDP). A diferencia de `--screenshot`, espera por reloj,
así funciona en páginas con fondo animado (canvas) que nunca quedan inactivas.
Soporta cookie de sesión para capturar páginas autenticadas.

Uso:
    python tools/capture.py <url> <salida.png> [--w 1280] [--h 860]
        [--wait 5.5] [--cookie NOMBRE=VALOR] [--domain localhost]

Requiere: Chrome instalado y la librería `pychrome`.
"""
import argparse
import os
import subprocess
import tempfile
import time
import base64
import socket
import pychrome


def find_chrome():
    for c in [
        r"C:\Program Files\Google\Chrome\Application\chrome.exe",
        r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe",
        os.path.expandvars(r"%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe"),
    ]:
        if os.path.isfile(c):
            return c
    raise RuntimeError("No se encontró Chrome.")


def free_port():
    s = socket.socket()
    s.bind(("127.0.0.1", 0))
    p = s.getsockname()[1]
    s.close()
    return p


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("url")
    ap.add_argument("out")
    ap.add_argument("--w", type=int, default=1280)
    ap.add_argument("--h", type=int, default=860)
    ap.add_argument("--wait", type=float, default=5.5)
    ap.add_argument("--cookie", default=None, help="NOMBRE=VALOR")
    ap.add_argument("--domain", default="localhost")
    ap.add_argument("--scale", type=int, default=2)
    ap.add_argument("--hide", default=None,
                    help="Selector CSS a ocultar antes de capturar")
    args = ap.parse_args()

    port = free_port()
    profile = tempfile.mkdtemp(prefix="cdp_")
    proc = subprocess.Popen([
        find_chrome(), "--headless=new", "--disable-gpu", "--hide-scrollbars",
        f"--remote-debugging-port={port}", f"--user-data-dir={profile}",
        f"--window-size={args.w},{args.h}", "about:blank",
    ], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    browser = pychrome.Browser(url=f"http://127.0.0.1:{port}")
    # Esperar a que el endpoint de depuración responda.
    tab = None
    for _ in range(50):
        try:
            tab = browser.new_tab()
            break
        except Exception:
            time.sleep(0.2)
    if tab is None:
        proc.kill()
        raise RuntimeError("Chrome no expuso el endpoint de depuración.")

    tab.start()
    tab.call_method("Page.enable")
    tab.call_method("Network.enable")
    tab.call_method("Emulation.setDeviceMetricsOverride", width=args.w,
                    height=args.h, deviceScaleFactor=args.scale, mobile=False)

    if args.cookie:
        name, _, value = args.cookie.partition("=")
        tab.call_method("Network.setCookie", name=name, value=value,
                        domain=args.domain, path="/", httpOnly=True)

    tab.call_method("Page.navigate", url=args.url)
    time.sleep(args.wait)  # espera por reloj: deja correr splash y animaciones

    if args.hide:
        js = ("(function(){var s=document.createElement('style');"
              "s.textContent='%s{display:none!important}';"
              "document.head.appendChild(s);})();" % args.hide)
        tab.call_method("Runtime.evaluate", expression=js)
        time.sleep(0.4)

    result = tab.call_method("Page.captureScreenshot", format="png",
                             captureBeyondViewport=False)
    with open(args.out, "wb") as f:
        f.write(base64.b64decode(result["data"]))

    tab.stop()
    browser.close_tab(tab)
    proc.terminate()
    try:
        proc.wait(timeout=8)
    except Exception:
        proc.kill()
    print(f"OK -> {args.out} ({os.path.getsize(args.out)} bytes)")


if __name__ == "__main__":
    main()
