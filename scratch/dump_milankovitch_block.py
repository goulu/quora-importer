import re

path = "/home/goulu/Documents/develop/quora2wordpress/content/Contenu_Philippe_Guglielmetti_19/index.html"
with open(path, "r", encoding="utf-8", errors="ignore") as f:
    html = f.read()

# Find the block containing Cycles_de_Milankovitch
matches = re.finditer(r"Cycles_de_Milankovitch", html)
for m in matches:
    start = max(0, m.start() - 2000)
    end = min(len(html), m.end() + 2000)
    print("MATCH BLOCK:")
    print(html[start:end])
    print("=" * 80)
