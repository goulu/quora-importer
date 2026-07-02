#!/usr/bin/env python3
import sys
import os
import time
import json
import re
from bs4 import BeautifulSoup
from selenium import webdriver
from selenium.webdriver.common.by import By

def expand_all_comments(driver):
    # Remove cookie consent banner to avoid overlay blockage
    try:
        driver.execute_script("document.querySelectorAll('[id*=\"onetrust\"], [class*=\"onetrust\"], [id*=\"consent\"]').forEach(el => el.remove());")
    except Exception:
        pass
        
    for attempt in range(5):
        expanded_something = False
        
        # 1. Click collapsed comment previews (e.g. divs with class qu-bg--darken and qu-cursor--pointer)
        try:
            collapsed_elms = driver.find_elements(By.CSS_SELECTOR, "div.qu-bg--darken.qu-cursor--pointer")
            for el in collapsed_elms:
                try:
                    driver.execute_script("arguments[0].click();", el)
                    expanded_something = True
                    time.sleep(0.5)
                except Exception:
                    pass
        except Exception:
            pass
            
        # 2. Click comment/reply expansion buttons/spans/links/divs
        try:
            all_clickable = driver.find_elements(By.XPATH, "//button | //span | //a | //div[@role='button']")
            for el in all_clickable:
                try:
                    text = el.text.strip()
                    if not text:
                        continue
                    text_lower = text.lower()
                    
                    is_match = False
                    if any(p in text_lower for p in [
                        "afficher les réponses", 
                        "afficher la réponse", 
                        "afficher plus de commentaires",
                        "plus de commentaires",
                        "afficher plus de réponses",
                        "réponses précédentes",
                        "show replies",
                        "view replies",
                        "show more comments",
                        "more comments"
                    ]):
                        is_match = True
                    elif re.search(r'\b\d+\s+(réponses?|replies?)\b', text_lower):
                        is_match = True
                        
                    if is_match:
                        driver.execute_script("arguments[0].click();", el)
                        expanded_something = True
                        time.sleep(0.5)
                except Exception:
                    pass
        except Exception:
            pass
            
        if not expanded_something:
            break

# Prepend standard system paths to process environment
os.environ["PATH"] = "/usr/bin:/bin:/usr/local/bin:" + os.environ.get("PATH", "")

def clean_comment_html(soup, text_div):
    # 1. Replace link cards with simple <a> tags
    for a in text_div.find_all('a'):
        if a.find('div'):
            href = a.get('href', '')
            title_div = a.find(class_=re.compile(r'qu-truncateLines--3'))
            if title_div:
                title = title_div.get_text(strip=True)
            else:
                first_div = a.find('div')
                title = first_div.get_text(strip=True) if first_div else href
            
            clean_link = soup.new_tag('a', href=href, target='_blank')
            clean_link.string = title
            a.replace_with(clean_link)
        else:
            href = a.get('href', '')
            a.attrs = {'href': href, 'target': '_blank'}
            
    # 2. Remove divs that just display a raw URL (like the footer of a link card)
    for div in text_div.find_all('div'):
        div_text = div.get_text(strip=True)
        if div_text.startswith('http://') or div_text.startswith('https://'):
            div.decompose()
            
    # 3. Clean up the tags and attributes bottom-up
    allowed_tags = {'p', 'a', 'b', 'strong', 'i', 'em', 'code', 'pre', 'br'}
    for tag in list(text_div.find_all(True)):
        if tag.name not in allowed_tags:
            tag.unwrap()
        else:
            if tag.name == 'a':
                href = tag.get('href', '')
                tag.attrs = {'href': href, 'target': '_blank'}
            else:
                tag.attrs = {}
                
    parts = []
    for child in text_div.children:
        parts.append(str(child))
    return "".join(parts).strip()

