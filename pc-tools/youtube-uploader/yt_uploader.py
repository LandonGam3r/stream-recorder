#!/usr/bin/env python3
"""
YouTube folder uploader - uploads (large) video files from a folder to a YouTube account.

Only needs Python 3.8+ (no extra packages). Double-click "YouTube Uploader.bat" for a menu,
or use the command line:

  py yt_uploader.py signin main                     sign in a YouTube account, saved as "main"
  py yt_uploader.py accounts                        list signed-in accounts
  py yt_uploader.py upload "D:\\Streams" --account main [--watch]
  py yt_uploader.py export-server main              make the account file for the recorder

Uploads are resumable: if the PC sleeps or the internet drops, run it again and it continues
where it stopped. Files already uploaded are remembered (uploads.json) and skipped.
"""
import argparse
import base64
import hashlib
import http.client
import http.server
import json
import os
import re
import secrets
import shutil
import socket
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import webbrowser
from pathlib import Path

HERE = Path(__file__).resolve().parent
CLIENT_FILE = HERE / "client_secret.json"
ACCOUNTS_DIR = HERE / "accounts"
STATE_FILE = HERE / "uploads.json"
CONFIG_FILE = HERE / "config.json"

AUTH_URL = "https://accounts.google.com/o/oauth2/v2/auth"
TOKEN_URL = "https://oauth2.googleapis.com/token"
API = "https://www.googleapis.com"
if os.environ.get("YTU_TEST_BASE"):  # only for testing against a fake server
    TOKEN_URL = os.environ["YTU_TEST_BASE"] + "/token"
    API = os.environ["YTU_TEST_BASE"]

SCOPES = "https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly"
VIDEO_EXT = {".mp4", ".mkv", ".mov", ".avi", ".webm", ".ts", ".flv", ".m4v", ".wmv", ".mpg", ".mpeg"}
MIME = {".mp4": "video/mp4", ".m4v": "video/mp4", ".mkv": "video/x-matroska", ".mov": "video/quicktime",
        ".webm": "video/webm", ".ts": "video/mp2t", ".avi": "video/x-msvideo", ".flv": "video/x-flv",
        ".wmv": "video/x-ms-wmv", ".mpg": "video/mpeg", ".mpeg": "video/mpeg"}

DEFAULT_CONFIG = {
    "privacy": "private",       # private / unlisted / public (unaudited Google projects are forced to private)
    "category_id": "20",        # 20 = Gaming, 22 = People & Blogs
    "after_upload": "keep",     # "keep" or "move" (moves the uploaded file into an "_uploaded" subfolder)
    "min_file_age_minutes": 2,  # skip files changed more recently (still being copied/recorded)
    "chunk_mb": 32,             # upload piece size (multiple of 0.25 MB)
    "watch_interval_minutes": 5,
}

try:
    sys.stdout.reconfigure(errors="replace")
except Exception:
    pass


class UserError(Exception):
    """Problem the user can fix - shown without a stack trace."""


# ----------------------------------------------------------------------------- storage

def load_json(path, default):
    try:
        return json.loads(Path(path).read_text(encoding="utf-8"))
    except (FileNotFoundError, ValueError):
        return default


def save_json(path, data):
    tmp = Path(str(path) + ".tmp")
    tmp.write_text(json.dumps(data, indent=2, ensure_ascii=False), encoding="utf-8")
    os.replace(tmp, path)


def config():
    cfg = dict(DEFAULT_CONFIG)
    cfg.update(load_json(CONFIG_FILE, {}))
    if not CONFIG_FILE.exists():
        save_json(CONFIG_FILE, cfg)
    return cfg


