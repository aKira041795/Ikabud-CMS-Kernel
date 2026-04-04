/**
 * Ikabud Page Builder - Pre-built Section Templates
 * Ready-to-use sections for quick page building
 * 
 * All templates include default width/height settings to ensure they always work
 * regardless of container context.
 */

import { DiSyLNode, createNode, TEMPLATE_DEFAULTS, DEFAULT_MOBILE_COLLAPSE } from './types';

// =============================================================================
// Template Categories
// =============================================================================

export type TemplateCategory = 'hero' | 'features' | 'content' | 'entity' | 'cta' | 'testimonials' | 'pricing' | 'contact' | 'footer';

export interface SectionTemplate {
  id: string;
  name: string;
  category: TemplateCategory;
  description: string;
  thumbnail?: string;
  createNode: () => DiSyLNode;
}

// =============================================================================
// Template Style Helpers (ensure templates always work)
// =============================================================================

/**
 * Create a section with guaranteed dimensions and default alignment
 */
function createSection(props: Record<string, unknown>, style: Record<string, unknown>, children: DiSyLNode[]): DiSyLNode {
  return createNode('section', props, {
    width: TEMPLATE_DEFAULTS.section.width,
    minHeight: TEMPLATE_DEFAULTS.section.minHeight,
    padding: TEMPLATE_DEFAULTS.section.padding,
    display: 'flex',
    flexDirection: 'column',
    alignItems: 'center',
    justifyContent: 'center',
    ...style,
  }, children);
}

/**
 * Create a container with guaranteed dimensions and default alignment
 */
function createContainer(props: Record<string, unknown>, style: Record<string, unknown>, children: DiSyLNode[]): DiSyLNode {
  return createNode('container', props, {
    width: TEMPLATE_DEFAULTS.container.width,
    maxWidth: TEMPLATE_DEFAULTS.container.maxWidth,
    minHeight: TEMPLATE_DEFAULTS.container.minHeight,
    padding: TEMPLATE_DEFAULTS.container.padding,
    display: 'flex',
    flexDirection: 'row',
    gap: '24px',
    flexWrap: 'wrap',
    justifyContent: 'center',
    alignItems: 'center',
    margin: '0 auto',
    ...style,
  }, children);
}

/**
 * Create a flex row that collapses on mobile
 */
function createFlexRow(props: Record<string, unknown>, style: Record<string, unknown>, children: DiSyLNode[]): DiSyLNode {
  return createNode('container', props, {
    width: '100%',
    display: 'flex',
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: '24px',
    justifyContent: 'center',
    alignItems: 'center',
    mobile: { ...DEFAULT_MOBILE_COLLAPSE },
    ...style,
  }, children);
}

/**
 * Create a flex column (child of flex row)
 */
function createFlexColumn(props: Record<string, unknown>, style: Record<string, unknown>, children: DiSyLNode[]): DiSyLNode {
  return createNode('container', props, {
    flex: '1 1 0',
    minWidth: '280px', // Ensures reasonable mobile width
    display: 'flex',
    flexDirection: 'column',
    gap: '16px',
    alignItems: 'center',
    justifyContent: 'center',
    mobile: { flex: '1 1 100%' },
    ...style,
  }, children);
}

/**
 * Create an image with guaranteed dimensions
 */
function createImage(props: Record<string, unknown>, style: Record<string, unknown>): DiSyLNode {
  return createNode('image', props, {
    width: TEMPLATE_DEFAULTS.image.width,
    height: TEMPLATE_DEFAULTS.image.height,
    minHeight: TEMPLATE_DEFAULTS.image.minHeight,
    objectFit: TEMPLATE_DEFAULTS.image.objectFit,
    ...style,
  });
}

/**
 * Create a button with guaranteed dimensions
 */
function createButton(props: Record<string, unknown>, style: Record<string, unknown>): DiSyLNode {
  return createNode('button', props, {
    minWidth: TEMPLATE_DEFAULTS.button.minWidth,
    padding: TEMPLATE_DEFAULTS.button.padding,
    ...style,
  });
}

function createBadgeRow(labels: string[], options: { backgroundColor?: string; color?: string } = {}): DiSyLNode {
  const backgroundColor = options.backgroundColor || '#e0f2fe';
  const color = options.color || '#0369a1';

  return createNode('container', {}, {
    display: 'flex',
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: '10px',
    width: '100%',
  }, labels.map((label) => createNode('text', { content: label }, {
    fontSize: '12px',
    fontWeight: '700',
    color,
    backgroundColor,
    borderRadius: '999px',
    padding: '7px 12px',
    lineHeight: '1.2',
  })));
}

function createEntityTemplateSection(options: {
  eyebrow: string;
  heading: string;
  description: string;
  badges: string[];
  widget: DiSyLNode;
  backgroundColor?: string;
  accentColor?: string;
  badgeBackgroundColor?: string;
  surfaceColor?: string;
  surfaceBorderColor?: string;
}): DiSyLNode {
  const accentColor = options.accentColor || '#0369a1';
  const surfaceColor = options.surfaceColor || '#ffffff';
  const surfaceBorderColor = options.surfaceBorderColor || 'rgba(15, 23, 42, 0.08)';

  return createSection({}, {
    padding: '80px 24px',
    backgroundColor: options.backgroundColor || '#f8fafc',
  }, [
    createContainer({}, {
      maxWidth: '1200px',
      display: 'flex',
      flexDirection: 'column',
      alignItems: 'stretch',
      gap: '28px',
    }, [
      createNode('container', {}, {
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'flex-start',
        gap: '16px',
        width: '100%',
      }, [
        createNode('text', { content: options.eyebrow }, {
          fontSize: '12px',
          fontWeight: '700',
          letterSpacing: '0.08em',
          textTransform: 'uppercase',
          color: accentColor,
        }),
        createNode('heading', { content: options.heading, level: 2 }, {
          fontSize: '36px',
          fontWeight: '700',
          color: '#0f172a',
          lineHeight: '1.2',
          textAlign: 'left',
          width: '100%',
          mobile: { fontSize: '28px' },
        }),
        createNode('text', { content: options.description }, {
          fontSize: '17px',
          color: '#475569',
          lineHeight: '1.7',
          maxWidth: '820px',
        }),
        createBadgeRow(options.badges, {
          backgroundColor: options.badgeBackgroundColor || '#e0f2fe',
          color: accentColor,
        }),
      ]),
      createNode('container', {}, {
        width: '100%',
        padding: '24px',
        backgroundColor: surfaceColor,
        border: `1px solid ${surfaceBorderColor}`,
        borderRadius: '24px',
        boxShadow: '0 16px 40px rgba(15, 23, 42, 0.08)',
      }, [options.widget]),
    ]),
  ]);
}

// =============================================================================
// Hero Sections (using helper functions for guaranteed dimensions)
// =============================================================================

const heroSimple: SectionTemplate = {
  id: 'hero-simple',
  name: 'Simple Hero',
  category: 'hero',
  description: 'Clean hero with heading, text, and CTA button',
  createNode: () => createSection({}, {
    padding: '80px 24px',
    backgroundColor: '#f8fafc',
    display: 'flex',
    justifyContent: 'center',
  }, [
    createContainer({}, {
      maxWidth: '800px',
      display: 'flex',
      flexDirection: 'column',
      alignItems: 'center',
      gap: '24px',
    }, [
      createNode('heading', { content: 'Build Something Amazing', level: 1 }, {
        fontSize: '48px',
        fontWeight: '700',
        textAlign: 'center',
        color: '#0f172a',
        lineHeight: '1.2',
        mobile: { fontSize: '32px' },
      }),
      createNode('text', { content: 'Create beautiful, responsive websites with our intuitive page builder. No coding required.' }, {
        fontSize: '18px',
        textAlign: 'center',
        color: '#64748b',
        maxWidth: '600px',
        lineHeight: '1.6',
        mobile: { fontSize: '16px' },
      }),
      createButton({ content: 'Get Started', variant: 'primary', href: '#' }, {
        padding: '16px 32px',
        fontSize: '16px',
      }),
    ]),
  ]),
};

const heroWithImage: SectionTemplate = {
  id: 'hero-with-image',
  name: 'Hero with Image',
  category: 'hero',
  description: 'Two-column hero with text and image',
  createNode: () => createSection({}, {
    padding: '80px 24px',
    backgroundColor: '#ffffff',
  }, [
    createFlexRow({}, {
      maxWidth: '1200px',
      margin: '0 auto',
      alignItems: 'center',
      gap: '48px',
    }, [
      createFlexColumn({}, { gap: '24px' }, [
        createNode('heading', { content: 'Transform Your Business', level: 1 }, {
          fontSize: '42px',
          fontWeight: '700',
          color: '#0f172a',
          lineHeight: '1.2',
          mobile: { fontSize: '32px' },
        }),
        createNode('text', { content: 'Empower your team with tools that drive growth and innovation. Our platform helps you achieve more with less effort.' }, {
          fontSize: '18px',
          color: '#64748b',
          lineHeight: '1.6',
        }),
        createFlexRow({}, { gap: '12px', flexWrap: 'wrap' }, [
          createButton({ content: 'Start Free Trial', variant: 'primary' }, { padding: '14px 28px' }),
          createButton({ content: 'Learn More', variant: 'outline' }, { padding: '14px 28px' }),
        ]),
      ]),
      createFlexColumn({}, {}, [
        createImage({ src: '', alt: 'Hero image' }, {
          minHeight: '400px',
          backgroundColor: '#e2e8f0',
          borderRadius: '8px',
        }),
      ]),
    ]),
  ]),
};