def scrape_comments(urls):
    # Pre-check URLs using cloudscraper to filter out 404s and find the correct one quickly.
    valid_urls = []
    try:
        import cloudscraper
        scraper = cloudscraper.create_scraper()
        for url in urls:
            try:
                res = scraper.get(url, timeout=5, allow_redirects=False)
                sys.stderr.write(f"DEBUG: Precheck {url} -> status={res.status_code}\n")
                if res.status_code == 200:
                    valid_urls.append(url)
                elif res.status_code in [403, 503, 301, 302]:
                    valid_urls.append(url)
            except Exception as e:
                sys.stderr.write(f"DEBUG: Precheck error for {url}: {e}\n")
                valid_urls.append(url)
    except ImportError:
        sys.stderr.write("DEBUG: cloudscraper not installed, skipping precheck\n")
        valid_urls = list(urls)
        
    if not valid_urls:
        return {"success": False, "error": "None of the Quora URLs could be loaded (Page not found or redirected).", "comments": []}

    # Check if --gui flag is passed in arguments
    use_gui = "--gui" in sys.argv

    # Set up a persistent profile to remember login sessions/cookies
    user_home = os.path.expanduser("~")
    profile_dir = os.path.join(user_home, ".config", "quora_importer_chrome_profile")
    os.makedirs(profile_dir, exist_ok=True)
    
    options = webdriver.ChromeOptions()
    options.add_argument(f"user-data-dir={profile_dir}")
    options.add_argument("--disable-gpu")
    options.add_argument("--no-sandbox")
    options.add_argument("--disable-dev-shm-usage")
    options.add_argument("--disable-blink-features=AutomationControlled")
    options.add_argument("user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36")
    options.add_experimental_option('excludeSwitches', ['enable-logging', 'enable-automation'])
    options.add_experimental_option('useAutomationExtension', False)
    
    # Run headlessly by default, unless --gui is explicitly requested
    if not use_gui:
        options.add_argument("--headless=new")
    
    html = ""
    successful_url = None
    
    for url in valid_urls:
        driver = None
        try:
            # Selenium 4 automatically resolves ChromeDriver via Selenium Manager
            driver = webdriver.Chrome(options=options)
            
            # Prevent Cloudflare from detecting webdriver
            driver.execute_cdp_cmd("Page.addScriptToEvaluateOnNewDocument", {
                "source": "Object.defineProperty(navigator, 'webdriver', {get: () => undefined})"
            })
            
            driver.get(url)
            
            # Smart wait loop: waits up to 15s for Cloudflare's challenge page to clear.
            is_valid = False
            for i in range(15):
                time.sleep(1)
                html = driver.page_source
                soup = BeautifulSoup(html, "html.parser")
                
                title = soup.title.string if soup.title else ''
                sys.stderr.write(f"DEBUG: i={i}, title={title}\n")
                
                # Wait until Cloudflare verification clears
                if title and ('Un instant' in title or 'Just a moment' in title or 'Vérification de sécurité' in title):
                    continue
                    
                # Check if the title or page text indicates a 404 or error page.
                title_lower = title.strip().lower()
                page_text_lower = soup.get_text().lower()
                
                sys.stderr.write(f"DEBUG: title_lower={title_lower}, text_snippet={page_text_lower[:200].replace(chr(10), ' ')}\n")
                
                if (not title or 
                    title_lower in ["erreur", "error", "quora"] or 
                    "page not found" in page_text_lower or 
                    "n'avons pas trouvé la page" in page_text_lower or 
                    "page introuvable" in page_text_lower or 
                    "n'existe pas" in page_text_lower):
                    sys.stderr.write("DEBUG: Match failed invalid condition\n")
                    break
                
                is_valid = True
                break
            
            if is_valid:
                expand_all_comments(driver)
                successful_url = url
                html = driver.page_source
                driver.quit()
                driver = None
                break
        except Exception as e:
            # Try next URL
            continue
        finally:
            if driver:
                try:
                    driver.quit()
                except:
                    pass
            
    if not successful_url:
        return {"success": False, "error": "None of the Quora URLs could be loaded (Page not found or redirected).", "comments": []}
        
    if not html:
        return {"success": False, "error": "Could not retrieve page source", "comments": [], "resolved_url": successful_url}
        
    # Parse HTML using BeautifulSoup
    try:
        soup = BeautifulSoup(html, "html.parser")
        
        comments_header = soup.find(string=re.compile(r'^(Commentaires|Comments)$'))
        if not comments_header:
            return {"success": True, "comments": [], "warning": "Comments section not found on page.", "resolved_url": successful_url}
            
        comments_section = None
        curr = comments_header.parent
        while curr:
            links = curr.find_all('a', href=re.compile(r'/profile/'))
            if len(links) > 1:
                comments_section = curr
                break
            curr = curr.parent
            
        if not comments_section:
            return {"success": True, "comments": [], "warning": "Comments section container not found.", "resolved_url": successful_url}
            
        author_links = comments_section.find_all('a', href=re.compile(r'/profile/'))
        
        extracted = []
        seen_comments = set()
        
        for link in author_links:
            author_name = link.get_text(strip=True)
            profile_url = link.get('href')
            if not author_name:
                continue
                
            wrapper = None
            curr = link.parent
            for _ in range(15):
                if not curr:
                    break
                text_div = curr.find(lambda el: el.name == 'div' and el.get('class') == ['q-text'])
                if text_div and link in curr.descendants and text_div != link:
                    wrapper = curr
                    break
                curr = curr.parent
                
            if not wrapper:
                continue
                
            wrapper_id = id(wrapper)
            if wrapper_id in seen_comments:
                continue
            seen_comments.add(wrapper_id)
            
            comment_id = None
            date_text = ""
            for a in wrapper.find_all('a'):
                href = a.get('href', '')
                if 'comment_id=' in href:
                    m = re.search(r'comment_id=(\d+)', href)
                    if m:
                        comment_id = m.group(1)
                    date_text = a.get_text(strip=True)
                    
            if not comment_id:
                comment_id = f"fallback_{len(seen_comments)}"
                
            text_div = wrapper.find(lambda el: el.name == 'div' and el.get('class') == ['q-text'])
            comment_text = clean_comment_html(soup, text_div) if text_div else ""
                
            distance = 0
            p = wrapper
            while p and p != comments_section:
                distance += 1
                p = p.parent
                
            extracted.append({
                "id": comment_id,
                "author": author_name,
                "profile_url": profile_url,
                "text": comment_text,
                "date": date_text,
                "distance": distance
            })
            
        if extracted:
            min_distance = min(c["distance"] for c in extracted)
            for c in extracted:
                c["nesting"] = (c["distance"] - min_distance) // 3
                del c["distance"]
                
        last_seen_at_level = {}
        for c in extracted:
            lvl = c["nesting"]
            last_seen_at_level[lvl] = c["id"]
            if lvl > 0:
                c["parent_id"] = last_seen_at_level.get(lvl - 1)
            else:
                c["parent_id"] = None
                
        return {"success": True, "comments": extracted, "resolved_url": successful_url}
        
    except Exception as e:
        return {"success": False, "error": f"Parsing error: {str(e)}", "comments": [], "resolved_url": successful_url}

def main():
    # Remove --gui from arguments to get the URLs
    args = [arg for arg in sys.argv[1:] if arg != "--gui"]
    if not args:
        print(json.dumps({"success": False, "error": "No URL provided", "comments": []}))
        return
        
    res = scrape_comments(args)
    print(json.dumps(res, ensure_ascii=False))

if __name__ == "__main__":
    main()