# Windows: account tokens are encrypted with DPAPI, so the files only work for your Windows user.
if os.name == "nt":
    import ctypes
    from ctypes import wintypes

    class _BLOB(ctypes.Structure):
        _fields_ = [("cbData", wintypes.DWORD), ("pbData", ctypes.POINTER(ctypes.c_char))]

    def _crypt(data: bytes, protect: bool) -> bytes:
        buf = ctypes.create_string_buffer(data, len(data))
        blob_in = _BLOB(len(data), ctypes.cast(buf, ctypes.POINTER(ctypes.c_char)))
        blob_out = _BLOB()
        fn = ctypes.windll.crypt32.CryptProtectData if protect else ctypes.windll.crypt32.CryptUnprotectData
        args = (ctypes.byref(blob_in), ctypes.c_wchar_p("yt-uploader") if protect else None,
                None, None, None, 0x1, ctypes.byref(blob_out))
        if not fn(*args):
            raise ctypes.WinError()
        try:
            return ctypes.string_at(blob_out.pbData, blob_out.cbData)
        finally:
            ctypes.windll.kernel32.LocalFree(ctypes.cast(blob_out.pbData, ctypes.c_void_p))

    def protect(data: bytes) -> bytes:
        return b"DPAPI:" + base64.b64encode(_crypt(data, True))

    def unprotect(data: bytes) -> bytes:
        if data.startswith(b"DPAPI:"):
            return _crypt(base64.b64decode(data[6:]), False)
        return data
else:
    def protect(data: bytes) -> bytes:
        return data

    def unprotect(data: bytes) -> bytes:
        return data


def account_name(name):
    n = re.sub(r"[^a-z0-9_-]", "", (name or "").lower())
    if not n:
        raise UserError("Account names may only use letters, digits, - and _ (for example: main).")
    return n


def save_account(name, data):
    ACCOUNTS_DIR.mkdir(exist_ok=True)
    path = ACCOUNTS_DIR / f"{name}.token"
    path.write_bytes(protect(json.dumps(data).encode()))
    try:
        os.chmod(path, 0o600)
    except OSError:
        pass


def load_account(name):
    path = ACCOUNTS_DIR / f"{account_name(name)}.token"
    if not path.exists():
        raise UserError(f"No account '{name}'. Sign it in first (menu option 1, or: signin {name}).")
    try:
        return json.loads(unprotect(path.read_bytes()))
    except Exception:
        raise UserError(f"Account file for '{name}' can't be read (made by another Windows user?). Sign in again.")


def list_accounts():
    if not ACCOUNTS_DIR.exists():
        return []
    return sorted(p.stem for p in ACCOUNTS_DIR.glob("*.token"))


def load_client():
    if not CLIENT_FILE.exists():
        raise UserError(f"{CLIENT_FILE.name} is missing. Download it from Google Cloud (see README) and put it next to this program.")
    data = load_json(CLIENT_FILE, {})
    c = data.get("installed") or data.get("web") or {}
    if not c.get("client_id") or not c.get("client_secret"):
        raise UserError(f"{CLIENT_FILE.name} doesn't look like a Google OAuth client file (type 'Desktop app').")
    return c["client_id"], c["client_secret"]


# ----------------------------------------------------------------------------- Google sign-in

def http_json(url, data=None, headers=None, method=None):
    body = urllib.parse.urlencode(data).encode() if isinstance(data, dict) else data
    req = urllib.request.Request(url, data=body, headers=headers or {}, method=method)
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return r.status, json.loads(r.read() or b"{}")
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read() or b"{}")
        except ValueError:
            return e.code, {}


