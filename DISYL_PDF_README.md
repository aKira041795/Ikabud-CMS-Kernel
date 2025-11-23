# DiSyL Syntax Reference PDF Documentation

## 📄 Generated Documentation

A comprehensive PDF reference guide for all DiSyL supported syntax has been created.

### Files Created

1. **DISYL_SYNTAX_REFERENCE.pdf** (13MB)
   - Complete syntax reference for all supported CMS platforms
   - Organized by CMS (WordPress, Joomla, Drupal)
   - Includes code examples, best practices, and quick reference tables

2. **DISYL_SYNTAX_REFERENCE.md** (Markdown source)
   - Source markdown file used to generate the PDF
   - Can be viewed directly in any markdown viewer

### Document Structure

The PDF documentation is organized into the following sections:

#### 1. Introduction
- Overview of DiSyL
- Supported CMS platforms
- Key features

#### 2. Core Syntax
- Comments
- CMS header declarations
- Variables and expressions

#### 3. Universal Components
- **Layout Components**: `ikb_section`, `ikb_container`, `ikb_grid`
- **Content Components**: `ikb_text`, `ikb_button`, `ikb_image`, `ikb_card`
- Complete attribute reference for each component

#### 4. WordPress-Specific Syntax
- CMS declaration
- `ikb_query` for WordPress posts
- `ikb_menu` for navigation menus
- `ikb_widget_area` for sidebars
- WordPress-specific filters (`wp_trim_words`, `wp_kses_post`)

#### 5. Joomla-Specific Syntax
- CMS declaration
- `ikb_query` for Joomla articles
- `joomla_module` for module positions
- `joomla_component` for component output
- `joomla_message` for system messages
- `joomla_params` for template parameters

#### 6. Drupal-Specific Syntax
- Planned components and syntax
- Future implementation details

#### 7. Filters Reference
- **Security Filters**: `esc_html`, `esc_url`, `esc_attr`, `strip_tags`
- **Text Manipulation**: `upper`, `lower`, `capitalize`, `truncate`
- **Date Formatting**: `date` filter with format options
- **CMS-Specific Filters**: WordPress and Joomla filters

#### 8. Conditional Logic
- Basic conditionals
- If-else statements
- Negation
- Multiple conditions
- CMS-specific conditionals

#### 9. Loops & Queries
- For loops
- Nested loops
- Query loops with conditionals

#### 10. Best Practices
- Security best practices
- CMS declaration guidelines
- Semantic component usage
- Template organization

#### 11. Complete Examples
- WordPress blog homepage
- Joomla article page
- Full working templates

#### 12. Appendix: Quick Reference
- Component cheat sheet
- Filter cheat sheet
- Quick lookup tables

### Usage

The PDF can be:
- **Printed** for offline reference
- **Shared** with team members
- **Used as training material** for new developers
- **Referenced** during development

### Viewing the PDF

```bash
# Linux
xdg-open /var/www/html/ikabud-kernel/DISYL_SYNTAX_REFERENCE.pdf

# macOS
open /var/www/html/ikabud-kernel/DISYL_SYNTAX_REFERENCE.pdf

# Windows
start /var/www/html/ikabud-kernel/DISYL_SYNTAX_REFERENCE.pdf
```

### Regenerating the PDF

If you need to regenerate the PDF after making changes to the markdown:

```bash
cd /var/www/html/ikabud-kernel

# Using wkhtmltopdf (installed)
wkhtmltopdf \
    --enable-local-file-access \
    --page-size A4 \
    --margin-top 20mm \
    --margin-bottom 20mm \
    --margin-left 15mm \
    --margin-right 15mm \
    DISYL_SYNTAX_REFERENCE.html \
    DISYL_SYNTAX_REFERENCE.pdf
```

### CMS Coverage

| CMS | Status | Components | Filters | Examples |
|-----|--------|------------|---------|----------|
| **WordPress** | ✅ Complete | 4 | 2 | 3 |
| **Joomla** | ✅ Complete | 5 | Standard | 2 |
| **Drupal** | 🔄 Planned | 3 | Standard | 1 |
| **Generic** | ✅ Complete | All universal | All standard | Multiple |

### Component Count by Category

- **Layout Components**: 3 (section, container, grid)
- **Content Components**: 4 (text, button, image, card)
- **Query Components**: 1 (ikb_query - CMS-specific)
- **WordPress Components**: 3 (menu, widget_area, query)
- **Joomla Components**: 4 (module, component, message, params)
- **Total Universal Components**: 7
- **Total CMS-Specific Components**: 7

### Filter Count

- **Security Filters**: 4
- **Text Manipulation**: 4
- **Date Formatting**: 1
- **WordPress Filters**: 2
- **Total Filters**: 11+

### Documentation Statistics

- **Total Pages**: ~50+ pages
- **Code Examples**: 100+
- **Component Definitions**: 14
- **Filter Definitions**: 11
- **Complete Templates**: 2
- **Quick Reference Tables**: 2

### Next Steps

1. **Review** the PDF documentation
2. **Share** with your development team
3. **Use** as reference during DiSyL template development
4. **Update** the markdown source as new features are added
5. **Regenerate** the PDF when updates are made

### Support

For questions or issues with the documentation:
- Check the source markdown file for the latest updates
- Refer to the kernel/DiSyL/README.md for technical details
- Review the manifest files in kernel/DiSyL/Manifests/

---

**Generated:** November 23, 2025  
**Version:** DiSyL 0.6.0  
**Format:** PDF (A4, 50+ pages)  
**Size:** 13MB

© 2025 Ikabud Team | MIT License
