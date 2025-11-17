# Drupal Phoenix Theme Documentation

Complete documentation for the Phoenix theme powered by DiSyL (Declarative Ikabud Syntax Language) for Drupal 10/11.

## 📚 Documentation Files

### [DRUPAL_PHOENIX_COMPLETE.md](DRUPAL_PHOENIX_COMPLETE.md)
**Complete Implementation Guide** - Comprehensive documentation covering:
- Architecture and file structure
- Implementation details for all components
- DrupalRenderer deep dive
- Template examples and patterns
- Configuration and setup
- Testing and debugging
- Performance optimization
- Troubleshooting guide
- Migration from Joomla
- Best practices

### [QUICK_REFERENCE.md](QUICK_REFERENCE.md)
**Quick Reference Guide** - Fast lookup for:
- All DiSyL components with syntax
- Filter usage examples
- Conditional patterns
- Context variables
- Common template patterns
- Useful commands
- Debugging tips

## 🚀 Quick Start

### Installation
```bash
cd /var/www/html/ikabud-kernel/instances/dpl-now-drupal/themes/phoenix
drush theme:enable phoenix
drush config-set system.theme default phoenix
drush cache:rebuild
```

### Create Your First Template
```bash
cd themes/phoenix/disyl
nano my-template.disyl
```

```disyl
{ikb_include template="components/header.disyl" /}

{ikb_section padding="large"}
    {ikb_container size="medium"}
        <h1>Hello DiSyL!</h1>
        
        {ikb_query type="post" limit=5}
            <article>
                <h2>{item.title | esc_html}</h2>
                <p>{item.excerpt | truncate:length=150}</p>
            </article>
        {/ikb_query}
    {/ikb_container}
{/ikb_section}

{ikb_include template="components/footer.disyl" /}
```

## 📖 Key Features

### ✅ Complete DiSyL Integration
- All core components (text, container, section, image, include, query)
- All Drupal components (block, region, menu, view, form)
- Full filter system (esc_html, esc_url, date, truncate, etc.)
- Conditional rendering with operators
- Expression evaluation with filter chains

### ✅ Production Ready
- **Site URL**: http://genesis.test
- **Status**: Fully functional
- **Version**: 2.0 (Drupal-Native)
- **CMS**: Drupal 10/11

### ✅ Cross-CMS Compatible
- Same DiSyL syntax works across WordPress, Drupal, Joomla
- Unified component system
- Consistent filter behavior
- Portable templates

## 🎯 Common Use Cases

### Blog Post Listing
```disyl
{ikb_query type="post" limit=6}
    <article class="post-card">
        {if condition="item.thumbnail"}
            {ikb_image src="{item.thumbnail | esc_url}" alt="{item.title | esc_attr}" lazy=true /}
        {/if}
        <h3><a href="{item.url | esc_url}">{item.title | esc_html}</a></h3>
        <p>{item.excerpt | strip_tags | truncate:length=150}</p>
    </article>
{/ikb_query}
```

### Conditional Content
```disyl
{if condition="user.logged_in"}
    <div class="user-dashboard">
        Welcome back, {user.name | esc_html}!
    </div>
{/if}
```

### Drupal Integration
```disyl
{drupal_region name="header" /}
{drupal_block id="phoenix_site_branding" /}
{drupal_menu name="main" /}
{drupal_view name="frontpage" display="block_1" /}
```

## 🔧 Development

### File Structure
```
themes/phoenix/
├── disyl/              # DiSyL templates
│   ├── components/     # Reusable components
│   ├── home.disyl      # Homepage
│   ├── single.disyl    # Single post
│   └── page.disyl      # Page template
├── includes/           # PHP integration
├── templates/          # Twig templates
├── assets/             # CSS, JS, images
└── phoenix.theme       # Theme hooks
```

### Adding Custom Components
```php
// In kernel/DiSyL/Renderers/DrupalRenderer.php
$this->registerComponent('my_component', function($node, $context) {
    // Your component logic
    return '<div class="my-component">...</div>';
});
```