def signin(name):
    name = account_name(name)
    client_id, client_secret = load_client()
    verifier = secrets.token_urlsafe(64)
    challenge = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).rstrip(b"=").decode()
    state = secrets.token_urlsafe(16)
    result = {}

    class Handler(http.server.BaseHTTPRequestHandler):
        def do_GET(self):
            q = urllib.parse.parse_qs(urllib.parse.urlparse(self.path).query)
            if "code" in q or "error" in q:
                result.update({k: v[0] for k, v in q.items()})
            self.send_response(200)
            self.send_header("Content-Type", "text/html; charset=utf-8")
            self.end_headers()
            ok = "code" in q and q.get("state", [""])[0] == state
            self.wfile.write(("<html><body style='font-family:sans-serif;padding:40px'><h2>"
                              + ("Signed in - you can close this tab." if ok else "Sign-in failed - see the program window.")
                              + "</h2></body></html>").encode())

        def log_message(self, *a):
            pass

    server = http.server.HTTPServer(("127.0.0.1", 0), Handler)
    redirect = f"http://127.0.0.1:{server.server_port}"
    url = AUTH_URL + "?" + urllib.parse.urlencode({
        "client_id": client_id, "redirect_uri": redirect, "response_type": "code", "scope": SCOPES,
        "access_type": "offline", "prompt": "consent", "state": state,
        "code_challenge": challenge, "code_challenge_method": "S256",
    })
    print("\nA browser window opens to sign in with Google.")
    print("Choose the Google account and the YouTube channel the videos should go to.")
    print("(Google may warn that the app isn't verified: click Advanced -> Go to ... - it's your own app.)\n")
    print("If no browser opens, paste this link into one:\n" + url + "\n")
    webbrowser.open(url)
    t = threading.Thread(target=lambda: [server.handle_request() for _ in range(5) if "code" not in result and "error" not in result], daemon=True)
    t.start()
    t.join(300)
    server.server_close()
    if "error" in result:
        raise UserError(f"Google sign-in was cancelled or refused ({result['error']}).")
    if "code" not in result or result.get("state") != state:
        raise UserError("No answer from the browser within 5 minutes. Try again.")

    status, tok = http_json(TOKEN_URL, {
        "code": result["code"], "client_id": client_id, "client_secret": client_secret,
        "redirect_uri": redirect, "grant_type": "authorization_code", "code_verifier": verifier,
    })
    if status != 200 or "refresh_token" not in tok:
        raise UserError(f"Google didn't give a long-term sign-in ({tok.get('error_description') or tok.get('error') or status}).")
    status, ch = http_json(API + "/youtube/v3/channels?part=snippet&mine=true",
                           headers={"Authorization": "Bearer " + tok["access_token"]})
    items = ch.get("items") or []
    title = items[0]["snippet"]["title"] if items else "(no YouTube channel on this Google account!)"
    save_account(name, {"refresh_token": tok["refresh_token"], "channel_title": title,
                        "channel_id": items[0]["id"] if items else None, "signed_in": time.strftime("%Y-%m-%d %H:%M")})
    print(f"Signed in. Account '{name}' uploads to YouTube channel: {title}")
    if not items:
        print("WARNING: this Google account has no YouTube channel - create one on youtube.com first.")


_token_cache = {}


def access_token(name, force=False):
    c = _token_cache.get(name)
    if c and not force and c[1] > time.time() + 60:
        return c[0]
    acct = load_account(name)
    client_id, client_secret = load_client()
    status, tok = http_json(TOKEN_URL, {"client_id": client_id, "client_secret": client_secret,
                                        "refresh_token": acct["refresh_token"], "grant_type": "refresh_token"})
    if status != 200:
        if tok.get("error") == "invalid_grant":
            raise UserError(f"The sign-in for '{name}' expired or was revoked. Sign in again (menu option 1). "
                            "If this happens every week: set the Google app to 'In production' (see README).")
        raise UserError(f"Couldn't refresh the sign-in for '{name}': {tok.get('error_description') or tok.get('error') or status}")
    _token_cache[name] = (tok["access_token"], time.time() + int(tok.get("expires_in", 3600)))
    return tok["access_token"]


# ----------------------------------------------------------------------------- upload

def raw_request(method, url, headers, body=b"", timeout=300):
    """HTTP request without automatic redirects (YouTube answers 308 = 'continue')."""
    u = urllib.parse.urlparse(url)
    conn_cls = http.client.HTTPSConnection if u.scheme == "https" else http.client.HTTPConnection
    conn = conn_cls(u.netloc, timeout=timeout)
    try:
        conn.request(method, u.path + ("?" + u.query if u.query else ""), body=body, headers=headers)
        r = conn.getresponse()
        data = r.read()
        return r.status, {k.lower(): v for k, v in r.getheaders()}, data
    finally:
        conn.close()


def clean(text, limit):
    text = (text or "").replace("<", "").replace(">", "")
    b = text.encode("utf-8")[:limit]
    return b.decode("utf-8", "ignore")