const heroCentered: SectionTemplate = {
  id: 'hero-centered',
  name: 'Centered Hero',
  category: 'hero',
  description: 'Full-width centered hero with background',
  createNode: () => createSection({}, {
    padding: '120px 24px',
    backgroundColor: '#0f172a',
    display: 'flex',
    justifyContent: 'center',
    mobile: { padding: '60px 24px' },
  }, [
    createContainer({}, {
      maxWidth: '900px',
      display: 'flex',
      flexDirection: 'column',
      alignItems: 'center',
      gap: '32px',
    }, [
      createNode('heading', { content: 'The Future of Web Design', level: 1 }, {
        fontSize: '56px',
        fontWeight: '800',
        textAlign: 'center',
        color: '#ffffff',
        lineHeight: '1.1',
        mobile: { fontSize: '36px' },
      }),
      createNode('text', { content: 'Join thousands of creators building stunning websites without writing a single line of code.' }, {
        fontSize: '20px',
        textAlign: 'center',
        color: '#94a3b8',
        maxWidth: '700px',
        lineHeight: '1.6',
        mobile: { fontSize: '16px' },
      }),
      createFlexRow({}, { gap: '16px', justifyContent: 'center', flexWrap: 'wrap' }, [
        createButton({ content: 'Get Started Free', variant: 'primary' }, {
          padding: '16px 32px',
          fontSize: '16px',
          backgroundColor: '#3b82f6',
        }),
        createButton({ content: 'Watch Demo', variant: 'ghost' }, {
          padding: '16px 32px',
          fontSize: '16px',
          color: '#ffffff',
        }),
      ]),
    ]),
  ]),
};

// =============================================================================
// Feature Sections
// =============================================================================

const featuresThreeColumn: SectionTemplate = {
  id: 'features-three-column',
  name: '3-Column Features',
  category: 'features',
  description: 'Three feature cards in a row',
  createNode: () => createNode('section', {}, {
    padding: '80px 24px',
    backgroundColor: '#ffffff',
  }, [
    createNode('container', {}, {
      maxWidth: '1200px',
      display: 'flex',
      flexDirection: 'column',
      gap: '48px',
    }, [
      createNode('row', {}, { display: 'flex' }, [
        createNode('column', {}, { display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '16px' }, [
          createNode('heading', { content: 'Why Choose Us', level: 2 }, {
            fontSize: '36px',
            fontWeight: '700',
            textAlign: 'center',
            color: '#0f172a',
          }),
          createNode('text', { content: 'Everything you need to build amazing websites' }, {
            fontSize: '18px',
            textAlign: 'center',
            color: '#64748b',
          }),
        ]),
      ]),
      createNode('row', {}, { display: 'flex', gap: '32px' }, [
        createNode('column', {}, { flex: '1', minWidth: '280px', padding: '32px', backgroundColor: '#f8fafc', display: 'flex', flexDirection: 'column', gap: '16px' }, [
          createNode('heading', { content: 'Easy to Use', level: 3 }, { fontSize: '20px', fontWeight: '600', color: '#0f172a' }),
          createNode('text', { content: 'Intuitive drag-and-drop interface that anyone can master in minutes.' }, { fontSize: '16px', color: '#64748b', lineHeight: '1.6' }),
        ]),
        createNode('column', {}, { flex: '1', minWidth: '280px', padding: '32px', backgroundColor: '#f8fafc', display: 'flex', flexDirection: 'column', gap: '16px' }, [
          createNode('heading', { content: 'Fully Responsive', level: 3 }, { fontSize: '20px', fontWeight: '600', color: '#0f172a' }),
          createNode('text', { content: 'Your designs look perfect on every device, from desktop to mobile.' }, { fontSize: '16px', color: '#64748b', lineHeight: '1.6' }),
        ]),
        createNode('column', {}, { flex: '1', minWidth: '280px', padding: '32px', backgroundColor: '#f8fafc', display: 'flex', flexDirection: 'column', gap: '16px' }, [
          createNode('heading', { content: 'Lightning Fast', level: 3 }, { fontSize: '20px', fontWeight: '600', color: '#0f172a' }),
          createNode('text', { content: 'Optimized code ensures your pages load instantly for better SEO.' }, { fontSize: '16px', color: '#64748b', lineHeight: '1.6' }),
        ]),
      ]),
    ]),
  ]),
};

const featuresWithIcons: SectionTemplate = {
  id: 'features-with-icons',
  name: 'Features Grid',
  category: 'features',
  description: 'Four feature items in a grid',
  createNode: () => createNode('section', {}, {
    padding: '80px 24px',
    backgroundColor: '#f8fafc',
  }, [
    createNode('container', {}, {
      maxWidth: '1000px',
    }, [
      createNode('row', {}, { display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: '40px' }, [
        createNode('column', {}, { display: 'flex', flexDirection: 'column', gap: '12px' }, [
          createNode('heading', { content: '🚀 Quick Setup', level: 3 }, { fontSize: '18px', fontWeight: '600', color: '#0f172a' }),
          createNode('text', { content: 'Get started in seconds with our streamlined onboarding process.' }, { fontSize: '15px', color: '#64748b', lineHeight: '1.6' }),
        ]),
        createNode('column', {}, { display: 'flex', flexDirection: 'column', gap: '12px' }, [
          createNode('heading', { content: '🎨 Beautiful Design', level: 3 }, { fontSize: '18px', fontWeight: '600', color: '#0f172a' }),
          createNode('text', { content: 'Professional templates designed by world-class designers.' }, { fontSize: '15px', color: '#64748b', lineHeight: '1.6' }),
        ]),
        createNode('column', {}, { display: 'flex', flexDirection: 'column', gap: '12px' }, [
          createNode('heading', { content: '🔒 Secure & Reliable', level: 3 }, { fontSize: '18px', fontWeight: '600', color: '#0f172a' }),
          createNode('text', { content: 'Enterprise-grade security to keep your data safe.' }, { fontSize: '15px', color: '#64748b', lineHeight: '1.6' }),
        ]),
        createNode('column', {}, { display: 'flex', flexDirection: 'column', gap: '12px' }, [
          createNode('heading', { content: '💬 24/7 Support', level: 3 }, { fontSize: '18px', fontWeight: '600', color: '#0f172a' }),
          createNode('text', { content: 'Our team is always here to help you succeed.' }, { fontSize: '15px', color: '#64748b', lineHeight: '1.6' }),
        ]),
      ]),
    ]),
  ]),
};

// =============================================================================
// Content Sections
// =============================================================================

const contentTwoColumn: SectionTemplate = {
  id: 'content-two-column',
  name: 'Two Column Content',
  category: 'content',
  description: 'Image and text side by side',
  createNode: () => createNode('section', {}, {
    padding: '80px 24px',
    backgroundColor: '#ffffff',
  }, [
    createNode('container', {}, {
      maxWidth: '1200px',
    }, [
      createNode('row', {}, { display: 'flex', flexDirection: 'row', alignItems: 'center', gap: '64px' }, [
        createNode('column', {}, { flex: '1' }, [
          createNode('image', { src: '', alt: 'Content image' }, {
            width: '100%',
            minHeight: '350px',
            backgroundColor: '#e2e8f0',
          }),
        ]),
        createNode('column', {}, { flex: '1', display: 'flex', flexDirection: 'column', gap: '20px' }, [
          createNode('heading', { content: 'Designed for Growth', level: 2 }, {
            fontSize: '32px',
            fontWeight: '700',
            color: '#0f172a',
            lineHeight: '1.2',
          }),
          createNode('text', { content: 'Our platform scales with your business. Start small and grow without limits. We handle the technical complexity so you can focus on what matters most.' }, {
            fontSize: '16px',
            color: '#64748b',
            lineHeight: '1.7',
          }),
          createNode('button', { content: 'Learn More', variant: 'outline' }, { padding: '12px 24px' }),
        ]),
      ]),
    ]),
  ]),
};

// =============================================================================
// CTA Sections
// =============================================================================

const ctaSimple: SectionTemplate = {
  id: 'cta-simple',
  name: 'Simple CTA',
  category: 'cta',
  description: 'Centered call-to-action section',
  createNode: () => createNode('section', {}, {
    padding: '80px 24px',
    backgroundColor: '#3b82f6',
    display: 'flex',
    justifyContent: 'center',
  }, [
    createNode('container', {}, {
      maxWidth: '700px',
      display: 'flex',
      flexDirection: 'column',
      alignItems: 'center',
      gap: '24px',
    }, [
      createNode('heading', { content: 'Ready to Get Started?', level: 2 }, {
        fontSize: '36px',
        fontWeight: '700',
        textAlign: 'center',
        color: '#ffffff',
      }),
      createNode('text', { content: 'Join thousands of satisfied customers and transform your workflow today.' }, {
        fontSize: '18px',
        textAlign: 'center',
        color: '#dbeafe',
        lineHeight: '1.6',
      }),
      createNode('row', {}, { display: 'flex', gap: '12px' }, [
        createNode('button', { content: 'Start Free Trial', variant: 'primary' }, {
          padding: '14px 28px',
          backgroundColor: '#ffffff',
          color: '#3b82f6',
        }),
        createNode('button', { content: 'Contact Sales', variant: 'outline' }, {
          padding: '14px 28px',
          borderColor: '#ffffff',
          color: '#ffffff',
        }),
      ]),
    ]),
  ]),
};

const ctaBanner: SectionTemplate = {
  id: 'cta-banner',
  name: 'CTA Banner',
  category: 'cta',
  description: 'Horizontal banner with CTA',
  createNode: () => createNode('section', {}, {
    padding: '40px 24px',
    backgroundColor: '#0f172a',
  }, [
    createNode('container', {}, {
      maxWidth: '1200px',
    }, [
      createNode('row', {}, { display: 'flex', flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: '24px' }, [
        createNode('column', {}, { display: 'flex', flexDirection: 'column', gap: '8px' }, [
          createNode('heading', { content: 'Start building today', level: 3 }, {
            fontSize: '24px',
            fontWeight: '600',
            color: '#ffffff',
          }),
          createNode('text', { content: 'No credit card required. Free 14-day trial.' }, {
            fontSize: '16px',
            color: '#94a3b8',
          }),
        ]),
        createNode('column', {}, {}, [
          createNode('button', { content: 'Get Started', variant: 'primary' }, {
            padding: '14px 32px',
            backgroundColor: '#3b82f6',
          }),
        ]),
      ]),
    ]),
  ]),
};

// =============================================================================
// Testimonials
// =============================================================================