### Adding Custom Filters
```php
// In kernel/DiSyL/Renderers/DrupalRenderer.php
$this->registerFilter('my_filter', function($value, ...$args) {
    // Your filter logic
    return strtoupper($value);
});
```

## 🐛 Debugging

### Enable Debug Logging
```php
// In includes/disyl-integration.php
\Drupal::logger('phoenix')->debug('Rendering: @template', [
    '@template' => $template_name
]);
```

### Check Logs
```bash
drush watchdog:show --type=phoenix --count=20
```

### Test Rendering
```bash
curl -s http://genesis.test | grep -E "(ikb-section|post-card)"
```

## 📊 Performance

### Optimization Checklist
- ✅ Enable Drupal CSS/JS aggregation
- ✅ Use `limit` on all queries
- ✅ Enable lazy loading on images
- ✅ Cache expensive operations
- ✅ Clear cache after template changes

### Cache Commands
```bash
drush cache:rebuild
drush config-set system.performance css.preprocess 1
drush config-set system.performance js.preprocess 1
```

## 🔒 Security

### Security Checklist
- ✅ Always escape output: `{value | esc_html}`
- ✅ Sanitize URLs: `{url | esc_url}`
- ✅ Escape attributes: `{attr | esc_attr}`
- ✅ Strip tags from user content: `{content | strip_tags}`
- ✅ Never output raw user input

## 📦 Components Reference

### Core Components
- `ikb_text` - Text styling
- `ikb_container` - Responsive containers
- `ikb_section` - Page sections
- `ikb_image` - Optimized images
- `ikb_include` - Template includes
- `ikb_query` - Data queries

### Drupal Components
- `drupal_block` - Render blocks
- `drupal_region` - Render regions
- `drupal_menu` - Render menus
- `drupal_view` - Render views
- `drupal_form` - Render forms

### Filters
- `esc_html` - HTML escaping
- `esc_url` - URL sanitization
- `esc_attr` - Attribute escaping
- `date:format="..."` - Date formatting
- `truncate:length=...` - Text truncation
- `strip_tags` - Remove HTML tags
- `t` - Translation

## 🆘 Troubleshooting

### DiSyL content not rendering?
```bash
drush cache:rebuild
drush watchdog:show --type=php
ls -la themes/phoenix/disyl/home.disyl
```

### Filters not working?
- Verify filter is registered in `DrupalRenderer.php`
- Check syntax: `{value | filter:param="value"}`
- Clear cache after adding filters

### Query returns no results?
```bash
drush sql:query "SELECT nid, title, type FROM node_field_data WHERE type='article' LIMIT 5"
```

## 📚 Additional Resources

### DiSyL Documentation
- Syntax Guide: `/docs/disyl/SYNTAX.md`
- Component Reference: `/docs/disyl/COMPONENTS.md`
- Filter Reference: `/docs/disyl/FILTERS.md`

### Code Locations
- DiSyL Engine: `/kernel/DiSyL/`
- DrupalRenderer: `/kernel/DiSyL/Renderers/DrupalRenderer.php`
- Theme Files: `/instances/dpl-now-drupal/themes/phoenix/`

## 🎓 Learning Path

1. **Start Here**: Read [QUICK_REFERENCE.md](QUICK_REFERENCE.md)
2. **Deep Dive**: Read [DRUPAL_PHOENIX_COMPLETE.md](DRUPAL_PHOENIX_COMPLETE.md)
3. **Practice**: Create custom templates
4. **Extend**: Add custom components and filters
5. **Optimize**: Follow performance best practices

## 📝 Changelog

### Version 2.0 (Current)
- ✅ Complete Drupal integration
- ✅ All DiSyL components implemented
- ✅ Filter system with 7 filters
- ✅ Conditional rendering support
- ✅ Query system for Drupal nodes
- ✅ Expression evaluation with filter chains
- ✅ Production-ready implementation

## 📄 License

[Your License Here]

---

**Theme**: Phoenix v2.0  
**Engine**: DiSyL (Declarative Ikabud Syntax Language)  
**CMS**: Drupal 10/11  
**Status**: Production Ready ✅

*Documentation last updated: November 17, 2025*
