#!/usr/bin/env python3
import sys
import os
import json
import time
import re
import urllib.parse

# Prepend standard system paths to process environment
os.environ["PATH"] = "/usr/bin:/bin:/usr/local/bin:" + os.environ.get("PATH", "")

def extract_quora_links(links):
    candidate_urls = []
    for l in links:
        try:
            href = l.get_attribute("href")
            if not href:
                continue
            href = href.strip()
            
            # Clean query parameters and hash fragments
            href_clean = href.split('?')[0].split('#')[0]
            
            # Match links like https://fr.quora.com/slug or https://quora.com/slug
            # but ignore static system pages, profiles, topics, spaces, search, etc.
            match = re.match(r'^https?://([a-z0-9\-]+\.)?quora\.com/([^/]+)(?:/answer/[^/]+)?$', href_clean, re.IGNORECASE)
            if match:
                subdomain = match.group(1) or ""
                slug = match.group(2)
                
                exclude_slugs = {
                    'about', 'careers', 'press', 'contact', 'languages', 'terms', 'privacy',
                    'cookies', 'login', 'signup', 'home', 'notifications', 'messages', 'profile',
                    'topic', 'spaces', 'search', 'answer', 'stats', 'settings', 'admin', 'create'
                }
                if slug.lower() not in exclude_slugs and len(slug) > 3:
                    base_url = f"https://{subdomain}quora.com/{slug}"
                    if base_url not in candidate_urls:
                        candidate_urls.append(base_url)
        except Exception:
            continue
    return candidate_urls

def main():
    if len(sys.argv) < 4:
        print(json.dumps({"success": False, "error": "Missing arguments. Usage: search-quora.py <title> <user> <lang>"}, ensure_ascii=False))
        return

    title = sys.argv[1]
    user = sys.argv[2]
    lang = sys.argv[3]

    # Determine homepage URL
    if lang == 'en':
        homepage = "https://quora.com"
    else:
        homepage = f"https://{lang}.quora.com"

    try:
        from selenium import webdriver
        from selenium.webdriver.common.by import By
        from selenium.webdriver.common.keys import Keys
    except ImportError as e:
        print(json.dumps({"success": False, "error": f"selenium missing: {str(e)}"}, ensure_ascii=False))
        return

    driver = None
    try:
        options = webdriver.ChromeOptions()
        options.add_argument('--headless=new')
        options.add_argument('--disable-gpu')
        options.add_argument('--no-sandbox')
        options.add_argument('--disable-dev-shm-usage')
        options.add_argument('--disable-blink-features=AutomationControlled')
        options.add_argument('user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36')
        
        driver = webdriver.Chrome(options=options)
        driver.execute_cdp_cmd("Page.addScriptToEvaluateOnNewDocument", {
            "source": "Object.defineProperty(navigator, 'webdriver', {get: () => undefined})"
        })
        
        # 1. Primary Method: Search via Quora main page first input
        driver.get(homepage)
        time.sleep(2)
        
        # Wait up to 5 seconds for Cloudflare Turnstile if present
        for _ in range(5):
            drv_title = driver.title if driver.title else ""
            if "Un instant" in drv_title or "Just a moment" in drv_title or "Vérification de sécurité" in drv_title:
                time.sleep(1)
            else:
                break

        # Find the first <input> element
        inputs = driver.find_elements(By.TAG_NAME, "input")
        candidate_urls = []
        
        if inputs:
            search_input = inputs[0]
            # Type full title and press Enter
            search_input.clear()
            search_input.send_keys(title)
            search_input.send_keys(Keys.ENTER)
            
            # Wait for results to load
            time.sleep(4)
            
            # Extract the first question/answer link
            links = driver.find_elements(By.TAG_NAME, "a")
            candidate_urls = extract_quora_links(links)

        # 2. Secondary Method: Fallback to Google if primary method found nothing
        if not candidate_urls:
            query = f"site:quora.com {title}"
            google_url = 'https://www.google.com/search?' + urllib.parse.urlencode({'q': query})
            driver.get(google_url)
            time.sleep(3)
            
            links = driver.find_elements(By.TAG_NAME, "a")
            candidate_urls = extract_quora_links(links)

        if not candidate_urls:
            print(json.dumps({"success": False, "error": "No question links found in search results"}, ensure_ascii=False))
            driver.quit()
            return

        # Use the first URL found, append /answer/user
        first_q_url = candidate_urls[0]
        final_url = f"{first_q_url}/answer/{user}"
        
        print(json.dumps({"success": True, "url": final_url}, ensure_ascii=False))
        driver.quit()

    except Exception as e:
        if driver:
            try:
                driver.quit()
            except Exception:
                pass
        print(json.dumps({"success": False, "error": f"Exception during execution: {str(e)}"}, ensure_ascii=False))

if __name__ == "__main__":
    main()