const testimonialCards: SectionTemplate = {
  id: 'testimonial-cards',
  name: 'Testimonial Cards',
  category: 'testimonials',
  description: 'Three testimonial cards',
  createNode: () => createNode('section', {}, {
    padding: '80px 24px',
    backgroundColor: '#f8fafc',
  }, [
    createNode('container', {}, {
      maxWidth: '1200px',
      display: 'flex',
      flexDirection: 'column',
      gap: '48px',
    }, [
      createNode('heading', { content: 'What Our Customers Say', level: 2 }, {
        fontSize: '36px',
        fontWeight: '700',
        textAlign: 'center',
        color: '#0f172a',
      }),
      createNode('row', {}, { display: 'flex', gap: '24px' }, [
        createNode('column', {}, { flex: '1', minWidth: '300px', padding: '32px', backgroundColor: '#ffffff', display: 'flex', flexDirection: 'column', gap: '16px', boxShadow: '0 1px 3px rgba(0,0,0,0.1)' }, [
          createNode('text', { content: '"This tool has completely transformed how we build websites. Incredible time savings!"' }, { fontSize: '16px', color: '#475569', lineHeight: '1.6', fontStyle: 'italic' }),
          createNode('text', { content: '— Sarah Johnson, CEO' }, { fontSize: '14px', color: '#94a3b8', fontWeight: '500' }),
        ]),
        createNode('column', {}, { flex: '1', minWidth: '300px', padding: '32px', backgroundColor: '#ffffff', display: 'flex', flexDirection: 'column', gap: '16px', boxShadow: '0 1px 3px rgba(0,0,0,0.1)' }, [
          createNode('text', { content: '"The best page builder I have ever used. Simple yet powerful."' }, { fontSize: '16px', color: '#475569', lineHeight: '1.6', fontStyle: 'italic' }),
          createNode('text', { content: '— Mike Chen, Designer' }, { fontSize: '14px', color: '#94a3b8', fontWeight: '500' }),
        ]),
        createNode('column', {}, { flex: '1', minWidth: '300px', padding: '32px', backgroundColor: '#ffffff', display: 'flex', flexDirection: 'column', gap: '16px', boxShadow: '0 1px 3px rgba(0,0,0,0.1)' }, [
          createNode('text', { content: '"Finally, a builder that lets me focus on design, not code."' }, { fontSize: '16px', color: '#475569', lineHeight: '1.6', fontStyle: 'italic' }),
          createNode('text', { content: '— Emily Davis, Freelancer' }, { fontSize: '14px', color: '#94a3b8', fontWeight: '500' }),
        ]),
      ]),
    ]),
  ]),
};

// =============================================================================
// Pricing
// =============================================================================

const pricingThreeColumn: SectionTemplate = {
  id: 'pricing-three-column',
  name: 'Pricing Table',
  category: 'pricing',
  description: 'Three pricing tiers',
  createNode: () => createNode('section', {}, {
    padding: '80px 24px',
    backgroundColor: '#ffffff',
  }, [
    createNode('container', {}, {
      maxWidth: '1100px',
      display: 'flex',
      flexDirection: 'column',
      gap: '48px',
    }, [
      createNode('row', {}, { display: 'flex' }, [
        createNode('column', {}, { display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '16px' }, [
          createNode('heading', { content: 'Simple Pricing', level: 2 }, {
            fontSize: '36px',
            fontWeight: '700',
            textAlign: 'center',
            color: '#0f172a',
          }),
          createNode('text', { content: 'Choose the plan that works for you' }, {
            fontSize: '18px',
            textAlign: 'center',
            color: '#64748b',
          }),
        ]),
      ]),
      createNode('row', {}, { display: 'flex', gap: '24px', alignItems: 'stretch' }, [
        // Starter
        createNode('column', {}, { flex: '1', minWidth: '280px', padding: '32px', border: '1px solid #e2e8f0', display: 'flex', flexDirection: 'column', gap: '24px' }, [
          createNode('heading', { content: 'Starter', level: 3 }, { fontSize: '20px', fontWeight: '600', color: '#0f172a' }),
          createNode('heading', { content: '$9/mo', level: 4 }, { fontSize: '36px', fontWeight: '700', color: '#0f172a' }),
          createNode('text', { content: '• 5 Projects\n• Basic Templates\n• Email Support' }, { fontSize: '15px', color: '#64748b', lineHeight: '2' }),
          createNode('button', { content: 'Get Started', variant: 'outline' }, { padding: '12px 24px', marginTop: 'auto' }),
        ]),
        // Pro (highlighted)
        createNode('column', {}, { flex: '1', minWidth: '280px', padding: '32px', backgroundColor: '#0f172a', display: 'flex', flexDirection: 'column', gap: '24px' }, [
          createNode('heading', { content: 'Pro', level: 3 }, { fontSize: '20px', fontWeight: '600', color: '#ffffff' }),
          createNode('heading', { content: '$29/mo', level: 4 }, { fontSize: '36px', fontWeight: '700', color: '#ffffff' }),
          createNode('text', { content: '• Unlimited Projects\n• All Templates\n• Priority Support\n• Custom Domain' }, { fontSize: '15px', color: '#94a3b8', lineHeight: '2' }),
          createNode('button', { content: 'Get Started', variant: 'primary' }, { padding: '12px 24px', marginTop: 'auto', backgroundColor: '#3b82f6' }),
        ]),
        // Enterprise
        createNode('column', {}, { flex: '1', minWidth: '280px', padding: '32px', border: '1px solid #e2e8f0', display: 'flex', flexDirection: 'column', gap: '24px' }, [
          createNode('heading', { content: 'Enterprise', level: 3 }, { fontSize: '20px', fontWeight: '600', color: '#0f172a' }),
          createNode('heading', { content: 'Custom', level: 4 }, { fontSize: '36px', fontWeight: '700', color: '#0f172a' }),
          createNode('text', { content: '• Everything in Pro\n• Dedicated Support\n• SLA\n• Custom Integrations' }, { fontSize: '15px', color: '#64748b', lineHeight: '2' }),
          createNode('button', { content: 'Contact Us', variant: 'outline' }, { padding: '12px 24px', marginTop: 'auto' }),
        ]),
      ]),
    ]),
  ]),
};

// =============================================================================
// Contact
// =============================================================================

const contactSimple: SectionTemplate = {
  id: 'contact-simple',
  name: 'Contact Section',
  category: 'contact',
  description: 'Two-column contact section with info and a contact form',
  createNode: () => createSection({}, {
    padding: '80px 24px',
    backgroundColor: '#f8fafc',
  }, [
    createContainer({}, {
      maxWidth: '1100px',
      display: 'flex',
      flexDirection: 'column',
      gap: '48px',
    }, [
      createNode('heading', { content: 'Get in Touch', level: 2 }, {
        fontSize: '36px',
        fontWeight: '700',
        textAlign: 'center',
        color: '#0f172a',
      }),
      createFlexRow({}, { gap: '64px', alignItems: 'flex-start' }, [
        // Left: contact details
        createFlexColumn({}, { flex: '1 1 300px', gap: '24px' }, [
          createNode('text', { content: 'Have questions? We\'d love to hear from you. Send us a message and we\'ll respond as soon as possible.' }, {
            fontSize: '16px',
            color: '#64748b',
            lineHeight: '1.7',
          }),
          createNode('container', {}, { display: 'flex', flexDirection: 'column', gap: '16px', marginTop: '8px' }, [
            createNode('text', { content: '📧  hello@example.com' }, { fontSize: '15px', color: '#0f172a' }),
            createNode('text', { content: '📞  +1 (555) 123-4567' }, { fontSize: '15px', color: '#0f172a' }),
            createNode('text', { content: '📍  123 Main St, City, Country' }, { fontSize: '15px', color: '#0f172a' }),
          ]),
        ]),
        // Right: contact form
        createFlexColumn({}, {
          flex: '1 1 400px',
          backgroundColor: '#ffffff',
          borderRadius: '16px',
          padding: '32px',
          boxShadow: '0 4px 24px rgba(15,23,42,0.07)',
        }, [
          createNode('form', {
            submitText: 'Send Message',
            successMessage: 'Thank you! We\'ll get back to you shortly.',
            fields: [
              { id: 'name', label: 'Your Name', type: 'text', placeholder: 'Jane Smith', required: true },
              { id: 'email', label: 'Email Address', type: 'email', placeholder: 'jane@example.com', required: true },
              { id: 'subject', label: 'Subject', type: 'text', placeholder: 'How can we help?', required: false },
              { id: 'message', label: 'Message', type: 'textarea', placeholder: 'Tell us more...', required: true },
            ],
          }, { width: '100%' }),
        ]),
      ]),
    ]),
  ]),
};

// =============================================================================
// Footer
// =============================================================================

