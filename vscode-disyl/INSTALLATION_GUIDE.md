# DiSyL Extension Installation Guide

## Current Status ✅

Based on diagnostics, your extension is **properly installed**:
- ✅ Extension: `ikabud.disyl@0.5.0`
- ✅ Location: `~/.windsurf/extensions/ikabud.disyl-0.5.0/`
- ✅ Grammar file: Present (11,989 bytes)
- ✅ Language config: Present
- ✅ File association: `*.disyl` → `disyl`

## The Problem

Windsurf is still showing `.disyl` files as "Plain Text" or "HTML" because:
1. **Windsurf hasn't fully restarted** - The extension host needs to reload
2. **File association cache** - Windsurf may have cached the old association

## Solution: Complete Restart Required

**"Reload Window" is NOT enough!** You must completely restart Windsurf:

### Method 1: Complete Restart (Recommended)

```bash
# 1. Close ALL Windsurf windows
# 2. Kill all Windsurf processes
pkill -9 windsurf

# 3. Wait 2 seconds
sleep 2

# 4. Start Windsurf fresh
windsurf /var/www/html/ikabud-kernel/vscode-disyl/test-simple.disyl
```

### Method 2: Manual Steps

1. **Close ALL Windsurf windows** (not just the current one)
2. **Verify no Windsurf processes are running:**
   ```bash
   ps aux | grep windsurf
   ```
3. **If any are running, kill them:**
   ```bash
   pkill -9 windsurf
   ```
4. **Start Windsurf fresh**
5. **Open a `.disyl` file**
6. **Check bottom-right corner** - should now say **"DiSyL"** (not "Plain Text")

### Method 3: Force Language Selection (Temporary)

If restart doesn't work immediately:

1. Open a `.disyl` file
2. Click on the language indicator in bottom-right corner (shows "Plain Text" or "HTML")
3. Type: `DiSyL`
4. Select **"DiSyL"** from the list
5. When prompted, select **"Configure File Association for '.disyl'"**
6. Choose **"DiSyL"**

## Verify It's Working

After restart, you should see:

### 1. Language Indicator
Bottom-right corner shows: **DiSyL** (not "Plain Text")

### 2. Syntax Highlighting
- **Blue/Purple**: `if`, `for`, `else`, `include`
- **Green**: `ikb_section`, `ikb_text`, `ikb_button`
- **Orange**: `{post.title}`, `{user.name}`
- **Gray**: `{!-- comments --}`
- **Red/Pink**: Strings in quotes

### 3. IntelliSense
Type `section` and press Tab - should expand to a full section component

## Still Not Working?

### Check Extension is Active

```bash
# Run diagnostics
cd /var/www/html/ikabud-kernel/vscode-disyl
./diagnose.sh
```

### Reinstall Extension

```bash
# Uninstall
windsurf --uninstall-extension ikabud.disyl

# Reinstall
./install.sh

# Complete restart (kill all processes)
pkill -9 windsurf
sleep 2
windsurf
```

### Check Windsurf Logs

1. Press `Ctrl+Shift+P`
2. Type: `Developer: Show Logs`
3. Select: **"Extension Host"**
4. Look for errors mentioning "disyl" or "ikabud"

### Verify Extension Files

```bash
# Check if all files are present
ls -la ~/.windsurf/extensions/ikabud.disyl-0.5.0/

# Should see:
# - package.json
# - syntaxes/disyl.tmLanguage.json
# - language-configuration.json
# - out/extension.js
```

## Common Issues

### Issue: "Extension installed but language still shows as Plain Text"
**Cause**: Windsurf extension host hasn't reloaded  
**Solution**: Complete restart (kill all processes)

### Issue: "Bottom-right shows HTML instead of DiSyL"
**Cause**: File association in settings is wrong  
**Solution**: Check `~/.config/Windsurf/User/settings.json` has:
```json
"files.associations": {
    "*.disyl": "disyl"
}
```

### Issue: "No syntax highlighting at all"
**Cause**: Grammar file missing or corrupted  
**Solution**: 
```bash
# Check grammar file
cat ~/.windsurf/extensions/ikabud.disyl-0.5.0/syntaxes/disyl.tmLanguage.json | jq .

# If error, reinstall
windsurf --uninstall-extension ikabud.disyl
./install.sh
```

### Issue: "Extension not found in marketplace"
**Cause**: DiSyL is not published to Open-VSX (Windsurf's marketplace)  
**Solution**: Install from VSIX file (which you've already done correctly)

## Why Complete Restart is Required

VSCode/Windsurf extensions work in an "Extension Host" process that:
1. Loads extension manifests at startup
2. Registers language grammars and configurations
3. Caches file associations

**"Reload Window"** only reloads the UI, not the Extension Host.  
**Complete restart** forces Extension Host to reload all extensions fresh.

## Next Steps

1. **Close ALL Windsurf windows now**
2. **Run:** `pkill -9 windsurf`
3. **Wait 2 seconds**
4. **Open Windsurf**
5. **Open:** `test-simple.disyl`
6. **Check bottom-right corner** - should say "DiSyL"

If it works, you're done! 🎉

If not, run `./diagnose.sh` and check the troubleshooting section.
