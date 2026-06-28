#!/usr/bin/env python3
import sys
import os
import json
import re

# Prepend standard system paths to process environment
os.environ["PATH"] = "/usr/bin:/bin:/usr/local/bin:" + os.environ.get("PATH", "")

def main():
    if len(sys.argv) < 2:
        print(json.dumps({"success": False, "error": "No URL provided", "topics": []}))
        return

    url = sys.argv[1]
    html = ""
    success = False
    error_msg = "Could not fetch URL"

    # Method 1: Try using cloudscraper to bypass Cloudflare
    try:
        import cloudscraper
        scraper = cloudscraper.create_scraper()
        response = scraper.get(url, timeout=10)
        if response.status_code == 200:
            html = response.text
            success = True
        else:
            error_msg = f"HTTP {response.status_code}"
    except Exception as e:
        error_msg = f"cloudscraper: {str(e)}"

    # Method 2: Fallback to requests if cloudscraper failed
    if not success:
        try:
            import requests
            headers = {
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept-Language': 'fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
            }
            response = requests.get(url, headers=headers, timeout=10)
            if response.status_code == 200:
                html = response.text
                success = True
            else:
                error_msg = f"HTTP {response.status_code}"
        except Exception as e:
            error_msg = f"requests: {str(e)}"

    # Method 3: Fallback to urllib
    if not success:
        try:
            import urllib.request
            import urllib.error
            req = urllib.request.Request(
                url,
                headers={
                    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept-Language': 'fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
                }
            )
            with urllib.request.urlopen(req, timeout=10) as response:
                html = response.read().decode('utf-8', errors='ignore')
                success = True
        except urllib.error.HTTPError as e:
            error_msg = f"HTTP {e.code}"
        except Exception as e:
            error_msg = f"urllib: {str(e)}"

    if not success:
        print(json.dumps({"success": False, "error": error_msg, "topics": []}, ensure_ascii=False))
        return

    # Extract topics using robust JSON regex pattern supporting relative and absolute URLs
    pattern = r'\\*\"url\\*\":\\*\"((?:https?://[^\"/\\ ]+)?/topic/(?:[^\"\\]|\\.)*?)\\*\",\\*\"name\\*\":\\*\"((?:[^\"\\]|\\.)*?)\\*\"'
    matches = re.findall(pattern, html)

    topics = []
    for m in matches:
        name = m[1]
        # Normalize and clean unicode escaping
        name_clean = name.replace('\\"', '"').replace('\\\\', '\\')
        try:
            decoded_name = bytes(name_clean, "utf-8").decode("unicode_escape")
        except Exception:
            decoded_name = name_clean

        decoded_name = decoded_name.strip()
        if decoded_name and decoded_name not in topics and len(decoded_name) < 50:
            topics.append(decoded_name)

    print(json.dumps({"success": True, "topics": topics}, ensure_ascii=False))

if __name__ == "__main__":
    main()