def unlink(text: str) -> str:
    """Links and e-mail addresses become "[link]": chat messages must not put links in your description."""
    text = re.sub(r"\b(?:https?://|www\.)\S+", "[link]", text, flags=re.I)
    text = re.sub(r"\b[\w.+-]+@[\w-]+\.[\w.-]+\b", "[link]", text)
    return re.sub(r"\b[\w-]+(?:\.[\w-]+)*\.(?:com|net|org|de|nl|io|gg|tv|me|ly|co|uk|eu|info|xyz|ru|be|fr|app|link|shop|store)\b(?:/\S*)?", "[link]", text, flags=re.I)


def metadata_for(path: Path):
    """Title/description: from the recorder's meta.json when present, else the file name.
    "Moments" found by the recorder (dashboard rules) are added: labels to the title, timestamps to the description."""
    meta = load_json(path.parent / "meta.json", {})
    if meta.get("title") or meta.get("channel"):
        date = (meta.get("stream_started") or "")[:10]
        title = f"{meta.get('channel', '')} - {meta.get('title') or 'stream'}" + (f" ({date})" if date else "")
    else:
        title = path.stem
    title = clean(title, 300)
    labels = [clean(str(l), 60) for l in (meta.get("moment_labels") or [])]
    suffix = (" | " + " | ".join(labels)) if labels else ""
    if len(suffix) > 60:
        suffix = suffix[:59] + "…"
    room = 100 - len(suffix)
    if len(title) > room:
        title = title[:room - 1] + "…"
    title += suffix
    # Public description: only what viewers should see. The recorder's summary.txt (server folder,
    # cron runs, gaps) is internal and never goes to YouTube.
    def clock(t):
        t = int(max(0, round(float(t))))
        return f"{t // 3600}:{t % 3600 // 60:02d}:{t % 60:02d}"
    lines = []
    if meta.get("channel") or meta.get("title"):
        when = (meta.get("stream_started") or "")[:16]
        lines.append(f"{meta.get('channel') or 'The streamer'} streamed on Twitch" + (f" on {when}." if when else "."))
        if meta.get("title"):
            lines.append("Title: " + meta["title"])
        if meta.get("recorded_seconds"):
            lines.append("Length: " + clock(meta["recorded_seconds"]))
        games = []
        for c in meta.get("changes") or []:
            g = c.get("game") or ""
            if g and (not games or games[-1][1] != g):
                games.append((c.get("video") or 0, g))
        if len(games) > 1:
            lines += ["", "Categories:"] + [f"{clock(t)} {g}" for t, g in games]
        elif meta.get("game"):
            lines.append("Category: " + meta["game"])
    desc = "\n".join(lines) + ("\n\n" if lines else "")
    moments = meta.get("moments") or []
    if moments:
        mlines = ["Moments:"]
        for t, label, detail in moments[:60]:
            detail = unlink(str(detail or ""))
            mlines.append(f"{clock(t)} {label}" + (f" ({detail})" if detail else ""))
        if len(moments) > 60:
            mlines.append(f"… and {len(moments) - 60} more")
        desc += "\n".join(mlines) + "\n\n"
    if desc:
        desc += "Recorded with Stream Recorder\u2122\n"
    return title, clean(desc, 4900)


def file_key(path: Path):
    st = path.stat()
    return f"{path.resolve()}|{st.st_size}|{int(st.st_mtime)}"


def fmt_size(b):
    return f"{b / 1073741824:.2f} GB" if b >= 1073741824 else f"{b / 1048576:.0f} MB"


