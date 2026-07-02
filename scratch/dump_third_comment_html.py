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
    
    comments = soup.find_all(string=re.compile(r"En fait je ne savais pas non plus"))
    print(f"Occurrences found: {len(comments)}")
    for idx, c in enumerate(comments):
        print(f"Occurrence {idx} parents:")
        curr = c.parent
        # Go up 5 levels and print
        for i in range(7):
            if not curr:
                break
            print(f"Level {i}: Tag={curr.name}, Class={curr.get('class')}")
            curr = curr.parent
            
        print("\nOuter HTML (Level 4 parent):")
        curr = c.parent
        for _ in range(4):
            if curr.parent:
                curr = curr.parent
        print(curr.prettify()[:4000])
        
except Exception as e:
    print(f"Error: {e}")
finally:
    driver.quit()
