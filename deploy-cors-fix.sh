#!/bin/bash
# Deploy Bluehost CORS Fix to WordPress Instance
# Usage: ./deploy-cors-fix.sh [instance_id]

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TEMPLATE_FILE="$SCRIPT_DIR/templates/ikabud-cors.php"

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}Ikabud Kernel - Bluehost CORS Fix${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""

# Check if template exists
if [ ! -f "$TEMPLATE_FILE" ]; then
    echo -e "${RED}Error: Template file not found at $TEMPLATE_FILE${NC}"
    exit 1
fi

echo -e "${GREEN}✓${NC} Template file found: ikabud-cors.php v1.1.0"
echo ""

# If instance ID provided, deploy to specific instance
if [ -n "$1" ]; then
    INSTANCE_ID="$1"
    INSTANCE_DIR="$SCRIPT_DIR/instances/inst_$INSTANCE_ID"
    
    if [ ! -d "$INSTANCE_DIR" ]; then
        echo -e "${RED}Error: Instance directory not found: $INSTANCE_DIR${NC}"
        exit 1
    fi
    
    TARGET_DIR="$INSTANCE_DIR/wp-content/mu-plugins"
    TARGET_FILE="$TARGET_DIR/ikabud-cors.php"
    
    # Create mu-plugins directory if it doesn't exist
    mkdir -p "$TARGET_DIR"
    
    # Backup existing file if it exists
    if [ -f "$TARGET_FILE" ]; then
        BACKUP_FILE="$TARGET_FILE.backup.$(date +%Y%m%d_%H%M%S)"
        cp "$TARGET_FILE" "$BACKUP_FILE"
        echo -e "${YELLOW}→${NC} Backed up existing file to: $(basename $BACKUP_FILE)"
    fi
    
    # Copy new file
    cp "$TEMPLATE_FILE" "$TARGET_FILE"
    echo -e "${GREEN}✓${NC} Deployed to instance: $INSTANCE_ID"
    echo -e "  Location: $TARGET_FILE"
    echo ""
    
else
    # Deploy to all instances
    INSTANCES_DIR="$SCRIPT_DIR/instances"
    
    if [ ! -d "$INSTANCES_DIR" ]; then
        echo -e "${RED}Error: Instances directory not found: $INSTANCES_DIR${NC}"
        exit 1
    fi
    
    DEPLOYED_COUNT=0
    
    for INSTANCE_DIR in "$INSTANCES_DIR"/inst_*; do
        if [ -d "$INSTANCE_DIR" ]; then
            INSTANCE_ID=$(basename "$INSTANCE_DIR" | sed 's/inst_//')
            TARGET_DIR="$INSTANCE_DIR/wp-content/mu-plugins"
            TARGET_FILE="$TARGET_DIR/ikabud-cors.php"
            
            # Create mu-plugins directory if it doesn't exist
            mkdir -p "$TARGET_DIR"
            
            # Backup existing file if it exists
            if [ -f "$TARGET_FILE" ]; then
                BACKUP_FILE="$TARGET_FILE.backup.$(date +%Y%m%d_%H%M%S)"
                cp "$TARGET_FILE" "$BACKUP_FILE"
            fi
            
            # Copy new file
            cp "$TEMPLATE_FILE" "$TARGET_FILE"
            echo -e "${GREEN}✓${NC} Deployed to instance: $INSTANCE_ID"
            
            DEPLOYED_COUNT=$((DEPLOYED_COUNT + 1))
        fi
    done
    
    echo ""
    echo -e "${GREEN}✓${NC} Deployed to $DEPLOYED_COUNT instance(s)"
    echo ""
fi

echo -e "${YELLOW}Next Steps:${NC}"
echo "1. Clear browser cache (Ctrl+Shift+Delete)"
echo "2. Clear WordPress cache (if using caching plugin)"
echo "3. Clear Bluehost cache (via cPanel)"
echo "4. Test adding a new post/page"
echo ""
echo -e "${YELLOW}Testing:${NC}"
echo "- Open Browser DevTools (F12) → Network tab"
echo "- Try to add a post/page"
echo "- Check for OPTIONS request returning 200"
echo "- Verify no CORS errors in console"
echo ""
echo -e "${GREEN}Deployment complete!${NC}"
echo ""
echo "For detailed information, see: BLUEHOST_CORS_FIX.md"
