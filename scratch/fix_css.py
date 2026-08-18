import re

with open('public/assets/css/style.css', 'r', encoding='utf-8') as f:
    content = f.read()

# Fix any missing Reset block first
if '/* ── Reset & Base ── */' not in content:
    reset_block = """
/* ── Reset & Base ── */
*, *::before, *::after {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}
"""
    content = content.replace('margin: 0;\n    padding: 0;\n}', reset_block)

new_root = """/* =====================================================
   InventoryPro — Design System
   Light Theme with Dark Sidebar
   ===================================================== */

/* ── CSS Variables ── */
:root {
    /* Background colors */
    --bg-primary: #f4f6f8;
    --bg-secondary: #ffffff;
    --bg-tertiary: #f9fafb;
    --bg-card: rgba(255, 255, 255, 0.95);
    --bg-card-hover: rgba(255, 255, 255, 1);
    --bg-glass: rgba(255, 255, 255, 0.85);
    --bg-input: #ffffff;
    --bg-sidebar: #9A0002;
    
    /* Text colors */
    --text-primary: #111827;
    --text-secondary: #4b5563;
    --text-muted: #6b7280;
    --text-white: #ffffff;
    
    /* Accent colors */
    --accent-violet: #9A0002;
    --accent-cyan: #e11d48;
    --accent-blue: #2563eb;
    --accent-emerald: #10b981;
    --accent-amber: #f59e0b;
    --accent-rose: #e11d48;
    --accent-pink: #db2777;
    
    /* Gradients */
    --gradient-primary: linear-gradient(135deg, #9A0002, #c90003);
    --gradient-warm: linear-gradient(135deg, #e11d48, #f59e0b);
    --gradient-cool: linear-gradient(135deg, #2563eb, #8b5cf6);
    --gradient-emerald: linear-gradient(135deg, #10b981, #06b6d4);
    --gradient-sidebar: linear-gradient(180deg, rgba(255, 255, 255, 0.1), rgba(0, 0, 0, 0.15));
    
    /* Borders */
    --border-color: rgba(15, 23, 42, 0.1);
    --border-color-hover: rgba(154, 0, 2, 0.3);
    --border-radius: 12px;
    --border-radius-sm: 8px;
    --border-radius-lg: 16px;
    --border-radius-xl: 20px;
    --border-radius-full: 9999px;
    
    /* Shadows */
    --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.05);
    --shadow-md: 0 4px 16px rgba(0, 0, 0, 0.08);
    --shadow-lg: 0 8px 32px rgba(0, 0, 0, 0.12);
    --shadow-glow-violet: 0 0 20px rgba(154, 0, 2, 0.2);
    --shadow-glow-cyan: 0 0 20px rgba(225, 29, 72, 0.2);
    
    /* Spacing */
    --sidebar-width: 260px;
    --sidebar-collapsed: 0px;
    --topbar-height: 64px;
    
    /* Transitions */
    --transition-fast: 0.15s ease;
    --transition-normal: 0.25s ease;
    --transition-slow: 0.4s ease;
    
    /* Font */
    --font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}
"""

# Replace from start up to /* ── Reset & Base ── */
content = re.sub(r'^.*?/\* ── Reset & Base ── \*/', new_root + '\n/* ── Reset & Base ── */', content, flags=re.DOTALL)

with open('public/assets/css/style.css', 'w', encoding='utf-8') as f:
    f.write(content)
