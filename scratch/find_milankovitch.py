import os

content_dir = "/home/goulu/Documents/develop/quora2wordpress/content"
for root, dirs, files in os.walk(content_dir):
    for file in files:
        if file == "index.html":
            path = os.path.join(root, file)
            try:
                with open(path, "r", encoding="utf-8", errors="ignore") as f:
                    content = f.read()
                    if "Milankovitch" in content:
                        print(f"Found in: {path}")
                        # Print surrounding snippet
                        pos = content.find("Milankovitch")
                        print("Snippet:", content[max(0, pos-200):min(len(content), pos+400)])
                        print("-" * 50)
            except Exception as e:
                pass
