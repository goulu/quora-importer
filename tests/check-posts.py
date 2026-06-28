import re

file_path = "/home/goulu/Documents/develop/quora2wordpress/content/Contenu_Philippe_Guglielmetti_16/index.html"
with open(file_path, "r", encoding="utf-8") as f:
    html = f.read()

# Let's find all <h2> positions
h2_matches = list(re.finditer(r'<h2[^>]*>(.*?)</h2>', html, re.IGNORECASE))
print(f"Total <h2> matches: {len(h2_matches)}")

for i in range(min(5, len(h2_matches))):
    start = h2_matches[i].start()
    end = h2_matches[i+1].start() if i+1 < len(h2_matches) else len(html)
    snippet = html[start:start+1000]
    print(f"\n--- Post {i+1} ({h2_matches[i].group(1)}) ---")
    print(snippet)
