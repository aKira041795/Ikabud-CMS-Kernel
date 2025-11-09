# Conditional Loading - Quick Reference

## 🚀 Quick Start

```bash
# 1. Generate manifest
php bin/generate-plugin-manifest wp-test-001

# 2. Install drop-in
cp templates/ikabud-conditional-loader.php instances/wp-test-001/wp-content/mu-plugins/

# 3. Done! Test your site
```

---

## 📝 Manifest Syntax

```json
{
  "plugins": {
    "plugin-folder/plugin-file.php": {
      "enabled": true,
      "load_on": {
        "routes": ["/path", "*"],
        "post_types": ["product"],
        "shortcodes": ["my_shortcode"],
        "post_meta": ["_meta_key"],
        "query_params": ["param"],
        "admin": true
      },
      "priority": 10
    }
  }
}
```

---

## 🎯 Common Patterns

### Load Everywhere
```json
"load_on": { "routes": ["*"], "admin": true }
```

### Load on Specific Path
```json
"load_on": { "routes": ["/shop", "/cart"], "admin": true }
```

### Load Only in Admin
```json
"load_on": { "routes": [], "admin": true }
```

### Load for Post Type
```json
"load_on": { "post_types": ["product"], "admin": true }
```

### Load When Shortcode Present
```json
"load_on": { "shortcodes": ["contact-form-7"], "admin": true }
```

---

## 🔧 API Endpoints

### Get Stats
```bash
GET /api/instances/wp-test-001/conditional-loading/stats
```

### Get Manifest
```bash
GET /api/instances/wp-test-001/conditional-loading/manifest
```

### Update Manifest
```bash
PUT /api/instances/wp-test-001/conditional-loading/manifest
Content-Type: application/json

{
  "plugins": { ... }
}
```

### Test Route
```bash
POST /api/instances/wp-test-001/conditional-loading/test
Content-Type: application/json

{
  "uri": "/shop/product-123",
  "context": {}
}
```

### Generate Manifest
```bash
POST /api/instances/wp-test-001/conditional-loading/generate
```

---

## 📊 Priority Levels

| Priority | Use Case | Examples |
|----------|----------|----------|
| 1-5 | Critical | Security, Caching |
| 10 | Normal | Most plugins |
| 20-30 | Late | Analytics, Social |
| 50+ | Admin Only | Backup, Migration |

---

## 🐛 Troubleshooting

### Plugin Not Loading?
1. Check `enabled: true`
2. Verify route pattern matches
3. Check JSON syntax
4. View error logs

### All Plugins Loading?
1. Check manifest exists
2. Verify drop-in installed
3. Not in admin area?
4. Check constant defined

### No Performance Gain?
1. Cache working?
2. Too many `"*"` routes?
3. Check plugin priorities
4. Monitor with stats API

---

## 📈 Performance Expectations

| Scenario | Before | After (Cached) | After (Uncached) |
|----------|--------|----------------|------------------|
| Blog Post | 1,600ms | 60ms (26x) | 800ms (2x) |
| Shop Page | 1,800ms | 60ms (30x) | 1,200ms (1.5x) |
| Memory | 50MB | 5MB (10x) | 25MB (2x) |

---

## 🎨 Plugin Type Defaults

| Plugin Type | Default Routes | Priority |
|-------------|----------------|----------|
| Security | `["*"]` | 1 |
| Caching | `["*"]` | 2 |
| SEO | `["*"]` | 5 |
| E-commerce | `/shop, /cart, /checkout` | 10 |
| Page Builder | Post meta detection | 15 |
| Contact Forms | `/contact` | 20 |
| Analytics | `["*"]` | 30 |
| Backup | `[]` (admin only) | 50 |

---

## 💡 Tips

- Start with `"*"` for all plugins, optimize later
- Test thoroughly after changes
- Use priority to control load order
- Monitor with stats API
- Keep backup of working manifest
- Document your changes

---

## 🔗 Related Docs

- [CONDITIONAL_LOADING_ARCHITECTURE.md](CONDITIONAL_LOADING_ARCHITECTURE.md) - Full architecture
- [CONDITIONAL_LOADING_SETUP.md](CONDITIONAL_LOADING_SETUP.md) - Detailed setup guide
- [HYBRID_KERNEL_ARCHITECTURE.md](HYBRID_KERNEL_ARCHITECTURE.md) - Cache system

---

**Version**: 1.0  
**Last Updated**: November 9, 2025
