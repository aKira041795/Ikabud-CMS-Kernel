# DiSyL Filter Syntax - Quick Reference Guide

## 🎯 Overview

DiSyL v0.2 introduces comprehensive filter pipeline syntax for transforming expression values. Filters use the pipe operator `|` to chain transformations.

---

## 📖 Basic Syntax

### Simple Filter
```disyl
{variable | filter_name}
```

**Example:**
```disyl
{item.title | upper}
{item.content | strip_tags}
```

---

## 🔧 Filter Arguments

### Positional Arguments
```disyl
{variable | filter:value}
```

**Example:**
```disyl
{item.content | truncate:100}
{item.price | number_format:2}
```

### Named Arguments
```disyl
{variable | filter:name=value}
```

**Example:**
```disyl
{item.content | truncate:length=100}
{item.date | date:format="F j, Y"}
```

### Multiple Arguments
```disyl
{variable | filter:arg1,arg2,name=value}
```

**Example:**
```disyl
{item.content | truncate:length=100,append="..."}
{item.price | number_format:decimals=2,dec_point=".",thousands_sep=","}
```

---

## 🔗 Filter Chaining

### Multiple Filters
```disyl
{variable | filter1 | filter2 | filter3}
```

**Example:**
```disyl
{item.title | strip_tags | upper | esc_html}
{item.description | strip_tags | truncate:50 | upper}
```

---

## 📚 Common Filter Patterns

### Text Transformation
```disyl
{item.title | upper}                    <!-- UPPERCASE -->
{item.title | lower}                    <!-- lowercase -->
{item.title | capitalize}               <!-- Capitalize First Letter -->
{item.content | strip_tags}             <!-- Remove HTML tags -->
```

### Content Truncation
```disyl
{item.content | truncate:100}
{item.content | truncate:length=100,append="..."}
{item.description | truncate:50 | upper}
```

### Date Formatting
```disyl
{item.date | date:format="F j, Y"}      <!-- November 15, 2025 -->
{item.date | date:format="Y-m-d"}       <!-- 2025-11-15 -->
{item.created_at | date:format="M d"}   <!-- Nov 15 -->
```

### HTML Escaping
```disyl
{item.title | esc_html}                 <!-- Escape HTML entities -->
{item.url | esc_url}                    <!-- Escape URL -->
{item.attr | esc_attr}                  <!-- Escape HTML attribute -->
```

### Number Formatting
```disyl
{item.price | number_format:2}
{item.price | number_format:decimals=2,dec_point=".",thousands_sep=","}
{item.quantity | abs}                   <!-- Absolute value -->
```

---

## 🎨 Real-World Examples

### Blog Post Card
```disyl
{ikb_card}
    {ikb_text size="xl" weight="bold"}>
        {post.title | esc_html}
    {/ikb_text}
    
    {ikb_text size="sm" color="gray"}>
        {post.date | date:format="F j, Y"}
    {/ikb_text}
    
    {ikb_text}>
        {post.excerpt | strip_tags | truncate:length=150,append="..."}
    {/ikb_text}
    
    {ikb_link href="{post.url | esc_url}"}>
        Read More
    {/ikb_link}
{/ikb_card}
```

### Product Display
```disyl
{ikb_section}>
    {ikb_text size="2xl"}>
        {product.name | upper | esc_html}
    {/ikb_text}
    
    {ikb_text size="lg" color="green"}>
        ${product.price | number_format:2}
    {/ikb_text}
    
    {ikb_text}>
        {product.description | strip_tags | truncate:200}
    {/ikb_text}
{/ikb_section}
```

### User Profile
```disyl
{ikb_container}>
    {ikb_text weight="bold"}>
        {user.name | esc_html}
    {/ikb_text}
    
    {ikb_text size="sm"}>
        Member since {user.joined | date:format="F Y"}
    {/ikb_text}
    
    {ikb_text}>
        {user.bio | strip_tags | truncate:length=100,append="..."}
    {/ikb_text}
{/ikb_container}
```