const footerSimple: SectionTemplate = {
  id: 'footer-simple',
  name: 'Simple Footer',
  category: 'footer',
  description: 'Footer with branding, navigation links, social icons, and copyright',
  createNode: () => createSection({}, {
    padding: '48px 24px 32px',
    backgroundColor: '#0f172a',
  }, [
    createContainer({}, {
      maxWidth: '1200px',
      display: 'flex',
      flexDirection: 'column',
      gap: '32px',
    }, [
      // Top row: brand + nav
      createFlexRow({}, { gap: '48px', alignItems: 'flex-start', flexWrap: 'wrap', justifyContent: 'space-between' }, [
        // Brand
        createFlexColumn({}, { flex: '0 1 260px', gap: '12px' }, [
          createNode('heading', { content: 'Your Brand', level: 3 }, { fontSize: '20px', fontWeight: '700', color: '#ffffff' }),
          createNode('text', { content: 'Building exceptional digital experiences for modern businesses worldwide.' }, { fontSize: '14px', color: '#94a3b8', lineHeight: '1.6', maxWidth: '240px' }),
          createNode('social_icons', {
            icons: [
              { platform: 'facebook', url: '#' },
              { platform: 'twitter', url: '#' },
              { platform: 'instagram', url: '#' },
              { platform: 'linkedin', url: '#' },
            ],
            size: '18',
            style: 'minimal',
          }, { marginTop: '8px' }),
        ]),
        // Nav columns
        createFlexRow({}, { gap: '48px', flexWrap: 'wrap' }, [
          createFlexColumn({}, { gap: '12px' }, [
            createNode('text', { content: 'Company' }, { fontSize: '13px', fontWeight: '600', color: '#ffffff', letterSpacing: '0.05em', marginBottom: '4px' }),
            createNode('text', { content: 'About' }, { fontSize: '14px', color: '#94a3b8' }),
            createNode('text', { content: 'Careers' }, { fontSize: '14px', color: '#94a3b8' }),
            createNode('text', { content: 'Blog' }, { fontSize: '14px', color: '#94a3b8' }),
          ]),
          createFlexColumn({}, { gap: '12px' }, [
            createNode('text', { content: 'Services' }, { fontSize: '13px', fontWeight: '600', color: '#ffffff', letterSpacing: '0.05em', marginBottom: '4px' }),
            createNode('text', { content: 'Web Design' }, { fontSize: '14px', color: '#94a3b8' }),
            createNode('text', { content: 'Development' }, { fontSize: '14px', color: '#94a3b8' }),
            createNode('text', { content: 'Marketing' }, { fontSize: '14px', color: '#94a3b8' }),
          ]),
          createFlexColumn({}, { gap: '12px' }, [
            createNode('text', { content: 'Support' }, { fontSize: '13px', fontWeight: '600', color: '#ffffff', letterSpacing: '0.05em', marginBottom: '4px' }),
            createNode('text', { content: 'Help Center' }, { fontSize: '14px', color: '#94a3b8' }),
            createNode('text', { content: 'Contact' }, { fontSize: '14px', color: '#94a3b8' }),
            createNode('text', { content: 'Privacy' }, { fontSize: '14px', color: '#94a3b8' }),
          ]),
        ]),
      ]),
      // Divider
      createNode('divider', {}, { width: '100%', borderColor: 'rgba(255,255,255,0.1)' }),
      // Bottom row: copyright + legal links
      createFlexRow({}, { justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '12px' }, [
        createNode('text', { content: '© 2025 Your Brand. All rights reserved.' }, { fontSize: '13px', color: '#64748b' }),
        createFlexRow({}, { gap: '24px' }, [
          createNode('text', { content: 'Privacy Policy' }, { fontSize: '13px', color: '#64748b' }),
          createNode('text', { content: 'Terms of Service' }, { fontSize: '13px', color: '#64748b' }),
          createNode('text', { content: 'Cookie Policy' }, { fontSize: '13px', color: '#64748b' }),
        ]),
      ]),
    ]),
  ]),
};

// =============================================================================
// Slideshow Templates
// =============================================================================

const slideshowHeroFullscreen: SectionTemplate = {
  id: 'slideshow-hero-fullscreen',
  name: 'Fullscreen Hero Slideshow',
  category: 'hero',
  description: 'Full-width hero slideshow with large images, overlay text, and CTA buttons',
  createNode: () => createNode('section', {}, {
    padding: '0',
    width: '100%',
  }, [
    createNode('slideshow', {
      slides: [
        {
          id: 'slide1',
          image: 'https://images.unsplash.com/photo-1497366216548-37526070297c?w=1920&h=800&fit=crop',
          title: 'Welcome to Our Platform',
          description: 'Build amazing digital experiences with our powerful, intuitive tools designed for modern businesses',
          ctaText: 'Get Started Free',
          link: '#signup'
        },
        {
          id: 'slide2',
          image: 'https://images.unsplash.com/photo-1522071820081-009f0129c71c?w=1920&h=800&fit=crop',
          title: 'Collaborate Seamlessly',
          description: 'Work together with your team in real-time. Share ideas, track progress, and achieve goals faster',
          ctaText: 'See How It Works',
          link: '#features'
        },
        {
          id: 'slide3',
          image: 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?w=1920&h=800&fit=crop',
          title: 'Grow Your Business',
          description: 'Scale with confidence using our enterprise-grade solutions trusted by thousands of companies worldwide',
          ctaText: 'View Success Stories',
          link: '#testimonials'
        },
      ],
      autoplay: true,
      interval: 6000,
      showArrows: true,
      showDots: true,
      animationStyle: 'fade',
      fullWidth: true,
      height: '100vh',
      captionTitleSize: '48px',
      captionDescSize: '20px',
      captionPosition: 'center',
      captionColor: '#ffffff',
      captionAlign: 'center',
      captionBg: 'rgba(0,0,0,0.4)',
    }, {
      minHeight: '600px',
    }),
  ]),
};

const slideshowProductShowcase: SectionTemplate = {
  id: 'slideshow-product-showcase',
  name: 'Product Showcase',
  category: 'hero',
  description: 'Elegant product slideshow with zoom animation and shop buttons',
  createNode: () => createNode('section', {}, {
    padding: '80px 24px',
    backgroundColor: '#ffffff',
  }, [
    createNode('container', {}, {
      maxWidth: '1400px',
    }, [
      createNode('heading', { content: 'Featured Products', level: 2 }, {
        fontSize: '42px',
        fontWeight: '700',
        textAlign: 'center',
        marginBottom: '48px',
        color: '#0f172a',
      }),
      createNode('slideshow', {
        slides: [
          {
            id: 'product1',
            image: 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=1400&h=600&fit=crop',
            title: 'Premium Headphones',
            description: 'Experience crystal clear sound with our award-winning noise-canceling technology. Starting at $299',
            ctaText: 'Shop Now',
            link: '#product-headphones'
          },
          {
            id: 'product2',
            image: 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=1400&h=600&fit=crop',
            title: 'Smart Watch Pro',
            description: 'Stay connected on the go with health tracking, notifications, and 7-day battery life. From $399',
            ctaText: 'Shop Now',
            link: '#product-watch'
          },
          {
            id: 'product3',
            image: 'https://images.unsplash.com/photo-1572635196237-14b3f281503f?w=1400&h=600&fit=crop',
            title: 'Designer Sunglasses',
            description: 'Where style meets UV protection. Handcrafted Italian frames with polarized lenses. $199',
            ctaText: 'Shop Now',
            link: '#product-sunglasses'
          },
        ],
        autoplay: true,
        interval: 5000,
        showArrows: true,
        showDots: true,
        animationStyle: 'zoom',
        fullWidth: false,
        height: '600px',
        captionTitleSize: '36px',
        captionDescSize: '18px',
        captionPosition: 'bottom',
        captionColor: '#ffffff',
        captionAlign: 'center',
        captionBg: 'auto',
      }, {
        borderRadius: '12px',
        overflow: 'hidden',
        boxShadow: '0 20px 60px rgba(0,0,0,0.1)',
      }),
    ]),
  ]),
};

const slideshowTestimonials: SectionTemplate = {
  id: 'slideshow-testimonials',
  name: 'Testimonial Slideshow',
  category: 'testimonials',
  description: 'Customer testimonials with photos and quotes',
  createNode: () => createNode('section', {}, {
    padding: '80px 24px',
    backgroundColor: '#f8fafc',
  }, [
    createNode('container', {}, {
      maxWidth: '1000px',
    }, [
      createNode('heading', { content: 'What Our Customers Say', level: 2 }, {
        fontSize: '38px',
        fontWeight: '700',
        textAlign: 'center',
        marginBottom: '56px',
        color: '#0f172a',
      }),
      createNode('slideshow', {
        slides: [
          {
            id: 'testimonial1',
            image: 'https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=1000&h=500&fit=crop',
            title: '"Game-changing platform!"',
            description: 'This tool has transformed how we work. Highly recommended! - Sarah Johnson, CEO'
          },
          {
            id: 'testimonial2',
            image: 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=1000&h=500&fit=crop',
            title: '"Incredible results"',
            description: 'We saw 300% growth in just 6 months. Amazing! - Michael Chen, Founder'
          },
          {
            id: 'testimonial3',
            image: 'https://images.unsplash.com/photo-1438761681033-6461ffad8d80?w=1000&h=500&fit=crop',
            title: '"Best investment ever"',
            description: 'The ROI has been phenomenal. Worth every penny! - Emily Davis, CMO'
          },
        ],
        autoplay: true,
        interval: 7000,
        showArrows: true,
        showDots: true,
        animationStyle: 'slide',
        fullWidth: false,
        height: '500px',
        captionTitleSize: '28px',
        captionDescSize: '16px',
        captionPosition: 'center',
        captionColor: '#ffffff',
        captionAlign: 'center',
        captionBg: 'rgba(0,0,0,0.5)',
      }, {
        borderRadius: '16px',
        overflow: 'hidden',
      }),
    ]),
  ]),
};

const slideshowPortfolio: SectionTemplate = {
  id: 'slideshow-portfolio',
  name: 'Portfolio Gallery',
  category: 'content',
  description: 'Showcase your work with a stunning portfolio slideshow',
  createNode: () => createNode('section', {}, {
    padding: '80px 24px',
    backgroundColor: '#0f172a',
  }, [
    createNode('container', {}, {
      maxWidth: '1600px',
    }, [
      createNode('heading', { content: 'Our Work', level: 2 }, {
        fontSize: '48px',
        fontWeight: '700',
        textAlign: 'center',
        marginBottom: '64px',
        color: '#ffffff',
      }),
      createNode('slideshow', {
        slides: [
          {
            id: 'project1',
            image: 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?w=1600&h=700&fit=crop',
            title: 'E-Commerce Platform',
            description: 'Modern shopping experience for global brands',
            ctaText: 'View Project',
            link: '#'
          },
          {
            id: 'project2',
            image: 'https://images.unsplash.com/photo-1551434678-e076c223a692?w=1600&h=700&fit=crop',
            title: 'Mobile App Design',
            description: 'Intuitive interfaces that users love',
            ctaText: 'View Project',
            link: '#'
          },
          {
            id: 'project3',
            image: 'https://images.unsplash.com/photo-1542744173-8e7e53415bb0?w=1600&h=700&fit=crop',
            title: 'Brand Identity',
            description: 'Creating memorable visual experiences',
            ctaText: 'View Project',
            link: '#'
          },
        ],
        autoplay: true,
        interval: 5500,
        showArrows: true,
        showDots: true,
        animationStyle: 'cube',
        fullWidth: false,
        height: '700px',
        captionTitleSize: '36px',
        captionDescSize: '18px',
        captionPosition: 'bottom',
        captionColor: '#ffffff',
        captionAlign: 'left',
        captionBg: 'auto',
      }, {}),
    ]),
  ]),
};

