# DiSyL Code Examples

20+ practical examples from beginner to advanced.

---

## Table of Contents

1. [Beginner Examples](#beginner-examples)
2. [Intermediate Examples](#intermediate-examples)
3. [Advanced Examples](#advanced-examples)
4. [Real-World Examples](#real-world-examples)

---

## Beginner Examples

### 1. Hello World

```disyl
{ikb_text}Hello World{/ikb_text}
```

### 2. Styled Text

```disyl
{ikb_text size="xl" weight="bold" color="#333"}
    Welcome to DiSyL
{/ikb_text}
```

### 3. Simple Image

```disyl
{ikb_image src="logo.png" alt="Logo" /}
```

### 4. Basic Card

```disyl
{ikb_card title="My First Card"}
    {ikb_text}This is card content{/ikb_text}
{/ikb_card}
```

### 5. Two-Column Layout

```disyl
{ikb_block cols=2 gap=2}
    {ikb_card title="Left" /}
    {ikb_card title="Right" /}
{/ikb_block}
```

---

## Intermediate Examples

### 6. Hero Section

```disyl
{ikb_section type="hero" bg="#f0f0f0" padding="large"}
    {ikb_container width="xl"}
        {ikb_text size="2xl" weight="bold" align="center"}
            Welcome to Our Website
        {/ikb_text}
        {ikb_text size="lg" align="center" color="#666"}
            Discover amazing content
        {/ikb_text}
    {/ikb_container}
{/ikb_section}
```

### 7. Card Grid

```disyl
{ikb_block cols=3 gap=2}
    {ikb_card title="Feature 1" variant="elevated"}
        {ikb_text}Amazing feature description{/ikb_text}
    {/ikb_card}
    {ikb_card title="Feature 2" variant="elevated"}
        {ikb_text}Another great feature{/ikb_text}
    {/ikb_card}
    {ikb_card title="Feature 3" variant="elevated"}
        {ikb_text}One more feature{/ikb_text}
    {/ikb_card}
{/ikb_block}
```

### 8. Blog Post List

```disyl
{ikb_query type="post" limit=5 orderby="date"}
    {ikb_card title="{item.title}" link="{item.url}"}
        {ikb_text size="sm" color="#666"}
            {item.date} by {item.author}
        {/ikb_text}
        {ikb_text}
            {item.excerpt}
        {/ikb_text}
    {/ikb_card}
{/ikb_query}
```

### 9. Conditional Image

```disyl
{if condition="item.thumbnail"}
    {ikb_image src="{item.thumbnail}" alt="{item.title}" /}
{/if}
```

### 10. Product Grid

```disyl
{ikb_query type="product" limit=8 orderby="title"}
    {ikb_block cols=4 gap=2}
        {ikb_card 
            title="{item.title}"
            image="{item.thumbnail}"
            link="{item.url}"
            variant="elevated"
        }
            {ikb_text weight="bold" color="#333"}
                ${item.price}
            {/ikb_text}
        {/ikb_card}
    {/ikb_block}
{/ikb_query}
```

---

## Advanced Examples

### 11. Multi-Section Landing Page

```disyl
{!-- Hero Section --}
{ikb_section type="hero" bg="linear-gradient(135deg, #667eea 0%, #764ba2 100%)" padding="large"}
    {ikb_container width="xl"}
        {ikb_text size="2xl" weight="bold" align="center" color="#fff"}
            Build Amazing Websites
        {/ikb_text}
        {ikb_text size="lg" align="center" color="#f0f0f0"}
            With DiSyL's declarative syntax
        {/ikb_text}
    {/ikb_container}
{/ikb_section}

{!-- Features Section --}
{ikb_section type="content"}
    {ikb_container width="lg"}
        {ikb_text size="xl" weight="bold" align="center"}
            Key Features
        {/ikb_text}
        {ikb_block cols=3 gap=3}
            {ikb_card title="Fast" variant="elevated"}
                {ikb_text}Sub-millisecond compilation{/ikb_text}
            {/ikb_card}
            {ikb_card title="Type-Safe" variant="elevated"}
                {ikb_text}Validated attributes{/ikb_text}
            {/ikb_card}
            {ikb_card title="Extensible" variant="elevated"}
                {ikb_text}Custom components{/ikb_text}
            {/ikb_card}
        {/ikb_block}
    {/ikb_container}
{/ikb_section}

{!-- Footer --}
{ikb_section type="footer" bg="#333" padding="normal"}
    {ikb_text size="sm" color="#fff" align="center"}
        © 2025 Company Name. All rights reserved.
    {/ikb_text}
{/ikb_section}
```

### 12. Blog Archive with Sidebar

```disyl
{ikb_section type="content"}
    {ikb_container width="xl"}
        {ikb_block cols=3 gap=3}
            {!-- Main Content (2 columns) --}
            {ikb_block cols=1}
                {ikb_text size="2xl" weight="bold"}
                    Blog Archive
                {/ikb_text}
                
                {ikb_query type="post" limit=10 orderby="date"}
                    {ikb_card 
                        title="{item.title}"
                        image="{item.thumbnail}"
                        link="{item.url}"
                        variant="outlined"
                    }
                        {ikb_text size="sm" color="#666"}
                            {item.date}
                        {/ikb_text}
                        {ikb_text}
                            {item.excerpt}
                        {/ikb_text}
                    {/ikb_card}
                {/ikb_query}
            {/ikb_block}
            
            {!-- Sidebar (1 column) --}
            {ikb_block cols=1}
                {ikb_card title="Categories" variant="elevated"}
                    {ikb_text}Technology{/ikb_text}
                    {ikb_text}Design{/ikb_text}
                    {ikb_text}Business{/ikb_text}
                {/ikb_card}
                
                {ikb_card title="Recent Posts" variant="elevated"}
                    {ikb_query type="post" limit=5}
                        {ikb_text size="sm"}
                            {item.title}
                        {/ikb_text}
                    {/ikb_query}
                {/ikb_card}
            {/ikb_block}
        {/ikb_block}
    {/ikb_container}
{/ikb_section}
```

### 13. E-commerce Product Page

```disyl
{ikb_section type="content"}
    {ikb_container width="lg"}
        {ikb_block cols=2 gap=3}
            {!-- Product Image --}
            {ikb_block cols=1}
                {ikb_image 
                    src="{product.image}"
                    alt="{product.name}"
                    responsive=true
                />
            {/ikb_block}
            
            {!-- Product Details --}
            {ikb_block cols=1}
                {ikb_text size="2xl" weight="bold"}
                    {product.name}
                {/ikb_text}
                
                {ikb_text size="xl" weight="bold" color="#e53e3e"}
                    ${product.price}
                {/ikb_text}
                
                {ikb_text}
                    {product.description}
                {/ikb_text}
                
                {if condition="product.inStock"}
                    {ikb_text color="#38a169" weight="bold"}
                        In Stock
                    {/ikb_text}
                {/if}
            {/ikb_block}
        {/ikb_block}
        
        {!-- Related Products --}
        {ikb_text size="xl" weight="bold"}
            Related Products
        {/ikb_text}
        
        {ikb_query type="product" limit=4 category="{product.category}"}
            {ikb_block cols=4 gap=2}
                {ikb_card 
                    title="{item.title}"
                    image="{item.thumbnail}"
                    link="{item.url}"
                    variant="elevated"
                />
            {/ikb_block}
        {/ikb_query}
    {/ikb_container}
{/ikb_section}
```

### 14. Portfolio Gallery

```disyl
{ikb_section type="content"}
    {ikb_container width="xl"}
        {ikb_text size="2xl" weight="bold" align="center"}
            Our Portfolio
        {/ikb_text}
        
        {ikb_text size="lg" align="center" color="#666"}
            Check out our latest work
        {/ikb_text}
        
        {ikb_query type="portfolio" limit=9 orderby="date"}
            {ikb_block cols=3 gap=2}
                {ikb_card 
                    image="{item.thumbnail}"
                    link="{item.url}"
                    variant="elevated"
                }
                    {ikb_text weight="bold"}
                        {item.title}
                    {/ikb_text}
                    {ikb_text size="sm" color="#666"}
                        {item.category}
                    {/ikb_text}
                {/ikb_card}
            {/ikb_block}
        {/ikb_query}
    {/ikb_container}
{/ikb_section}
```

### 15. Team Members Grid

```disyl
{ikb_section type="content" bg="#f7fafc"}
    {ikb_container width="lg"}
        {ikb_text size="2xl" weight="bold" align="center"}
            Meet Our Team
        {/ikb_text}
        
        {for items="team" as="member"}
            {ikb_block cols=4 gap=3}
                {ikb_card variant="elevated"}
                    {ikb_image 
                        src="{member.photo}"
                        alt="{member.name}"
                        width=200
                        height=200
                    />
                    {ikb_text weight="bold" align="center"}
                        {member.name}
                    {/ikb_text}
                    {ikb_text size="sm" color="#666" align="center"}
                        {member.role}
                    {/ikb_text}
                {/ikb_card}
            {/ikb_block}
        {/for}
    {/ikb_container}
{/ikb_section}
```

---

## Real-World Examples

### 16. Complete Homepage

```disyl
{!-- Hero --}
{ikb_section type="hero" bg="#667eea" padding="large"}
    {ikb_container width="xl"}
        {ikb_text size="2xl" weight="bold" align="center" color="#fff"}
            Welcome to TechCorp
        {/ikb_text}
        {ikb_text size="lg" align="center" color="#f0f0f0"}
            Innovation in Technology
        {/ikb_text}
    {/ikb_container}
{/ikb_section}

{!-- Services --}
{ikb_section type="content"}
    {ikb_container width="lg"}
        {ikb_text size="xl" weight="bold" align="center"}
            Our Services
        {/ikb_text}
        {ikb_block cols=3 gap=2}
            {ikb_card title="Web Development" variant="elevated"}
                {ikb_text}Custom web applications{/ikb_text}
            {/ikb_card}
            {ikb_card title="Mobile Apps" variant="elevated"}
                {ikb_text}iOS and Android development{/ikb_text}
            {/ikb_card}
            {ikb_card title="Cloud Solutions" variant="elevated"}
                {ikb_text}Scalable infrastructure{/ikb_text}
            {/ikb_card}
        {/ikb_block}
    {/ikb_container}
{/ikb_section}

{!-- Latest News --}
{ikb_section type="content" bg="#f7fafc"}
    {ikb_container width="lg"}
        {ikb_text size="xl" weight="bold" align="center"}
            Latest News
        {/ikb_text}
        {ikb_query type="post" limit=3 orderby="date"}
            {ikb_block cols=3 gap=2}
                {ikb_card 
                    title="{item.title}"
                    image="{item.thumbnail}"
                    link="{item.url}"
                    variant="elevated"
                }
                    {ikb_text size="sm"}{item.excerpt}{/ikb_text}
                {/ikb_card}
            {/ikb_block}
        {/ikb_query}
    {/ikb_container}
{/ikb_section}

{!-- Footer --}
{ikb_section type="footer" bg="#2d3748" padding="normal"}
    {ikb_container width="lg"}
        {ikb_block cols=3 gap=3}
            {ikb_block cols=1}
                {ikb_text color="#fff" weight="bold"}Company{/ikb_text}
                {ikb_text size="sm" color="#cbd5e0"}About Us{/ikb_text}
                {ikb_text size="sm" color="#cbd5e0"}Careers{/ikb_text}
            {/ikb_block}
            {ikb_block cols=1}
                {ikb_text color="#fff" weight="bold"}Support{/ikb_text}
                {ikb_text size="sm" color="#cbd5e0"}Help Center{/ikb_text}
                {ikb_text size="sm" color="#cbd5e0"}Contact{/ikb_text}
            {/ikb_block}
            {ikb_block cols=1}
                {ikb_text color="#fff" weight="bold"}Legal{/ikb_text}
                {ikb_text size="sm" color="#cbd5e0"}Privacy{/ikb_text}
                {ikb_text size="sm" color="#cbd5e0"}Terms{/ikb_text}
            {/ikb_block}
        {/ikb_block}
        {ikb_text size="sm" color="#cbd5e0" align="center"}
            © 2025 TechCorp. All rights reserved.
        {/ikb_text}
    {/ikb_container}
{/ikb_section}
```

### 17. Blog Single Post

```disyl
{ikb_section type="content"}
    {ikb_container width="md"}
        {ikb_query type="post" limit=1}
            {!-- Post Header --}
            {ikb_text size="2xl" weight="bold"}
                {item.title}
            {/ikb_text}
            
            {ikb_text size="sm" color="#666"}
                Published on {item.date} by {item.author}
            {/ikb_text}
            
            {!-- Featured Image --}
            {if condition="item.thumbnail"}
                {ikb_image 
                    src="{item.thumbnail}"
                    alt="{item.title}"
                    responsive=true
                />
            {/if}
            
            {!-- Post Content --}
            {ikb_text}
                {item.content}
            {/ikb_text}
            
            {!-- Categories --}
            {if condition="item.categories"}
                {ikb_text size="sm" weight="bold"}
                    Categories: {item.categories}
                {/ikb_text}
            {/if}
        {/ikb_query}
        
        {!-- Related Posts --}
        {ikb_text size="xl" weight="bold"}
            Related Posts
        {/ikb_text}
        
        {ikb_query type="post" limit=3 orderby="random"}
            {ikb_block cols=3 gap=2}
                {ikb_card 
                    title="{item.title}"
                    image="{item.thumbnail}"
                    link="{item.url}"
                    variant="outlined"
                />
            {/ikb_block}
        {/ikb_query}
    {/ikb_container}
{/ikb_section}
```

### 18. Pricing Page

```disyl
{ikb_section type="content"}
    {ikb_container width="lg"}
        {ikb_text size="2xl" weight="bold" align="center"}
            Choose Your Plan
        {/ikb_text}
        
        {ikb_block cols=3 gap=3}
            {!-- Basic Plan --}
            {ikb_card title="Basic" variant="outlined"}
                {ikb_text size="2xl" weight="bold" align="center"}
                    $9/mo
                {/ikb_text}
                {ikb_text align="center"}10 Projects{/ikb_text}
                {ikb_text align="center"}5GB Storage{/ikb_text}
                {ikb_text align="center"}Email Support{/ikb_text}
            {/ikb_card}
            
            {!-- Pro Plan --}
            {ikb_card title="Pro" variant="elevated"}
                {ikb_text size="2xl" weight="bold" align="center" color="#667eea"}
                    $29/mo
                {/ikb_text}
                {ikb_text align="center"}Unlimited Projects{/ikb_text}
                {ikb_text align="center"}50GB Storage{/ikb_text}
                {ikb_text align="center"}Priority Support{/ikb_text}
            {/ikb_card}
            
            {!-- Enterprise Plan --}
            {ikb_card title="Enterprise" variant="outlined"}
                {ikb_text size="2xl" weight="bold" align="center"}
                    $99/mo
                {/ikb_text}
                {ikb_text align="center"}Unlimited Everything{/ikb_text}
                {ikb_text align="center"}500GB Storage{/ikb_text}
                {ikb_text align="center"}24/7 Support{/ikb_text}
            {/ikb_card}
        {/ikb_block}
    {/ikb_container}
{/ikb_section}
```

### 19. Contact Page

```disyl
{ikb_section type="content"}
    {ikb_container width="md"}
        {ikb_text size="2xl" weight="bold" align="center"}
            Get In Touch
        {/ikb_text}
        
        {ikb_text align="center" color="#666"}
            We'd love to hear from you
        {/ikb_text}
        
        {ikb_block cols=2 gap=3}
            {ikb_card title="Email" variant="elevated"}
                {ikb_text}contact@company.com{/ikb_text}
            {/ikb_card}
            
            {ikb_card title="Phone" variant="elevated"}
                {ikb_text}+1 (555) 123-4567{/ikb_text}
            {/ikb_card}
        {/ikb_block}
        
        {ikb_card title="Office" variant="outlined"}
            {ikb_text}123 Main Street{/ikb_text}
            {ikb_text}San Francisco, CA 94102{/ikb_text}
        {/ikb_card}
    {/ikb_container}
{/ikb_section}
```

### 20. FAQ Page

```disyl
{ikb_section type="content"}
    {ikb_container width="md"}
        {ikb_text size="2xl" weight="bold" align="center"}
            Frequently Asked Questions
        {/ikb_text}
        
        {for items="faqs" as="faq"}
            {ikb_card variant="outlined"}
                {ikb_text weight="bold"}
                    {faq.question}
                {/ikb_text}
                {ikb_text color="#666"}
                    {faq.answer}
                {/ikb_text}
            {/ikb_card}
        {/for}
    {/ikb_container}
{/ikb_section}
```

---

## Tips for Writing DiSyL

1. **Start Simple**: Begin with basic components and add complexity
2. **Use Comments**: Document your template structure
3. **Leverage Defaults**: Don't specify every attribute
4. **Test Incrementally**: Build and test section by section
5. **Reuse Patterns**: Create templates for common layouts
6. **Validate Early**: Check for errors during development

---

## See Also

- [Language Reference](DISYL_LANGUAGE_REFERENCE.md)
- [Component Catalog](DISYL_COMPONENT_CATALOG.md)
- [WordPress Integration](DISYL_WORDPRESS_THEME_EXAMPLE.md)
