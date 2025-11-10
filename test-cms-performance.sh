#\!/bin/bash

# Comprehensive CMS Performance Test
# Tests loading times, cache performance, and file sizes

echo "=========================================="
echo "IKABUD KERNEL - CMS PERFORMANCE TEST"
echo "=========================================="
echo ""

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Test function
test_url() {
    local url=$1
    local name=$2
    
    # First request (cache MISS)
    echo -e "${YELLOW}Testing: $name${NC}"
    echo "URL: $url"
    
    # Clear cache first
    curl -s -X DELETE "http://localhost/api/v1/cache/$(echo $url | cut -d'/' -f3 | cut -d'.' -f1)" > /dev/null 2>&1
    sleep 1
    
    # MISS request
    miss_time=$(curl -w "%{time_total}" -o /dev/null -s "$url")
    miss_header=$(curl -I -s "$url" | grep "X-Cache:" | awk '{print $2}')
    
    # HIT request
    hit_time=$(curl -w "%{time_total}" -o /dev/null -s "$url")
    hit_header=$(curl -I -s "$url" | grep "X-Cache:" | awk '{print $2}')
    
    # Calculate speedup
    speedup=$(echo "scale=1; $miss_time / $hit_time" | bc)
    
    echo -e "  Cache MISS: ${RED}${miss_time}s${NC} (X-Cache: $miss_header)"
    echo -e "  Cache HIT:  ${GREEN}${hit_time}s${NC} (X-Cache: $hit_header)"
    echo -e "  Speedup:    ${BLUE}${speedup}x faster${NC}"
    echo ""
}

# Test WordPress
echo "=========================================="
echo "1. WORDPRESS PERFORMANCE"
echo "=========================================="
test_url "http://akira.test/" "WordPress Frontend"

# Test Joomla
echo "=========================================="
echo "2. JOOMLA PERFORMANCE"
echo "=========================================="
test_url "http://phoenix.test/" "Joomla Frontend"

# Test Drupal
echo "=========================================="
echo "3. DRUPAL PERFORMANCE"
echo "=========================================="
test_url "http://drupal.test/" "Drupal Frontend"

# File size comparison
echo "=========================================="
echo "4. FILE SIZE COMPARISON"
echo "=========================================="
echo ""

# WordPress
echo -e "${YELLOW}WORDPRESS:${NC}"
wp_shared=$(du -sh /var/www/html/ikabud-kernel/shared-cores/wordpress 2>/dev/null | awk '{print $1}')
wp_instance=$(du -sh /var/www/html/ikabud-kernel/instances/inst_5ca59a2151e98cd1 2>/dev/null | awk '{print $1}')
echo "  Shared Core:  $wp_shared"
echo "  Instance:     $wp_instance"
echo ""

# Joomla
echo -e "${YELLOW}JOOMLA:${NC}"
jml_shared=$(du -sh /var/www/html/ikabud-kernel/shared-cores/joomla 2>/dev/null | awk '{print $1}')
jml_instance=$(du -sh /var/www/html/ikabud-kernel/instances/inst_556c8d4b18623f27 2>/dev/null | awk '{print $1}')
echo "  Shared Core:  $jml_shared"
echo "  Instance:     $jml_instance"
echo ""

# Drupal
echo -e "${YELLOW}DRUPAL:${NC}"
dpl_shared=$(du -sh /var/www/html/ikabud-kernel/shared-cores/drupal 2>/dev/null | awk '{print $1}')
dpl_instance=$(du -sh /var/www/html/ikabud-kernel/instances/dpl-test-001 2>/dev/null | awk '{print $1}')
echo "  Shared Core:  $dpl_shared"
echo "  Instance:     $dpl_instance"
echo ""

# Cache statistics
echo "=========================================="
echo "5. CACHE STATISTICS"
echo "=========================================="
echo ""

cache_files=$(ls -1 /var/www/html/ikabud-kernel/storage/cache/*.cache 2>/dev/null | wc -l)
cache_size=$(du -sh /var/www/html/ikabud-kernel/storage/cache 2>/dev/null | awk '{print $1}')

echo "  Total cached files: $cache_files"
echo "  Total cache size:   $cache_size"
echo ""

echo "=========================================="
echo "TEST COMPLETE"
echo "=========================================="