const slideshowCompactBanner: SectionTemplate = {
  id: 'slideshow-compact-banner',
  name: 'Compact Banner',
  category: 'cta',
  description: 'Small promotional banner slideshow',
  createNode: () => createNode('section', {}, {
    padding: '40px 24px',
    backgroundColor: '#ffffff',
  }, [
    createNode('container', {}, {
      maxWidth: '1200px',
    }, [
      createNode('slideshow', {
        slides: [
          {
            id: 'promo1',
            image: 'https://images.unsplash.com/photo-1607082348824-0a96f2a4b9da?w=1200&h=300&fit=crop',
            title: '🎉 Summer Sale - 50% Off',
            description: 'Limited time offer on all products',
            ctaText: 'Shop Sale',
            link: '#'
          },
          {
            id: 'promo2',
            image: 'https://images.unsplash.com/photo-1607082349566-187342175e2f?w=1200&h=300&fit=crop',
            title: '🚀 New Arrivals',
            description: 'Check out our latest collection',
            ctaText: 'Browse Now',
            link: '#'
          },
          {
            id: 'promo3',
            image: 'https://images.unsplash.com/photo-1607082350899-7e105aa886ae?w=1200&h=300&fit=crop',
            title: '📦 Free Shipping',
            description: 'On orders over $50',
            ctaText: 'Shop Now',
            link: '#'
          },
        ],
        autoplay: true,
        interval: 4000,
        showArrows: false,
        showDots: true,
        animationStyle: 'slide',
        fullWidth: false,
        height: '300px',
        captionTitleSize: '24px',
        captionDescSize: '14px',
        captionPosition: 'center',
        captionColor: '#ffffff',
        captionAlign: 'center',
        captionBg: 'rgba(0,0,0,0.4)',
      }, {
        borderRadius: '8px',
        overflow: 'hidden',
      }),
    ]),
  ]),
};

// =============================================================================
// Blog Templates (Dec 2025)
// =============================================================================

const blogPostGrid: SectionTemplate = {
  id: 'blog-post-grid',
  name: 'Blog Post Grid',
  category: 'content',
  description: 'Dynamic grid of blog posts with configurable options',
  createNode: () => createSection({}, {
    padding: '60px 24px',
    backgroundColor: '#ffffff',
  }, [
    createContainer({}, {
      maxWidth: '1200px',
      display: 'flex',
      flexDirection: 'column',
      gap: '32px',
    }, [
      createNode('heading', { content: 'Latest Articles', level: 2 }, {
        fontSize: '36px',
        fontWeight: '700',
        textAlign: 'center',
        color: '#1f2937',
        marginBottom: '16px',
      }),
      createNode('posts_grid', {
        postCount: 3,
        categoryIds: [],
        showDate: true,
        showExcerpt: true,
        excerptLength: 120,
        showFeaturedImage: true,
        showAuthor: false,
        showReadMore: true,
        gridColumns: 3,
        postType: 'post',
        orderBy: 'date',
        order: 'desc',
      }, {
        gap: '24px',
      }),
    ]),
  ]),
};

const blogFeaturedPost: SectionTemplate = {
  id: 'blog-featured-post',
  name: 'Featured Post',
  category: 'content',
  description: 'Large featured blog post with side content',
  createNode: () => createSection({}, {
    padding: '80px 24px',
    backgroundColor: '#f8fafc',
  }, [
    createContainer({}, {
      maxWidth: '1200px',
    }, [
      createFlexRow({}, { gap: '48px', alignItems: 'stretch' }, [
        // Featured image
        createFlexColumn({}, { flex: '1 1 55%' }, [
          createImage({ src: 'https://images.unsplash.com/photo-1522202176988-66273c2fd55f?w=800&h=600&fit=crop', alt: 'Featured post' }, {
            height: '400px',
            borderRadius: '16px',
            boxShadow: '0 20px 25px -5px rgba(0,0,0,0.1)',
          }),
        ]),
        // Content
        createFlexColumn({}, { flex: '1 1 40%', justifyContent: 'center', gap: '20px' }, [
          createNode('text', { content: 'FEATURED ARTICLE' }, { fontSize: '12px', color: '#3B82F6', fontWeight: '600', letterSpacing: '0.1em' }),
          createNode('heading', { content: 'The Complete Guide to Building Modern Websites', level: 2 }, { fontSize: '32px', fontWeight: '700', color: '#1f2937', lineHeight: '1.3' }),
          createNode('text', { content: 'Everything you need to know about creating professional websites that convert visitors into customers. From design principles to technical implementation.' }, { fontSize: '16px', color: '#6b7280', lineHeight: '1.7' }),
          createFlexRow({}, { gap: '16px', alignItems: 'center' }, [
            createNode('image', { src: 'https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=100&h=100&fit=crop', alt: 'Author' }, { width: '48px', height: '48px', borderRadius: '50%' }),
            createNode('container', {}, { display: 'flex', flexDirection: 'column', gap: '2px' }, [
              createNode('text', { content: 'John Smith' }, { fontSize: '14px', fontWeight: '600', color: '#1f2937' }),
              createNode('text', { content: 'December 27, 2025 · 8 min read' }, { fontSize: '13px', color: '#6b7280' }),
            ]),
          ]),
          createButton({ content: 'Read Article', variant: 'primary' }, { padding: '14px 28px' }),
        ]),
      ]),
    ]),
  ]),
};

// =============================================================================
// Portfolio Templates (Dec 2025)
// =============================================================================