def upload_file(path: Path, account, cfg, state):
    key = file_key(path)
    job = state.setdefault(key, {"file": str(path), "account": account})
    if job.get("video_id"):
        return job
    size = path.stat().st_size
    mime = MIME.get(path.suffix.lower(), "application/octet-stream")
    title, desc = metadata_for(path)
    print(f"\n>> {path.name}  ({fmt_size(size)})\n   Title: {title}")

    chunk = max(1, int(cfg["chunk_mb"] * 4)) * 262144
    failures = 0
    offset = 0
    while True:
        try:
            tok = access_token(account)
            auth = {"Authorization": "Bearer " + tok}
            if job.get("session") and job.get("account") == account:
                # Resume: ask how much YouTube already has.
                st, h, body = raw_request("PUT", job["session"], dict(auth, **{"Content-Range": f"bytes */{size}", "Content-Length": "0"}), b"", 60)
                if st in (200, 201):
                    return finish(job, body, state, path, cfg)
                if st == 308:
                    offset = int(h["range"].rsplit("-", 1)[1]) + 1 if "range" in h else 0
                    if offset:
                        print(f"   Continuing at {fmt_size(offset)}")
                elif st in (404, 410):
                    print("   Previous upload session expired - starting over.")
                    job.pop("session", None)
                    continue
                elif st == 401:
                    access_token(account, force=True)
                    continue
                else:
                    raise RuntimeError(f"HTTP {st} {body[:200]!r}")
            else:
                body_json = json.dumps({
                    "snippet": {"title": title, "description": desc, "categoryId": str(cfg["category_id"])},
                    "status": {"privacyStatus": cfg["privacy"], "selfDeclaredMadeForKids": False},
                }).encode()
                st, h, body = raw_request("POST", API + "/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status",
                                          dict(auth, **{"Content-Type": "application/json; charset=UTF-8",
                                                        "X-Upload-Content-Length": str(size), "X-Upload-Content-Type": mime}),
                                          body_json, 60)
                if st != 200 or "location" not in h:
                    check_fatal(st, body)
                    raise RuntimeError(f"HTTP {st} {body[:200]!r}")
                job.update({"session": h["location"], "account": account, "started": time.strftime("%Y-%m-%d %H:%M")})
                save_json(STATE_FILE, state)
                offset = 0

            t0, sent0 = time.time(), offset
            with open(path, "rb") as f:
                while offset < size:
                    f.seek(offset)
                    data = f.read(chunk)
                    end = offset + len(data) - 1
                    st, h, body = raw_request("PUT", job["session"], dict(auth, **{
                        "Content-Range": f"bytes {offset}-{end}/{size}", "Content-Type": mime}), data, 600)
                    if st == 308:
                        offset = int(h["range"].rsplit("-", 1)[1]) + 1 if "range" in h else 0
                        failures = 0
                        speed = (offset - sent0) / max(0.001, time.time() - t0)
                        eta = (size - offset) / speed if speed > 0 else 0
                        print(f"\r   {100 * offset / size:5.1f}%  {fmt_size(offset)} / {fmt_size(size)}  "
                              f"{speed / 1048576:5.1f} MB/s  ~{int(eta // 60)}m{int(eta % 60):02d}s left   ", end="", flush=True)
                    elif st in (200, 201):
                        print()
                        return finish(job, body, state, path, cfg)
                    elif st == 401:
                        auth = {"Authorization": "Bearer " + access_token(account, force=True)}
                    elif st in (404, 410):
                        print("\n   Upload session expired - starting over.")
                        job.pop("session", None)
                        break
                    else:
                        check_fatal(st, body)
                        raise RuntimeError(f"HTTP {st} {body[:200]!r}")
            if not job.get("session"):
                continue
        except UserError:
            save_json(STATE_FILE, state)
            raise
        except (OSError, http.client.HTTPException, RuntimeError, ValueError) as e:
            failures += 1
            if failures > 8:
                save_json(STATE_FILE, state)
                raise UserError(f"Giving up on {path.name} for now after repeated errors ({e}). Run again later to continue.")
            wait = min(300, 5 * 2 ** failures)
            print(f"\n   Problem: {e} - retrying in {wait}s (attempt {failures}/8)")
            save_json(STATE_FILE, state)
            time.sleep(wait)


