import os
import sys
import time
import re
from bs4 import BeautifulSoup
from selenium import webdriver

# Prepend standard system paths
os.environ["PATH"] = "/usr/bin:/bin:/usr/local/bin:" + os.environ.get("PATH", "")

options = webdriver.ChromeOptions()
options.add_argument("--headless=new")
options.add_argument("--disable-gpu")
options.add_argument("--no-sandbox")
options.add_argument("--disable-dev-shm-usage")
options.add_argument("--disable-blink-features=AutomationControlled")
options.add_argument("user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36")

url = "https://reponsesfrequentes.quora.com/Pourquoi-si-je-pousse-tire-tourne-un-long-objet-lautre-bout-ne-se-met-pas-instantanément-à-bouger-donc-plus-vite-que"

driver = webdriver.Chrome(options=options)
driver.execute_cdp_cmd("Page.addScriptToEvaluateOnNewDocument", {
    "source": "Object.defineProperty(navigator, 'webdriver', {get: () => undefined})"
})

try:
    driver.get(url)
    time.sleep(5)
    
    html = driver.page_source
    soup = BeautifulSoup(html, "html.parser")
    
    print("Is '75967066' in page source?", "75967066" in html)
    
    # Print all occurrences of 'comment_id' in hrefs
    anchors_with_comment = soup.find_all('a', href=re.compile(r'comment_id='))
    print(f"Total comment anchors found: {len(anchors_with_comment)}")
    for a in anchors_with_comment:
        print(f"Anchor href: {a.get('href')} | text: {a.get_text(strip=True)}")
        
except Exception as e:
    print(f"Error: {e}")
finally:
    driver.quit()
