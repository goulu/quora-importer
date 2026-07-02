import os
import sys
import time
from bs4 import BeautifulSoup
from selenium import webdriver
from selenium.webdriver.common.by import By

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
    
    # Let's find all buttons and anchors
    elements = driver.find_elements(By.XPATH, "//button | //a")
    print(f"Total buttons/anchors on page: {len(elements)}")
    for idx, el in enumerate(elements):
        text = el.text.strip()
        if text:
            # Print if contains keywords related to comment/reply/more/plus
            text_lower = text.lower()
            if any(k in text_lower for k in ["commentaire", "comment", "répon", "reply", "plus", "more"]):
                print(f"Index {idx} | Tag: {el.tag_name} | Text: '{text}'")
                
except Exception as e:
    print(f"Error: {e}")
finally:
    driver.quit()
