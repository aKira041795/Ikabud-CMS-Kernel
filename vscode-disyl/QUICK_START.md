# DiSyL Extension - Quick Start Guide

Get up and running with the DiSyL Language Server Protocol extension in 5 minutes.

## 🚀 Installation

### Windsurf IDE
```bash
windsurf --install-extension disyl-0.5.0.vsix
```

### VS Code
```bash
code --install-extension disyl-0.5.0.vsix
```

### Manual Installation
1. Open Extensions panel (`Ctrl+Shift+X` / `Cmd+Shift+X`)
2. Click "..." menu → "Install from VSIX..."
3. Select `disyl-0.5.0.vsix`
4. Reload window

---

## ✨ First Steps

### 1. Create a DiSyL File
Create a new file with `.disyl` extension:
```bash
touch my-template.disyl
```

### 2. Start Coding
Type `{ikb_` and watch IntelliSense appear:

```disyl
{ikb_section type="hero" padding="large"}
    {ikb_container size="xlarge"}
        {ikb_text size="3xl" weight="bold"}
            Welcome to DiSyL
        {/ikb_text}
    {/ikb_container}
{/ikb_section}
```

### 3. See Validation in Action
Try typing an unclosed tag:
```disyl
{ikb_section}
    Content here
```
❌ You'll see an error: "Unclosed tag: {ikb_section}"

### 4. Format Your Code
Press `Shift+Alt+F` (Windows/Linux) or `Shift+Option+F` (Mac)

Your code will auto-indent:
```disyl
{ikb_section}
    {ikb_container}
        {ikb_text}Hello{/ikb_text}
    {/ikb_container}
{/ikb_section}
```

---

## 🎯 Key Features

### IntelliSense
Type `{` to trigger completions:
- Components: `ikb_section`, `ikb_text`, `ikb_button`, etc.
- Control structures: `if`, `for`, `include`
- Filters: `esc_html`, `truncate`, `upper`, etc.

### Hover Documentation
Hover over any component or filter to see docs:
```disyl
{ikb_section}  ← Hover here
    ↓
**ikb_section** - Container section for organizing content
Attributes:
- type: Section type (hero, main, footer)
- padding: Padding size (small, medium, large)
```

### Real-time Validation
Errors appear as you type:
- Unclosed tags
- Mismatched closing tags
- Unknown filters

### Filters
Use the pipe operator for filters:
```disyl
{post.title | esc_html}
{post.excerpt | strip_tags | truncate:length=150}
{post.date | date:format="F j, Y"}
```

---

## 🎨 Code Snippets

Type these prefixes and press `Tab`:

| Prefix | Expands to |
|--------|------------|
| `section` | `{ikb_section}...{/ikb_section}` |
| `container` | `{ikb_container}...{/ikb_container}` |
| `text` | `{ikb_text}...{/ikb_text}` |
| `button` | `{ikb_button}...{/ikb_button}` |
| `if` | `{if condition="..."}...{/if}` |
| `for` | `{for items="..." as="..."}...{/for}` |
| `include` | `{include file="..." /}` |

---

## ⚙️ Commands

Access via Command Palette (`Ctrl+Shift+P` / `Cmd+Shift+P`):

- **DiSyL: Format Document** - Format with proper indentation
- **DiSyL: Show Component Preview** - Preview components
- **DiSyL: Validate Document** - Manual validation
- **DiSyL: Insert Component** - Quick component picker

---

## 🔧 Settings

Configure in VS Code settings (`Ctrl+,` / `Cmd+,`):

```json
{
  "disyl.validateOnType": true,
  "disyl.formatOnSave": true,
  "disyl.maxNumberOfProblems": 100
}
```

---

## 📖 Example Template

Complete example showing all features:

```disyl
{!-- Header --}
{include file="components/header.disyl" /}

{!-- Hero Section --}
{ikb_section type="hero" padding="large"}
    {ikb_container size="xlarge"}
        {ikb_text size="3xl" weight="bold"}
            {page.title | esc_html}
        {/ikb_text}
        
        {ikb_button variant="primary" size="large"}
            Get Started
        {/ikb_button}
    {/ikb_container}
{/ikb_section}

{!-- Blog Posts --}
{ikb_section type="blog"}
    {ikb_container}
        {if condition="{posts}"}
            {ikb_grid columns=3 gap="medium"}
                {for items="{posts}" as="post"}
                    {ikb_card shadow="medium"}
                        {if condition="{post.thumbnail}"}
                            {ikb_image 
                                src="{post.thumbnail | esc_url}" 
                                alt="{post.title | esc_attr}"
                                lazy=true
                            /}
                        {/if}
                        
                        {ikb_text size="xl" weight="semibold"}
                            {post.title | esc_html}
                        {/ikb_text}
                        
                        {ikb_text}
                            {post.excerpt | strip_tags | truncate:length=150}
                        {/ikb_text}
                    {/ikb_card}
                {/for}
            {/ikb_grid}
        {else}
            <p>No posts found.</p>
        {/if}
    {/ikb_container}
{/ikb_section}

{!-- Footer --}
{include file="components/footer.disyl" /}
```

---

## 🐛 Troubleshooting

### Extension Not Working
1. Check language mode (bottom right): Should say "DiSyL"
2. Reload window: `Developer: Reload Window`
3. Check Output panel: `View > Output > DiSyL Language Server`

### No Completions
1. Ensure file has `.disyl` extension
2. Try manual trigger: `Ctrl+Space`
3. Check settings: `disyl.validateOnType` should be `true`

### Formatting Not Working
1. Try manual format: `Shift+Alt+F`
2. Check command palette: "Format Document"
3. Verify file is saved as `.disyl`

---

## 📚 Learn More

- **Full Features**: See `LSP_FEATURES.md`
- **Development**: See `DEVELOPMENT.md`
- **Changelog**: See `CHANGELOG.md`
- **Build Info**: See `BUILD_SUMMARY.md`

---

## 🎉 You're Ready!

Start building beautiful DiSyL templates with full IDE support!

For help: https://github.com/ikabud/disyl/issues