def check_fatal(status, body):
    try:
        err = json.loads(body).get("error", {})
    except ValueError:
        err = {}
    reason = (err.get("errors") or [{}])[0].get("reason", "")
    msg = err.get("message", "")
    if reason == "uploadLimitExceeded":
        raise UserError("YouTube says this CHANNEL has reached its daily upload limit (uploadLimitExceeded). "
                        "New channels and channels without phone verification get a low limit. Verify the channel at "
                        "https://www.youtube.com/verify (also needed for videos longer than 15 minutes) and try again in 24 hours "
                        "- it continues where it stopped.")
    if reason == "quotaExceeded":
        raise UserError("Your Google PROJECT's daily upload quota is used up (quotaExceeded). It resets at midnight "
                        "Pacific time (09:00 in the Netherlands). Run again after that - it continues where it stopped.")
    if reason == "rateLimitExceeded":
        raise UserError("Too many requests in a short time (rateLimitExceeded). Wait a few minutes and run again.")
    if status in (400, 403) and reason not in ("", "backendError"):
        raise UserError(f"YouTube refused the upload: {reason} {msg}")


def finish(job, body, state, path: Path, cfg):
    v = json.loads(body or b"{}")
    job.pop("session", None)
    job.update({"video_id": v.get("id"), "url": f"https://youtu.be/{v.get('id')}",
                "privacy": (v.get("status") or {}).get("privacyStatus", cfg["privacy"]),
                "uploaded": time.strftime("%Y-%m-%d %H:%M")})
    save_json(STATE_FILE, state)
    print(f"   Done: {job['url']} ({job['privacy']})")
    if job["privacy"] != cfg["privacy"]:
        print(f"   Note: YouTube made it {job['privacy']} (unaudited Google projects can only upload private videos).")
    if cfg.get("after_upload") == "move":
        target = path.parent / "_uploaded" / path.name
        target.parent.mkdir(exist_ok=True)
        shutil.move(str(path), str(target))
        job["file"] = str(target)
        save_json(STATE_FILE, state)
        print(f"   Moved to {target}")
    return job


def find_videos(folder: Path, cfg):
    min_age = cfg["min_file_age_minutes"] * 60
    out = []
    for p in folder.rglob("*"):
        if not p.is_file() or p.suffix.lower() not in VIDEO_EXT or "_uploaded" in p.parts or p.name.startswith("."):
            continue
        if p.parent.name == "parts" and (p.parent.parent / "meta.json").exists():
            continue  # unconverted pieces of a web-recorder recording, not separate videos
            continue
        if time.time() - p.stat().st_mtime < min_age:
            print(f"(skipping {p.name} for now: it changed in the last {cfg['min_file_age_minutes']} minutes)")
            continue
        out.append(p)
    return sorted(out, key=lambda p: p.stat().st_mtime)


def upload_folder(folder, account, watch=False, privacy=None):
    folder = Path(folder).expanduser()
    if not folder.is_dir():
        raise UserError(f"Folder not found: {folder}")
    account = account_name(account)
    load_account(account)
    cfg = config()
    if privacy:
        cfg["privacy"] = privacy
    while True:
        state = load_json(STATE_FILE, {})
        todo = [p for p in find_videos(folder, cfg) if not state.get(file_key(p), {}).get("video_id")]
        print(f"\n{len(todo)} video(s) to upload from {folder} to account '{account}' ({cfg['privacy']}).")
        done = 0
        for p in todo:
            upload_file(p, account, cfg, state)
            done += 1
        if done:
            print(f"\nFinished: {done} video(s) uploaded.")
        if not watch:
            return
        print(f"Watching the folder - checking again in {cfg['watch_interval_minutes']} min (Ctrl+C to stop).")
        time.sleep(cfg["watch_interval_minutes"] * 60)


def export_server(name):
    name = account_name(name)
    acct = load_account(name)
    client_id, client_secret = load_client()
    out = HERE / f"youtube_setup_{name}.json"
    save_json(out, {"client_id": client_id, "client_secret": client_secret, "refresh_token": acct["refresh_token"],
                    "channel_title": acct.get("channel_title"), "exported": time.strftime("%Y-%m-%d %H:%M")})
    print(f"\nCreated {out}\n")
    print("1. Put this file in the  secret/  folder next to record.php (on a web server: e.g. cPanel File Manager).")
    print("   The next recorder run encrypts it with your REC_KEY and deletes the file.")
    print(f"2. On the dashboard, choose '{name}' under 'Upload to YouTube' for each streamer to upload.")
    print("3. DELETE this file from your PC afterwards - it gives upload access to the channel.\n")


