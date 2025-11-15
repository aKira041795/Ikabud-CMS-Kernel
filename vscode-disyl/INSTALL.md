# DiSyL Extension Installation Guide

## For Windsurf / VS Code

### Method 1: Install from VSIX (Recommended)

1. **Package the extension** (if not already packaged):
   ```bash
   cd /var/www/html/ikabud-kernel/vscode-disyl
   npm install -g @vscode/vsce
   vsce package
   ```
   This creates `disyl-0.3.0.vsix`

2. **Install in Windsurf/VS Code**:
   - Open Windsurf or VS Code
   - Press `Ctrl+Shift+P` (Windows/Linux) or `Cmd+Shift+P` (Mac)
   - Type: `Extensions: Install from VSIX...`
   - Select the `disyl-0.3.0.vsix` file
   - Restart the editor

### Method 2: Install from Source (Development)

1. **Copy to extensions folder**:
   
   **Linux/Mac:**
   ```bash
   cp -r /var/www/html/ikabud-kernel/vscode-disyl ~/.vscode/extensions/disyl-0.3.0
   # or for Windsurf:
   cp -r /var/www/html/ikabud-kernel/vscode-disyl ~/.windsurf/extensions/disyl-0.3.0
   ```
   
   **Windows:**
   ```powershell
   xcopy /E /I "C:\path\to\vscode-disyl" "%USERPROFILE%\.vscode\extensions\disyl-0.3.0"
   # or for Windsurf:
   xcopy /E /I "C:\path\to\vscode-disyl" "%USERPROFILE%\.windsurf\extensions\disyl-0.3.0"
   ```

2. **Restart the editor**

### Method 3: Symlink (Development)

For active development:

```bash
# VS Code
ln -s /var/www/html/ikabud-kernel/vscode-disyl ~/.vscode/extensions/disyl-0.3.0

# Windsurf
ln -s /var/www/html/ikabud-kernel/vscode-disyl ~/.windsurf/extensions/disyl-0.3.0
```

## Verify Installation

1. Open a `.disyl` file
2. Check the language mode in the bottom right corner
3. It should show "DiSyL"
4. Syntax highlighting should be active

## Test the Extension

Create a test file `test.disyl`:

```disyl
{!-- Test DiSyL Syntax Highlighting --}
{include file="components/header.disyl" /}

{ikb_section type="main" padding="large"}
    {ikb_container size="large"}
        {!-- This is a comment --}
        {ikb_text size="2xl" weight="bold"}
            {post.title | esc_html}
        {/ikb_text}
        
        {if condition="{post.thumbnail}"}
            {ikb_image src="{post.thumbnail | esc_url}" alt="{post.title | esc_attr}" /}
        {/if}
        
        <div class="content">
            {post.content | raw}
        </div>
        
        {for items="{posts}" as="post"}
            <article>
                <h2>{post.title | upper | esc_html}</h2>
                <p>{post.excerpt | strip_tags | truncate:length=150,append="..."}</p>
                <time>{post.date | date:format="F j, Y"}</time>
            </article>
        {/for}
    {/ikb_container}
{/ikb_section}

{include file="components/footer.disyl" /}
```

## Test Snippets

Type these prefixes and press `Tab`:

- `section` → Creates ikb_section
- `if` → Creates if statement
- `for` → Creates for loop
- `fesc_html` → Creates expression with esc_html filter
- `template` → Creates complete template structure

## Troubleshooting

### Extension not loading

1. Check the extensions folder:
   ```bash
   ls ~/.vscode/extensions/
   # or
   ls ~/.windsurf/extensions/
   ```

2. Verify the extension is listed:
   - Open Extensions panel (`Ctrl+Shift+X`)
   - Search for "DiSyL"
   - Should show as installed

3. Check for errors:
   - Open Developer Tools: `Help` → `Toggle Developer Tools`
   - Check Console for errors

### Syntax highlighting not working

1. Verify file extension is `.disyl`
2. Check language mode (bottom right corner)
3. Manually set language:
   - Click language mode
   - Select "DiSyL" from the list

### Snippets not working

1. Verify snippets are enabled:
   - `File` → `Preferences` → `Settings`
   - Search for "snippets"
   - Ensure "Editor: Snippet Suggestions" is not "none"

2. Try pressing `Ctrl+Space` after typing the prefix

## Uninstall

### From UI
1. Open Extensions panel (`Ctrl+Shift+X`)
2. Find "DiSyL"
3. Click "Uninstall"

### Manually
```bash
rm -rf ~/.vscode/extensions/disyl-0.3.0
# or
rm -rf ~/.windsurf/extensions/disyl-0.3.0
```

## Next Steps

After installation:

1. Open your DiSyL templates
2. Try the snippets (type prefix + Tab)
3. Use syntax highlighting for better readability
4. Explore auto-completion features
5. Report any issues on GitHub

## Support

- **GitHub**: https://github.com/ikabud/disyl
- **Documentation**: https://ikabud.com/disyl
- **Issues**: https://github.com/ikabud/disyl/issues

---

**Happy coding with DiSyL!** 🚀
