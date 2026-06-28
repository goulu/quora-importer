import re

file_path = "/home/goulu/Documents/develop/quora2wordpress/content/Contenu_Philippe_Guglielmetti_16/index.html"
with open(file_path, "r", encoding="utf-8") as f:
    html = f.read()

# Find all matches of /topic/
matches = re.findall(r'href=["\'][^"\']*/topic/([^"\'/]+)["\'][^>]*>(.*?)</a>', html, re.IGNORECASE)

print(f"Found {len(matches)} topic matches.")
for i, match in enumerate(matches[:20]):
    print(f"{i+1}: slug='{match[0]}' label='{match[1]}'")