# ----------------------------------------------------------------------------- menu

def ask_folder():
    try:
        import tkinter
        from tkinter import filedialog
        root = tkinter.Tk()
        root.withdraw()
        root.attributes("-topmost", True)
        folder = filedialog.askdirectory(title="Choose the folder with the videos to upload")
        root.destroy()
        if folder:
            return folder
    except Exception:
        pass
    return input("Folder path: ").strip().strip('"')


def ask_account():
    accts = list_accounts()
    if not accts:
        raise UserError("No accounts signed in yet. Choose option 1 first.")
    if len(accts) == 1:
        print(f"Account: {accts[0]}")
        return accts[0]
    for i, a in enumerate(accts, 1):
        print(f"  {i}. {a}")
    c = input("Account number: ").strip()
    return accts[int(c) - 1] if c.isdigit() and 0 < int(c) <= len(accts) else account_name(c)


def menu():
    while True:
        accts = list_accounts()
        print("\n=== YouTube Uploader ===")
        print("Accounts: " + (", ".join(f"{a} ({load_account(a).get('channel_title')})" for a in accts) if accts else "none yet"))
        print("  1. Sign in a YouTube account")
        print("  2. Upload a folder")
        print("  3. Upload a folder and keep watching it for new videos")
        print("  4. Export an account for the web recorder (server)")
        print("  5. Quit")
        c = input("Choice: ").strip()
        try:
            if c == "1":
                signin(input("Name for this account (e.g. main): ").strip())
            elif c in ("2", "3"):
                upload_folder(ask_folder(), ask_account(), watch=(c == "3"))
            elif c == "4":
                export_server(ask_account())
            elif c == "5":
                return
        except UserError as e:
            print(f"\n!! {e}")
        except KeyboardInterrupt:
            print("\nStopped. Run again to continue unfinished uploads.")


def move_old_files():
    """Since 1.8.0 the uploader has its own folder (pc-tools/youtube-uploader). Sign-ins and settings
    from the old place (pc-tools itself) are moved here once, so nothing has to be set up again."""
    old = HERE.parent
    if HERE.name != "youtube-uploader" or not (old / "README.md").exists():
        return
    names = ["client_secret.json", "accounts", "uploads.json", "config.json"] + [p.name for p in old.glob("youtube_setup_*.json")]
    for name in names:
        src, dst = old / name, HERE / name
        if src.exists() and not dst.exists():
            shutil.move(str(src), str(dst))
            print(f"Moved {name} into the youtube-uploader folder.")


def main():
    move_old_files()
    ap = argparse.ArgumentParser(description="Upload video folders to YouTube.")
    sub = ap.add_subparsers(dest="cmd")
    s = sub.add_parser("signin"); s.add_argument("account")
    sub.add_parser("accounts")
    u = sub.add_parser("upload"); u.add_argument("folder"); u.add_argument("--account", required=True)
    u.add_argument("--watch", action="store_true"); u.add_argument("--privacy", choices=["private", "unlisted", "public"])
    e = sub.add_parser("export-server"); e.add_argument("account")
    a = ap.parse_args()
    try:
        if a.cmd == "signin":
            signin(a.account)
        elif a.cmd == "accounts":
            for n in list_accounts():
                print(f"{n}: {load_account(n).get('channel_title')}")
        elif a.cmd == "upload":
            upload_folder(a.folder, a.account, a.watch, a.privacy)
        elif a.cmd == "export-server":
            export_server(a.account)
        else:
            menu()
    except UserError as e:
        print(f"\n!! {e}")
        sys.exit(1)
    except KeyboardInterrupt:
        print("\nStopped. Run again to continue unfinished uploads.")


if __name__ == "__main__":
    main()
