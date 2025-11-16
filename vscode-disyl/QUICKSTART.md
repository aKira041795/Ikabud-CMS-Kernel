# DiSyL Extension - Quick Start

## 3-Step Installation

### 1️⃣ Install
```bash
cd /var/www/html/ikabud-kernel/vscode-disyl
./install.sh
```

### 2️⃣ Reload
Press `Ctrl+Shift+P` → Type "Reload Window" → Enter

### 3️⃣ Test
Open any `.disyl` file and check:
- Bottom-right shows "DiSyL"
- Syntax highlighting is active
- Type `section` + Tab to test snippets

---

## Snippets Cheat Sheet

| Type | Press Tab | Result |
|------|-----------|--------|
| `section` | Tab | `{ikb_section}...{/ikb_section}` |
| `if` | Tab | `{if}...{/if}` |
| `for` | Tab | `{for}...{/for}` |
| `button` | Tab | `{ikb_button}...{/ikb_button}` |
| `fesc_html` | Tab | `{var \| esc_html}` |

---

## Troubleshooting

**Not working?**
1. Click bottom-right corner
2. Select "DiSyL" from language list
3. Reload window

**Still not working?**
```bash
./install.sh  # Run installer again
```

---

**Full docs:** [WINDSURF_SETUP.md](WINDSURF_SETUP.md)