const portfolioGrid: SectionTemplate = {
  id: 'portfolio-grid',
  name: 'Portfolio Grid',
  category: 'content',
  description: 'Showcase your work with a masonry-style grid',
  createNode: () => createSection({}, {
    padding: '80px 24px',
    backgroundColor: '#0f172a',
  }, [
    createContainer({}, {
      maxWidth: '1200px',
      display: 'flex',
      flexDirection: 'column',
      gap: '48px',
    }, [
      createNode('container', {}, { textAlign: 'center', marginBottom: '16px' }, [
        createNode('heading', { content: 'Our Portfolio', level: 2 }, { fontSize: '42px', fontWeight: '700', color: '#ffffff', marginBottom: '16px' }),
        createNode('text', { content: 'A selection of our finest work across various industries' }, { fontSize: '18px', color: '#94a3b8' }),
      ]),
      createNode('gallery', {
        images: [
          { id: 'p1', src: 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?w=600&h=400&fit=crop', alt: 'Web Design Project', caption: 'E-Commerce Platform' },
          { id: 'p2', src: 'https://images.unsplash.com/photo-1551434678-e076c223a692?w=600&h=400&fit=crop', alt: 'Mobile App', caption: 'Mobile Banking App' },
          { id: 'p3', src: 'https://images.unsplash.com/photo-1542744173-8e7e53415bb0?w=600&h=400&fit=crop', alt: 'Brand Identity', caption: 'Brand Identity Design' },
          { id: 'p4', src: 'https://images.unsplash.com/photo-1559136555-9303baea8ebd?w=600&h=400&fit=crop', alt: 'Marketing Campaign', caption: 'Digital Marketing Campaign' },
          { id: 'p5', src: 'https://images.unsplash.com/photo-1522202176988-66273c2fd55f?w=600&h=400&fit=crop', alt: 'Team Collaboration', caption: 'SaaS Dashboard' },
          { id: 'p6', src: 'https://images.unsplash.com/photo-1553877522-43269d4ea984?w=600&h=400&fit=crop', alt: 'Product Design', caption: 'Product Launch Website' },
        ],
        columns: 3,
        lightbox: true,
      }, {}),
    ]),
  ]),
};

const portfolioCaseStudy: SectionTemplate = {
  id: 'portfolio-case-study',
  name: 'Case Study',
  category: 'content',
  description: 'Detailed case study layout with project info',
  createNode: () => createSection({}, {
    padding: '80px 24px',
    backgroundColor: '#ffffff',
  }, [
    createContainer({}, {
      maxWidth: '1000px',
      display: 'flex',
      flexDirection: 'column',
      gap: '48px',
    }, [
      // Header
      createNode('container', {}, { textAlign: 'center', marginBottom: '24px' }, [
        createNode('text', { content: 'CASE STUDY' }, { fontSize: '12px', color: '#3B82F6', fontWeight: '600', letterSpacing: '0.1em', marginBottom: '16px' }),
        createNode('heading', { content: 'Redesigning the Future of Online Shopping', level: 1 }, { fontSize: '42px', fontWeight: '700', color: '#1f2937', lineHeight: '1.2' }),
      ]),
      // Hero image
      createImage({ src: 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?w=1200&h=600&fit=crop', alt: 'Project showcase' }, { borderRadius: '16px', height: '500px' }),
      // Project details
      createFlexRow({}, { gap: '48px' }, [
        createFlexColumn({}, { flex: '1 1 200px' }, [
          createNode('text', { content: 'CLIENT' }, { fontSize: '12px', color: '#6b7280', fontWeight: '600', letterSpacing: '0.05em', marginBottom: '8px' }),
          createNode('text', { content: 'TechCorp Inc.' }, { fontSize: '16px', color: '#1f2937', fontWeight: '500' }),
        ]),
        createFlexColumn({}, { flex: '1 1 200px' }, [
          createNode('text', { content: 'SERVICES' }, { fontSize: '12px', color: '#6b7280', fontWeight: '600', letterSpacing: '0.05em', marginBottom: '8px' }),
          createNode('text', { content: 'UX Design, Development' }, { fontSize: '16px', color: '#1f2937', fontWeight: '500' }),
        ]),
        createFlexColumn({}, { flex: '1 1 200px' }, [
          createNode('text', { content: 'YEAR' }, { fontSize: '12px', color: '#6b7280', fontWeight: '600', letterSpacing: '0.05em', marginBottom: '8px' }),
          createNode('text', { content: '2025' }, { fontSize: '16px', color: '#1f2937', fontWeight: '500' }),
        ]),
        createFlexColumn({}, { flex: '1 1 200px' }, [
          createNode('text', { content: 'RESULTS' }, { fontSize: '12px', color: '#6b7280', fontWeight: '600', letterSpacing: '0.05em', marginBottom: '8px' }),
          createNode('text', { content: '+150% Conversions' }, { fontSize: '16px', color: '#10B981', fontWeight: '600' }),
        ]),
      ]),
      // Description
      createNode('text', { content: 'We partnered with TechCorp to completely reimagine their e-commerce experience. Through extensive user research, iterative design, and cutting-edge development, we delivered a platform that not only looks stunning but drives real business results. The new design reduced cart abandonment by 40% and increased average order value by 25%.' }, { fontSize: '18px', color: '#4b5563', lineHeight: '1.8' }),
    ]),
  ]),
};

// =============================================================================
// Services Templates (Dec 2025)
// =============================================================================

const servicesGrid: SectionTemplate = {
  id: 'services-grid',
  name: 'Services Grid',
  category: 'features',
  description: 'Display your services in a clean grid layout',
  createNode: () => createSection({}, {
    padding: '80px 24px',
    backgroundColor: '#ffffff',
  }, [
    createContainer({}, {
      maxWidth: '1200px',
      display: 'flex',
      flexDirection: 'column',
      gap: '48px',
    }, [
      createNode('container', {}, { textAlign: 'center', marginBottom: '16px' }, [
        createNode('heading', { content: 'Our Services', level: 2 }, { fontSize: '36px', fontWeight: '700', color: '#1f2937', marginBottom: '16px' }),
        createNode('text', { content: 'Comprehensive solutions tailored to your business needs' }, { fontSize: '18px', color: '#6b7280' }),
      ]),
      createFlexRow({}, { gap: '32px' }, [
        createFlexColumn({}, {
          flex: '1 1 280px',
          padding: '32px',
          backgroundColor: '#f8fafc',
          borderRadius: '16px',
          gap: '16px',
        }, [
          createNode('icon', { icon: 'Zap', size: '32' }, { color: '#3B82F6' }),
          createNode('heading', { content: 'Web Development', level: 3 }, { fontSize: '20px', fontWeight: '600', color: '#1f2937' }),
          createNode('text', { content: 'Custom websites built with modern technologies for optimal performance and user experience.' }, { fontSize: '14px', color: '#6b7280', lineHeight: '1.6' }),
          createNode('list', { items: ['Responsive Design', 'SEO Optimized', 'Fast Loading'], listType: 'check' }, {}),
        ]),
        createFlexColumn({}, {
          flex: '1 1 280px',
          padding: '32px',
          backgroundColor: '#f8fafc',
          borderRadius: '16px',
          gap: '16px',
        }, [
          createNode('icon', { icon: 'Shield', size: '32' }, { color: '#10B981' }),
          createNode('heading', { content: 'Brand Strategy', level: 3 }, { fontSize: '20px', fontWeight: '600', color: '#1f2937' }),
          createNode('text', { content: 'Build a strong brand identity that resonates with your target audience and stands out.' }, { fontSize: '14px', color: '#6b7280', lineHeight: '1.6' }),
          createNode('list', { items: ['Logo Design', 'Brand Guidelines', 'Visual Identity'], listType: 'check' }, {}),
        ]),
        createFlexColumn({}, {
          flex: '1 1 280px',
          padding: '32px',
          backgroundColor: '#f8fafc',
          borderRadius: '16px',
          gap: '16px',
        }, [
          createNode('icon', { icon: 'Clock', size: '32' }, { color: '#F59E0B' }),
          createNode('heading', { content: 'Digital Marketing', level: 3 }, { fontSize: '20px', fontWeight: '600', color: '#1f2937' }),
          createNode('text', { content: 'Data-driven marketing strategies to grow your online presence and drive conversions.' }, { fontSize: '14px', color: '#6b7280', lineHeight: '1.6' }),
          createNode('list', { items: ['Social Media', 'Content Strategy', 'Analytics'], listType: 'check' }, {}),
        ]),
      ]),
    ]),
  ]),
};

const servicesProcess: SectionTemplate = {
  id: 'services-process',
  name: 'Process Steps',
  category: 'features',
  description: 'Show your work process in numbered steps',
  createNode: () => createSection({}, {
    padding: '80px 24px',
    backgroundColor: '#0f172a',
  }, [
    createContainer({}, {
      maxWidth: '1000px',
      display: 'flex',
      flexDirection: 'column',
      gap: '64px',
    }, [
      createNode('container', {}, { textAlign: 'center' }, [
        createNode('heading', { content: 'How We Work', level: 2 }, { fontSize: '36px', fontWeight: '700', color: '#ffffff', marginBottom: '16px' }),
        createNode('text', { content: 'Our proven process delivers results every time' }, { fontSize: '18px', color: '#94a3b8' }),
      ]),
      createNode('container', {}, { display: 'flex', flexDirection: 'column', gap: '48px' }, [
        // Step 1
        createFlexRow({}, { gap: '32px', alignItems: 'center' }, [
          createNode('container', {}, {
            width: '80px',
            height: '80px',
            backgroundColor: '#3B82F6',
            borderRadius: '50%',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            flexShrink: '0',
          }, [
            createNode('text', { content: '01' }, { fontSize: '24px', fontWeight: '700', color: '#ffffff' }),
          ]),
          createNode('container', {}, { flex: '1' }, [
            createNode('heading', { content: 'Discovery & Research', level: 3 }, { fontSize: '24px', fontWeight: '600', color: '#ffffff', marginBottom: '8px' }),
            createNode('text', { content: 'We dive deep into understanding your business, goals, and target audience to create a solid foundation for success.' }, { fontSize: '16px', color: '#94a3b8', lineHeight: '1.6' }),
          ]),
        ]),
        // Step 2
        createFlexRow({}, { gap: '32px', alignItems: 'center' }, [
          createNode('container', {}, {
            width: '80px',
            height: '80px',
            backgroundColor: '#10B981',
            borderRadius: '50%',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            flexShrink: '0',
          }, [
            createNode('text', { content: '02' }, { fontSize: '24px', fontWeight: '700', color: '#ffffff' }),
          ]),
          createNode('container', {}, { flex: '1' }, [
            createNode('heading', { content: 'Design & Prototype', level: 3 }, { fontSize: '24px', fontWeight: '600', color: '#ffffff', marginBottom: '8px' }),
            createNode('text', { content: 'Our designers create stunning visuals and interactive prototypes that bring your vision to life.' }, { fontSize: '16px', color: '#94a3b8', lineHeight: '1.6' }),
          ]),
        ]),
        // Step 3
        createFlexRow({}, { gap: '32px', alignItems: 'center' }, [
          createNode('container', {}, {
            width: '80px',
            height: '80px',
            backgroundColor: '#F59E0B',
            borderRadius: '50%',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            flexShrink: '0',
          }, [
            createNode('text', { content: '03' }, { fontSize: '24px', fontWeight: '700', color: '#ffffff' }),
          ]),
          createNode('container', {}, { flex: '1' }, [
            createNode('heading', { content: 'Development & Launch', level: 3 }, { fontSize: '24px', fontWeight: '600', color: '#ffffff', marginBottom: '8px' }),
            createNode('text', { content: 'We build, test, and launch your project with meticulous attention to detail and performance.' }, { fontSize: '16px', color: '#94a3b8', lineHeight: '1.6' }),
          ]),
        ]),
      ]),
    ]),
  ]),
};

// =============================================================================
// About Templates (Dec 2025)
// =============================================================================

const aboutTeam: SectionTemplate = {
  id: 'about-team',
  name: 'Team Section',
  category: 'content',
  description: 'Showcase your team members with photos and roles',
  createNode: () => createSection({}, {
    padding: '80px 24px',
    backgroundColor: '#ffffff',
  }, [
    createContainer({}, {
      maxWidth: '1200px',
      display: 'flex',
      flexDirection: 'column',
      gap: '48px',
    }, [
      createNode('container', {}, { textAlign: 'center', marginBottom: '16px' }, [
        createNode('heading', { content: 'Meet Our Team', level: 2 }, { fontSize: '36px', fontWeight: '700', color: '#1f2937', marginBottom: '16px' }),
        createNode('text', { content: 'The talented people behind our success' }, { fontSize: '18px', color: '#6b7280' }),
      ]),
      createFlexRow({}, { gap: '32px', justifyContent: 'center' }, [
        createFlexColumn({}, { flex: '0 1 280px', alignItems: 'center', textAlign: 'center', gap: '16px' }, [
          createNode('image', { src: 'https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=300&h=300&fit=crop', alt: 'Team member' }, { width: '180px', height: '180px', borderRadius: '50%', objectFit: 'cover' }),
          createNode('heading', { content: 'John Smith', level: 3 }, { fontSize: '20px', fontWeight: '600', color: '#1f2937' }),
          createNode('text', { content: 'CEO & Founder' }, { fontSize: '14px', color: '#3B82F6', fontWeight: '500' }),
          createNode('text', { content: 'Visionary leader with 15+ years of experience in digital transformation.' }, { fontSize: '14px', color: '#6b7280', lineHeight: '1.5' }),
          createNode('social_icons', { icons: [{ platform: 'linkedin', url: '#' }, { platform: 'twitter', url: '#' }], size: '20' }, {}),
        ]),
        createFlexColumn({}, { flex: '0 1 280px', alignItems: 'center', textAlign: 'center', gap: '16px' }, [
          createNode('image', { src: 'https://images.unsplash.com/photo-1438761681033-6461ffad8d80?w=300&h=300&fit=crop', alt: 'Team member' }, { width: '180px', height: '180px', borderRadius: '50%', objectFit: 'cover' }),
          createNode('heading', { content: 'Sarah Johnson', level: 3 }, { fontSize: '20px', fontWeight: '600', color: '#1f2937' }),
          createNode('text', { content: 'Creative Director' }, { fontSize: '14px', color: '#3B82F6', fontWeight: '500' }),
          createNode('text', { content: 'Award-winning designer passionate about creating memorable experiences.' }, { fontSize: '14px', color: '#6b7280', lineHeight: '1.5' }),
          createNode('social_icons', { icons: [{ platform: 'linkedin', url: '#' }, { platform: 'instagram', url: '#' }], size: '20' }, {}),
        ]),
        createFlexColumn({}, { flex: '0 1 280px', alignItems: 'center', textAlign: 'center', gap: '16px' }, [
          createNode('image', { src: 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=300&h=300&fit=crop', alt: 'Team member' }, { width: '180px', height: '180px', borderRadius: '50%', objectFit: 'cover' }),
          createNode('heading', { content: 'Mike Chen', level: 3 }, { fontSize: '20px', fontWeight: '600', color: '#1f2937' }),
          createNode('text', { content: 'Lead Developer' }, { fontSize: '14px', color: '#3B82F6', fontWeight: '500' }),
          createNode('text', { content: 'Full-stack expert who turns complex ideas into elegant solutions.' }, { fontSize: '14px', color: '#6b7280', lineHeight: '1.5' }),
          createNode('social_icons', { icons: [{ platform: 'linkedin', url: '#' }, { platform: 'github', url: '#' }], size: '20' }, {}),
        ]),
      ]),
    ]),
  ]),
};

const aboutStory: SectionTemplate = {
  id: 'about-story',
  name: 'Company Story',
  category: 'content',
  description: 'Tell your company story with timeline',
  createNode: () => createSection({}, {
    padding: '80px 24px',
    backgroundColor: '#f8fafc',
  }, [
    createContainer({}, {
      maxWidth: '900px',
      display: 'flex',
      flexDirection: 'column',
      gap: '48px',
    }, [
      createNode('container', {}, { textAlign: 'center', marginBottom: '24px' }, [
        createNode('heading', { content: 'Our Story', level: 2 }, { fontSize: '36px', fontWeight: '700', color: '#1f2937', marginBottom: '16px' }),
        createNode('text', { content: 'From humble beginnings to industry leaders' }, { fontSize: '18px', color: '#6b7280' }),
      ]),
      createFlexRow({}, { gap: '48px', alignItems: 'center' }, [
        createFlexColumn({}, { flex: '1 1 45%' }, [
          createImage({ src: 'https://images.unsplash.com/photo-1522071820081-009f0129c71c?w=600&h=400&fit=crop', alt: 'Our team' }, { borderRadius: '16px', height: '350px' }),
        ]),
        createFlexColumn({}, { flex: '1 1 50%', gap: '20px' }, [
          createNode('text', { content: 'Founded in 2015, we started with a simple mission: to make professional web design accessible to everyone.' }, { fontSize: '16px', color: '#4b5563', lineHeight: '1.7' }),
          createNode('text', { content: 'What began as a two-person startup has grown into a team of 50+ passionate professionals serving clients worldwide. We\'ve helped over 500 businesses transform their digital presence.' }, { fontSize: '16px', color: '#4b5563', lineHeight: '1.7' }),
          createFlexRow({}, { gap: '32px', marginTop: '16px' }, [
            createNode('container', {}, { textAlign: 'center' }, [
              createNode('counter', { startValue: '0', endValue: '500', suffix: '+', title: 'Clients' }, {}),
            ]),
            createNode('container', {}, { textAlign: 'center' }, [
              createNode('counter', { startValue: '0', endValue: '50', suffix: '+', title: 'Team Members' }, {}),
            ]),
            createNode('container', {}, { textAlign: 'center' }, [
              createNode('counter', { startValue: '0', endValue: '10', suffix: '+', title: 'Years' }, {}),
            ]),
          ]),
        ]),
      ]),
    ]),
  ]),
};

const aboutMission: SectionTemplate = {
  id: 'about-mission',
  name: 'Mission & Values',
  category: 'content',
  description: 'Share your company mission and core values',
  createNode: () => createSection({}, {
    padding: '80px 24px',
    backgroundColor: '#0f172a',
  }, [
    createContainer({}, {
      maxWidth: '1200px',
      display: 'flex',
      flexDirection: 'column',
      gap: '64px',
    }, [
      // Mission statement
      createNode('container', {}, { textAlign: 'center', maxWidth: '800px', margin: '0 auto' }, [
        createNode('text', { content: 'OUR MISSION' }, { fontSize: '12px', color: '#3B82F6', fontWeight: '600', letterSpacing: '0.1em', marginBottom: '16px' }),
        createNode('heading', { content: 'Empowering businesses to thrive in the digital age through innovative design and technology.' }, { fontSize: '32px', fontWeight: '600', color: '#ffffff', lineHeight: '1.4' }),
      ]),
      // Values
      createNode('container', {}, { marginTop: '24px' }, [
        createNode('heading', { content: 'Our Core Values', level: 3 }, { fontSize: '24px', fontWeight: '600', color: '#ffffff', textAlign: 'center', marginBottom: '40px' }),
        createFlexRow({}, { gap: '24px' }, [
          createFlexColumn({}, {
            flex: '1 1 250px',
            padding: '32px',
            backgroundColor: 'rgba(255,255,255,0.05)',
            borderRadius: '12px',
            border: '1px solid rgba(255,255,255,0.1)',
          }, [
            createNode('icon', { icon: 'Heart', size: '32' }, { color: '#EF4444', marginBottom: '16px' }),
            createNode('heading', { content: 'Passion', level: 4 }, { fontSize: '18px', fontWeight: '600', color: '#ffffff', marginBottom: '8px' }),
            createNode('text', { content: 'We love what we do and it shows in every project we deliver.' }, { fontSize: '14px', color: '#94a3b8', lineHeight: '1.6' }),
          ]),
          createFlexColumn({}, {
            flex: '1 1 250px',
            padding: '32px',
            backgroundColor: 'rgba(255,255,255,0.05)',
            borderRadius: '12px',
            border: '1px solid rgba(255,255,255,0.1)',
          }, [
            createNode('icon', { icon: 'Star', size: '32' }, { color: '#F59E0B', marginBottom: '16px' }),
            createNode('heading', { content: 'Excellence', level: 4 }, { fontSize: '18px', fontWeight: '600', color: '#ffffff', marginBottom: '8px' }),
            createNode('text', { content: 'We never settle for good enough. Excellence is our standard.' }, { fontSize: '14px', color: '#94a3b8', lineHeight: '1.6' }),
          ]),
          createFlexColumn({}, {
            flex: '1 1 250px',
            padding: '32px',
            backgroundColor: 'rgba(255,255,255,0.05)',
            borderRadius: '12px',
            border: '1px solid rgba(255,255,255,0.1)',
          }, [
            createNode('icon', { icon: 'Check', size: '32' }, { color: '#10B981', marginBottom: '16px' }),
            createNode('heading', { content: 'Integrity', level: 4 }, { fontSize: '18px', fontWeight: '600', color: '#ffffff', marginBottom: '8px' }),
            createNode('text', { content: 'Honesty and transparency guide every decision we make.' }, { fontSize: '14px', color: '#94a3b8', lineHeight: '1.6' }),
          ]),
          createFlexColumn({}, {
            flex: '1 1 250px',
            padding: '32px',
            backgroundColor: 'rgba(255,255,255,0.05)',
            borderRadius: '12px',
            border: '1px solid rgba(255,255,255,0.1)',
          }, [
            createNode('icon', { icon: 'Zap', size: '32' }, { color: '#3B82F6', marginBottom: '16px' }),
            createNode('heading', { content: 'Innovation', level: 4 }, { fontSize: '18px', fontWeight: '600', color: '#ffffff', marginBottom: '8px' }),
            createNode('text', { content: 'We embrace new ideas and push boundaries constantly.' }, { fontSize: '14px', color: '#94a3b8', lineHeight: '1.6' }),
          ]),
        ]),
      ]),
    ]),
  ]),
};

// =============================================================================
// Entity Templates (Mar 2026)
// =============================================================================

const entityCurrentDetail: SectionTemplate = {
  id: 'entity-current-detail',
  name: 'Current Entity Detail',
  category: 'entity',
  description: 'Full current-entity layout using the canonical entity view contract',
  createNode: () => createEntityTemplateSection({
    eyebrow: 'Universal entity contract',
    heading: 'Current entity detail',
    description: 'Use this on entity pages when you want the builder section to stay aligned with the canonical entity view contract instead of rebuilding media, capability blocks, and actions by hand.',
    badges: ['Current entity', 'Media', 'Pricing', 'Inventory', 'Actions'],
    accentColor: '#0f766e',
    badgeBackgroundColor: '#dcfce7',
    surfaceColor: '#ffffff',
    widget: createNode('entity_view', {
      showFeaturedImage: true,
      showTitle: true,
      showMeta: true,
      showTypeLabel: true,
      showAuthor: true,
      showDate: true,
      showPricing: true,
      showInventory: true,
      showSku: true,
      showProgress: true,
      showLessons: true,
      showActions: true,
      showBody: true,
    }, {
      width: '100%',
      display: 'block',
    }),
  }),
};

const entityProductStorefront: SectionTemplate = {
  id: 'entity-product-storefront',
  name: 'Product Storefront',
  category: 'entity',
  description: 'Ecommerce-ready product grid with pricing and direct product CTA buttons',
  createNode: () => createEntityTemplateSection({
    eyebrow: 'Ecommerce preset',
    heading: 'Featured products',
    description: 'Built for product entities using the ecommerce preset. This template leads with imagery, description, price, and a direct product action so it can drop straight into a storefront or landing page.',
    badges: ['Preset: ecommerce', 'Cards: pricing', 'Cards: action'],
    accentColor: '#0f766e',
    badgeBackgroundColor: '#ccfbf1',
    widget: createNode('products_grid', {
      itemCount: 6,
      gridColumns: 3,
      showImage: true,
      showTitle: true,
      showExcerpt: true,
      excerptLength: 100,
      showMeta: true,
      showAction: true,
      actionText: 'View Product',
      orderBy: 'date',
      order: 'desc',
      emptyMessage: 'Add published products to populate your storefront.',
    }, {
      display: 'grid',
      gridTemplateColumns: 'repeat(3, 1fr)',
      gap: '24px',
      width: '100%',
    }),
  }),
};

const entityProductInventoryCards: SectionTemplate = {
  id: 'entity-product-inventory-cards',
  name: 'Product Inventory Cards',
  category: 'entity',
  description: 'Product list cards that emphasize pricing and stock state',
  createNode: () => createEntityTemplateSection({
    eyebrow: 'Storefront cards',
    heading: 'Inventory-aware product cards',
    description: 'Use this when you want the product catalog to foreground the pricing and inventory card surfaces defined by the entity list contract.',
    badges: ['Preset: ecommerce', 'Cards: pricing', 'Cards: inventory'],
    accentColor: '#0f766e',
    badgeBackgroundColor: '#ccfbf1',
    widget: createNode('entity_list', {
      entityType: 'product',
      itemCount: 6,
      layout: 'grid',
      gridColumns: 3,
      showFeaturedImage: true,
      showTitle: true,
      showExcerpt: true,
      excerptLength: 110,
      showPricing: true,
      showInventory: true,
      showProgress: false,
      showActions: false,
      emptyMessage: 'Add published products to populate these cards.',
      orderBy: 'date',
      order: 'desc',
    }, {
      display: 'grid',
      gridTemplateColumns: 'repeat(3, 1fr)',
      gap: '24px',
      width: '100%',
    }),
  }),
};

const entityBusinessServices: SectionTemplate = {
  id: 'entity-business-services',
  name: 'Business Services',
  category: 'entity',
  description: 'Service directory tuned for inquiry and booking led business services',
  createNode: () => createEntityTemplateSection({
    eyebrow: 'Business preset',
    heading: 'Service directory',
    description: 'Best for service entities that should lead with description and contact intent rather than a hard-sell product flow. Action cards surface booking or inquiry when those capabilities are attached.',
    badges: ['Preset: business', 'Cards: action'],
    accentColor: '#166534',
    badgeBackgroundColor: '#dcfce7',
    backgroundColor: '#f0fdf4',
    widget: createNode('entity_list', {
      entityType: 'service',
      itemCount: 6,
      layout: 'grid',
      gridColumns: 3,
      showFeaturedImage: true,
      showTitle: true,
      showExcerpt: true,
      excerptLength: 120,
      showPricing: false,
      showInventory: false,
      showProgress: false,
      showActions: true,
      emptyMessage: 'Add published service entries to populate this directory.',
      orderBy: 'title',
      order: 'asc',
    }, {
      display: 'grid',
      gridTemplateColumns: 'repeat(3, 1fr)',
      gap: '24px',
      width: '100%',
    }),
  }),
};

const entitySellableServices: SectionTemplate = {
  id: 'entity-sellable-services',
  name: 'Sellable Service Cards',
  category: 'entity',
  description: 'Commerce-aware service cards with price plus booking or inquiry CTA',
  createNode: () => createEntityTemplateSection({
    eyebrow: 'Service-commerce preset',
    heading: 'Sellable service cards',
    description: 'A stronger commercial service layout for entities using the service-commerce preset. Pricing stays visible while the action surface can drive booking or inquiry depending on the attached capability mix.',
    badges: ['Preset: service-commerce', 'Cards: pricing', 'Cards: action'],
    accentColor: '#15803d',
    badgeBackgroundColor: '#dcfce7',
    backgroundColor: '#f7fee7',
    widget: createNode('entity_list', {
      entityType: 'service',
      itemCount: 6,
      layout: 'grid',
      gridColumns: 3,
      showFeaturedImage: true,
      showTitle: true,
      showExcerpt: true,
      excerptLength: 110,
      showPricing: true,
      showInventory: false,
      showProgress: false,
      showActions: true,
      emptyMessage: 'Add published service entries to populate these sellable cards.',
      orderBy: 'title',
      order: 'asc',
    }, {
      display: 'grid',
      gridTemplateColumns: 'repeat(3, 1fr)',
      gap: '24px',
      width: '100%',
    }),
  }),
};

const entityCourseDirectory: SectionTemplate = {
  id: 'entity-course-directory',
  name: 'Course Directory',
  category: 'entity',
  description: 'Course cards with pricing and learner progress surface',
  createNode: () => createEntityTemplateSection({
    eyebrow: 'Education preset',
    heading: 'Course catalog',
    description: 'Designed for course entities using the education preset. It pairs a normal catalog grid with the progress surface so the listing can work for both marketing and returning learners.',
    badges: ['Preset: education', 'Cards: pricing', 'Cards: progress'],
    accentColor: '#6d28d9',
    badgeBackgroundColor: '#ede9fe',
    backgroundColor: '#faf5ff',
    widget: createNode('entity_list', {
      entityType: 'course',
      itemCount: 6,
      layout: 'grid',
      gridColumns: 3,
      showFeaturedImage: true,
      showTitle: true,
      showExcerpt: true,
      excerptLength: 120,
      showPricing: true,
      showInventory: false,
      showProgress: true,
      showActions: false,
      emptyMessage: 'Add published courses to populate this directory.',
      orderBy: 'date',
      order: 'desc',
    }, {
      display: 'grid',
      gridTemplateColumns: 'repeat(3, 1fr)',
      gap: '24px',
      width: '100%',
    }),
  }),
};

const entitySellableCourses: SectionTemplate = {
  id: 'entity-sellable-courses',
  name: 'Sellable Course Cards',
  category: 'entity',
  description: 'Commerce-aware course cards with pricing, progress, and enrollment CTA',
  createNode: () => createEntityTemplateSection({
    eyebrow: 'Course-commerce preset',
    heading: 'Sellable courses',
    description: 'This version turns the course grid into a commerce-ready surface. Pricing, progress, and action states are all visible so enrolled and prospective learners can use the same section.',
    badges: ['Preset: course-commerce', 'Cards: pricing', 'Cards: progress', 'Cards: action'],
    accentColor: '#6d28d9',
    badgeBackgroundColor: '#ede9fe',
    backgroundColor: '#f5f3ff',
    widget: createNode('entity_list', {
      entityType: 'course',
      itemCount: 6,
      layout: 'grid',
      gridColumns: 3,
      showFeaturedImage: true,
      showTitle: true,
      showExcerpt: true,
      excerptLength: 120,
      showPricing: true,
      showInventory: false,
      showProgress: true,
      showActions: true,
      emptyMessage: 'Add published courses to populate these sellable cards.',
      orderBy: 'date',
      order: 'desc',
    }, {
      display: 'grid',
      gridTemplateColumns: 'repeat(3, 1fr)',
      gap: '24px',
      width: '100%',
    }),
  }),
};

const entityCourseDetail: SectionTemplate = {
  id: 'entity-course-detail',
  name: 'Course Detail Layout',
  category: 'entity',
  description: 'Current-course detail with lessons, progress, pricing, and actions',
  createNode: () => createEntityTemplateSection({
    eyebrow: 'Current entity',
    heading: 'Course detail',
    description: 'Use this on a course page when you want the builder to foreground the learning contract: lesson index, learner progress, pricing, and enrollment actions.',
    badges: ['Current entity', 'Lessons', 'Progress', 'Actions'],
    accentColor: '#7c3aed',
    badgeBackgroundColor: '#ede9fe',
    backgroundColor: '#faf5ff',
    widget: createNode('entity_view', {
      showFeaturedImage: true,
      showTitle: true,
      showMeta: true,
      showTypeLabel: true,
      showAuthor: false,
      showDate: true,
      showPricing: true,
      showInventory: false,
      showSku: false,
      showProgress: true,
      showLessons: true,
      showActions: true,
      showBody: true,
    }, {
      width: '100%',
      display: 'block',
    }),
  }),
};

const entityPortfolioSpotlight: SectionTemplate = {
  id: 'entity-portfolio-spotlight',
  name: 'Portfolio Spotlight',
  category: 'entity',
  description: 'Current-entity showcase tuned for media gallery and inquiry-driven portfolio pieces',
  createNode: () => createEntityTemplateSection({
    eyebrow: 'Portfolio preset',
    heading: 'Portfolio spotlight',
    description: 'A current-entity spotlight tuned for portfolio pieces. It keeps the media gallery front and center and leaves the action surface free for inquiry-driven calls to action like “Work with me.”',
    badges: ['Preset: portfolio', 'Current entity', 'Media', 'Cards: action'],
    accentColor: '#be185d',
    badgeBackgroundColor: '#fce7f3',
    backgroundColor: '#fff1f2',
    widget: createNode('entity_view', {
      showFeaturedImage: true,
      showTitle: true,
      showMeta: true,
      showTypeLabel: true,
      showAuthor: false,
      showDate: false,
      showPricing: false,
      showInventory: false,
      showSku: false,
      showProgress: false,
      showLessons: false,
      showActions: true,
      showBody: true,
    }, {
      width: '100%',
      display: 'block',
    }),
  }),
};

// =============================================================================
// Export All Templates
// =============================================================================

export const sectionTemplates: SectionTemplate[] = [
  // Hero
  heroSimple,
  heroWithImage,
  heroCentered,
  slideshowHeroFullscreen,
  slideshowProductShowcase,
  // Features / Services
  featuresThreeColumn,
  featuresWithIcons,
  servicesGrid,
  servicesProcess,
  // Content / Blog / Portfolio / About
  contentTwoColumn,
  slideshowPortfolio,
  blogPostGrid,
  blogFeaturedPost,
  portfolioGrid,
  portfolioCaseStudy,
  aboutTeam,
  aboutStory,
  aboutMission,
  // Entity presets and card surfaces
  entityCurrentDetail,
  entityProductStorefront,
  entityProductInventoryCards,
  entityBusinessServices,
  entitySellableServices,
  entityCourseDirectory,
  entitySellableCourses,
  entityCourseDetail,
  entityPortfolioSpotlight,
  // CTA
  ctaSimple,
  ctaBanner,
  slideshowCompactBanner,
  // Testimonials
  testimonialCards,
  slideshowTestimonials,
  // Pricing
  pricingThreeColumn,
  // Contact
  contactSimple,
  // Footer
  footerSimple,
];

export const templateCategories: { id: TemplateCategory; name: string }[] = [
  { id: 'hero', name: 'Hero' },
  { id: 'features', name: 'Features' },
  { id: 'content', name: 'Content' },
  { id: 'entity', name: 'Entity Presets' },
  { id: 'cta', name: 'Call to Action' },
  { id: 'testimonials', name: 'Testimonials' },
  { id: 'pricing', name: 'Pricing' },
  { id: 'contact', name: 'Contact' },
  { id: 'footer', name: 'Footer' },
];

export function getTemplatesByCategory(category: TemplateCategory): SectionTemplate[] {
  return sectionTemplates.filter(t => t.category === category);
}

export function getTemplateById(id: string): SectionTemplate | undefined {
  return sectionTemplates.find(t => t.id === id);
}