---

## 🔍 Filter Grammar Reference

### EBNF Definition
```ebnf
expression ::= pipe_expression

pipe_expression ::= primary_expression { "|" filter_call }

filter_call ::= filter_name [ filter_arguments ]

filter_arguments ::= ":" argument { "," argument }

argument ::= named_argument | positional_argument

named_argument ::= identifier "=" argument_value

positional_argument ::= quoted_string | number_literal | boolean_literal
```

---

## ⚠️ Common Mistakes

### ❌ Missing Filter Name
```disyl
{item.title | }
<!-- Error: Expected filter name after pipe operator -->
```

### ❌ Missing Argument Value
```disyl
{item.content | truncate:length=}
<!-- Error: Expected value after = -->
```

### ❌ Unclosed Expression
```disyl
{item.title | upper
<!-- Error: Expected } -->
```

### ❌ Invalid Argument Syntax
```disyl
{item.content | truncate:100 200}
<!-- Error: Use comma to separate arguments -->
<!-- Correct: {item.content | truncate:100,200} -->
```

---

## 💡 Best Practices

### 1. **Always Escape Output**
```disyl
<!-- ✅ GOOD -->
{item.title | esc_html}
{item.url | esc_url}

<!-- ❌ BAD -->
{item.title}
{item.url}
```

### 2. **Chain Filters Logically**
```disyl
<!-- ✅ GOOD: Strip tags before truncating -->
{item.content | strip_tags | truncate:100}

<!-- ❌ BAD: Truncate before stripping (may cut in middle of tag) -->
{item.content | truncate:100 | strip_tags}
```

### 3. **Use Named Arguments for Clarity**
```disyl
<!-- ✅ GOOD: Clear intent -->
{item.content | truncate:length=100,append="..."}

<!-- ❌ LESS CLEAR: Positional arguments -->
{item.content | truncate:100,"..."}
```

### 4. **Format Dates Consistently**
```disyl
<!-- ✅ GOOD: Consistent format -->
{post.date | date:format="F j, Y"}
{event.start | date:format="F j, Y"}

<!-- ❌ BAD: Mixed formats -->
{post.date | date:format="F j, Y"}
{event.start | date:format="m/d/Y"}
```

---

## 🚀 Advanced Patterns

### Conditional Filtering
```disyl
{if condition="item.is_featured"}
    {item.title | upper | esc_html}
{else}
    {item.title | esc_html}
{/if}
```

### Nested Expressions with Filters
```disyl
{ikb_link href="{item.url | esc_url}" title="{item.title | esc_attr}">
    {item.title | esc_html}
{/ikb_link}
```

### Loop with Filters
```disyl
{for items="posts" as="post"}
    {ikb_text}>
        {post.title | upper} - {post.date | date:format="M d"}
    {/ikb_text}
{/for}
```

---

## 📋 Filter Checklist

Before deploying templates, verify:

- [ ] All user-generated content is escaped (`esc_html`, `esc_url`, `esc_attr`)
- [ ] Dates are formatted consistently
- [ ] Long content is truncated appropriately
- [ ] Filter chains are in logical order
- [ ] Named arguments are used for complex filters
- [ ] All expressions are properly closed with `}`

---

## 🔗 Related Documentation

- **Grammar Specification:** `DISYL_GRAMMAR_v0.2.ebnf`
- **Filter Implementation:** `/kernel/DiSyL/Filters/FilterRegistry.php`
- **Best Practices:** `DISYL_BEST_PRACTICES.md`
- **Changelog:** `DISYL_GRAMMAR_v0.2_CHANGELOG.md`

---

## 📞 Support

For questions or issues with filter syntax:
1. Check the grammar specification
2. Review filter implementation in `FilterRegistry.php`
3. Consult the DiSyL test suite for examples

---

**Document Version:** 1.0  
**Last Updated:** November 15, 2025  
**DiSyL Version:** v0.2+
