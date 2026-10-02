import os
import re

directory = 'c:/xampp/htdocs/unified'

for root, dirs, files in os.walk(directory):
    for filename in files:
        if filename.endswith('.php'):
            filepath = os.path.join(root, filename)
            try:
                with open(filepath, 'r', encoding='utf-8') as f:
                    content = f.read()
                
                new_content = re.sub(r'href="https://fonts\.googleapis\.com/css2\?family=[^"]*"', 'href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap"', content)
                
                if new_content != content:
                    with open(filepath, 'w', encoding='utf-8') as f:
                        f.write(new_content)
                    print(f"Updated {filepath}")
            except Exception as e:
                print(f"Error reading {filepath}: {e}")

        elif filename.endswith('.css'):
            filepath = os.path.join(root, filename)
            try:
                with open(filepath, 'r', encoding='utf-8') as f:
                    content = f.read()
                
                # Replace font families in CSS variables or declarations
                new_content = re.sub(r"'DM Sans'", "'Poppins'", content)
                new_content = re.sub(r'"DM Sans"', '"Poppins"', new_content)
                new_content = re.sub(r"'Plus Jakarta Sans'", "'Poppins'", new_content)
                new_content = re.sub(r'"Plus Jakarta Sans"', '"Poppins"', new_content)
                new_content = re.sub(r"'Fraunces'", "'Poppins'", new_content)
                new_content = re.sub(r'"Fraunces"', '"Poppins"', new_content)
                
                if new_content != content:
                    with open(filepath, 'w', encoding='utf-8') as f:
                        f.write(new_content)
                    print(f"Updated {filepath}")
            except Exception as e:
                print(f"Error reading {filepath}: {e}")
