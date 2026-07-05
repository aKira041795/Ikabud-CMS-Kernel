/**
 * Ikabud Page Builder - Component Definitions
 * Registry of available components for the CMS Page Builder
 */

import { ComponentDefinition, ComponentType } from './types';
import { getBootData, type BuilderNestingRule } from '@/lib/api';

// Re-export ComponentDefinition for use in other modules
export type { ComponentDefinition } from './types';

// ---------------------------------------------------------------------------
// Inline SVG placeholder generator — no external dependency (via.placeholder.com
// was unreliable). Returns a data URI that renders instantly in any <img> tag.
// ---------------------------------------------------------------------------
function placeholderSvg(w: number, h: number, bg: string, text: string): string {
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}">` +
    `<rect width="100%" height="100%" fill="${bg}"/>` +
    `<text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" ` +
    `fill="#fff" font-family="system-ui,sans-serif" font-size="${Math.max(14, Math.round(h / 8))}px" font-weight="600">` +
    `${text}</text></svg>`;
  return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
}

const themeWidgetCardStyle = {
  padding: '20px',
  border: '1px solid #e5e7eb',
  borderRadius: '16px',
  backgroundColor: '#ffffff',
  width: '100%',
};

// =============================================================================
// CMS Page Builder Components
// =============================================================================

export const CMS_COMPONENTS: ComponentDefinition[] = [
  // Document (root wrapper - not shown in component panel)
  {
    type: 'document',
    name: 'Document',
    icon: 'FileText',
    category: 'layout',
    description: 'Root document wrapper',
    defaultProps: {},
    defaultStyle: {},
    allowedChildren: ['section'],  // Only sections at root level
    allowedParents: [],  // Cannot be nested
    isLeaf: false,
  },
  // Layout Components
  {
    type: 'section',
    name: 'Section',
    icon: 'LayoutTemplate',
    category: 'layout',
    description: 'Full-width section container',
    defaultProps: {},
    defaultStyle: {
      padding: '48px 24px',
      width: '100%',
      display: 'flex',
      flexDirection: 'column',
      alignItems: 'center',
      justifyContent: 'center',
    },
    allowedChildren: null,
    allowedParents: ['document'],  // Sections can only be direct children of document
    isLeaf: false,
  },
  {
    type: 'container',
    name: 'Container',
    icon: 'Square',
    category: 'layout',
    description: 'Constrained content wrapper',
    defaultProps: {},
    defaultStyle: {
      maxWidth: '1200px',
      margin: '0 auto',
      padding: '0 24px',
    },
    allowedChildren: null,
    allowedParents: null,
    isLeaf: false,
  },
  {
    type: 'layout_container',
    name: 'Layout',
    icon: 'LayoutGrid',
    category: 'layout',
    description: 'Flex or grid layout container',
    defaultProps: {},
    defaultStyle: {
      width: '100%',
      minHeight: '100px',
      display: 'flex',
      flexDirection: 'column',
      gap: '24px',
    },
    allowedChildren: null,
    allowedParents: null,
    isLeaf: false,
  },
  {
    type: 'row',
    name: 'Row',
    icon: 'Columns',
    category: 'layout',
    description: 'Horizontal flex row',
    defaultProps: {},
    defaultStyle: {
      display: 'flex',
      flexDirection: 'row',
      gap: '24px',
      justifyContent: 'center',
      alignItems: 'stretch',
    },
    allowedChildren: ['column'],
    allowedParents: null,
    isLeaf: false,
  },
  {
    type: 'column',
    name: 'Column',
    icon: 'RectangleVertical',
    category: 'layout',
    description: 'Vertical column in a row',
    defaultProps: {},
    defaultStyle: {
      display: 'flex',
      flexDirection: 'column',
      gap: '16px',
      alignItems: 'stretch',
    },
    allowedChildren: null,
    allowedParents: ['row'],
    isLeaf: false,
  },

  // Content Components
  {
    type: 'heading',
    name: 'Heading',
    icon: 'Type',
    category: 'content',
    description: 'Heading text (H1-H6)',
    defaultProps: {
      content: 'Heading',
      level: 2,
    },
    defaultStyle: {
      fontSize: '32px',
      fontWeight: '700',
      lineHeight: '1.2',
      color: '#111827',
      textAlign: 'center',
      width: '100%',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },
  {
    type: 'text',
    name: 'Text',
    icon: 'AlignLeft',
    category: 'content',
    description: 'Paragraph text',
    defaultProps: {
      content: 'Enter your text here...',
    },
    defaultStyle: {
      fontSize: '16px',
      textAlign: 'left',
      lineHeight: '1.6',
      color: '#4B5563',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },
  {
    type: 'button',
    name: 'Button',
    icon: 'MousePointer',
    category: 'content',
    description: 'Clickable button',
    defaultProps: {
      content: 'Click me',
      href: '#',
      variant: 'primary',
      size: 'md',
    },
    defaultStyle: {
      display: 'inline-flex',
      padding: '12px 24px',
      backgroundColor: '#2563EB',
      color: '#ffffff',
      borderRadius: '8px',
      fontWeight: '500',
      fontSize: '14px',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // Media Components
  {
    type: 'image',
    name: 'Image',
    icon: 'Image',
    category: 'media',
    description: 'Image element',
    defaultProps: {
      src: '',
      alt: 'Image description',
    },
    defaultStyle: {
      width: '100%',
      height: 'auto',
      borderRadius: '8px',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },
  {
    type: 'video',
    name: 'Video',
    icon: 'Video',
    category: 'media',
    description: 'Video player',
    defaultProps: {
      src: '',
      controls: true,
      autoplay: false,
      loop: false,
      muted: false,
    },
    defaultStyle: {
      width: '100%',
      borderRadius: '8px',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // Utility Components
  {
    type: 'spacer',
    name: 'Spacer',
    icon: 'MoveVertical',
    category: 'utility',
    description: 'Vertical space',
    defaultProps: {
      height: '48px',
    },
    defaultStyle: {
      height: '48px',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },
  {
    type: 'divider',
    name: 'Divider',
    icon: 'Minus',
    category: 'utility',
    description: 'Horizontal line',
    defaultProps: {},
    defaultStyle: {
      width: '100%',
      height: '1px',
      backgroundColor: '#E5E7EB',
      margin: '24px 0',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // Interactive Components
  {
    type: 'icon',
    name: 'Icon',
    icon: 'Star',
    category: 'content',
    description: 'Display an icon',
    defaultProps: {
      icon: 'Star',
      size: '24',
    },
    defaultStyle: {
      display: 'inline-flex',
      alignItems: 'center',
      justifyContent: 'center',
      color: '#3B82F6',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },
  {
    type: 'icon_box',
    name: 'Icon Box',
    icon: 'SquareAsterisk',
    category: 'content',
    description: 'Icon with heading and text',
    defaultProps: {
      icon: 'Star',
      title: 'Feature Title',
      description: 'Feature description goes here',
    },
    defaultStyle: {
      display: 'flex',
      flexDirection: 'column',
      alignItems: 'center',
      textAlign: 'center',
      padding: '24px',
      gap: '12px',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },
  {
    type: 'tabs',
    name: 'Tabs',
    icon: 'LayoutList',
    category: 'interactive',
    description: 'Tabbed content panels',
    defaultProps: {
      tabs: [
        { id: 'tab1', label: 'Tab 1', content: 'Content for tab 1' },
        { id: 'tab2', label: 'Tab 2', content: 'Content for tab 2' },
        { id: 'tab3', label: 'Tab 3', content: 'Content for tab 3' },
      ],
      activeTab: 'tab1',
    },
    defaultStyle: {
      width: '100%',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },
  {
    type: 'accordion',
    name: 'Accordion',
    icon: 'ChevronDown',
    category: 'interactive',
    description: 'Collapsible content sections',
    keywords: ['faq', 'questions', 'answers', 'collapse'],
    defaultProps: {
      items: [
        { id: 'item1', title: 'Accordion Item 1', content: 'Content for item 1', isOpen: true },
        { id: 'item2', title: 'Accordion Item 2', content: 'Content for item 2', isOpen: false },
        { id: 'item3', title: 'Accordion Item 3', content: 'Content for item 3', isOpen: false },
      ],
      allowMultiple: false,
    },
    defaultStyle: {
      width: '100%',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },
  {
    type: 'social_icons',
    name: 'Social Icons',
    icon: 'Share2',
    category: 'content',
    description: 'Social media icon links',
    defaultProps: {
      icons: [
        { platform: 'facebook', url: '#' },
        { platform: 'twitter', url: '#' },
        { platform: 'instagram', url: '#' },
        { platform: 'linkedin', url: '#' },
      ],
      size: '24',
      style: 'filled',
    },
    defaultStyle: {
      display: 'flex',
      gap: '12px',
      alignItems: 'center',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },
  {
    type: 'list',
    name: 'List',
    icon: 'List',
    category: 'content',
    description: 'Bulleted or numbered list',
    defaultProps: {
      items: ['List item 1', 'List item 2', 'List item 3'],
      listType: 'bullet',
      icon: 'Check',
    },
    defaultStyle: {
      display: 'flex',
      flexDirection: 'column',
      gap: '8px',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },
  {
    type: 'counter',
    name: 'Counter',
    icon: 'Hash',
    category: 'interactive',
    description: 'Animated number counter',
    keywords: ['stats', 'metrics', 'numbers', 'kpi'],
    defaultProps: {
      startValue: '0',
      endValue: '100',
      duration: '2000',
      prefix: '',
      suffix: '',
      title: 'Counter Title',
    },
    defaultStyle: {
      textAlign: 'center',
      padding: '24px',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },
  {
    type: 'progress',
    name: 'Progress Bar',
    icon: 'BarChart3',
    category: 'interactive',
    description: 'Visual progress indicator',
    defaultProps: {
      value: '75',
      max: '100',
      label: 'Progress',
      showValue: true,
      color: '#3B82F6',
    },
    defaultStyle: {
      width: '100%',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },
  {
    type: 'testimonial',
    name: 'Testimonial',
    icon: 'Quote',
    category: 'content',
    description: 'Customer testimonial card',
    defaultProps: {
      quote: 'This is an amazing product! I highly recommend it to everyone.',
      author: 'John Doe',
      role: 'CEO, Company Inc.',
      avatar: '',
      rating: '5',
    },
    defaultStyle: {
      padding: '24px',
      backgroundColor: '#f9fafb',
      borderRadius: '8px',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },
  {
    type: 'slideshow',
    name: 'Slideshow',
    icon: 'LayoutList',
    category: 'media',
    description: 'Image carousel/slideshow',
    defaultProps: {
      slides: [
        { id: 'slide1', image: placeholderSvg(1200, 500, '#3B82F6', 'Slide 1'), title: 'Slide 1', description: 'First slide description' },
        { id: 'slide2', image: placeholderSvg(1200, 500, '#10B981', 'Slide 2'), title: 'Slide 2', description: 'Second slide description' },
        { id: 'slide3', image: placeholderSvg(1200, 500, '#F59E0B', 'Slide 3'), title: 'Slide 3', description: 'Third slide description' },
      ],
      autoplay: true,
      interval: 5000,
      showArrows: true,
      showDots: true,
      animationStyle: 'slide',
      fullWidth: false,
      height: '500px',
    },
    defaultStyle: {
      margin: '0 auto',
      display: 'block',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // =============================================================================
  // New Components (Dec 2025)
  // =============================================================================

  // Form Component
  {
    type: 'form',
    name: 'Form',
    icon: 'FileInput',
    category: 'interactive',
    description: 'Contact form, newsletter signup, or custom form',
    defaultProps: {
      formType: 'contact',
      fields: [
        { id: 'name', type: 'text', label: 'Name', placeholder: 'Your name', required: true },
        { id: 'email', type: 'email', label: 'Email', placeholder: 'your@email.com', required: true },
        { id: 'message', type: 'textarea', label: 'Message', placeholder: 'Your message...', required: false },
      ],
      submitText: 'Send Message',
      successMessage: 'Thank you! Your message has been sent.',
    },
    defaultStyle: {
      width: '100%',
      maxWidth: '500px',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // Gallery Component
  {
    type: 'gallery',
    name: 'Gallery',
    icon: 'Images',
    category: 'media',
    description: 'Image gallery with lightbox',
    defaultProps: {
      images: [
        { id: 'img1', src: placeholderSvg(400, 300, '#3B82F6', 'Image 1'), alt: 'Gallery image 1' },
        { id: 'img2', src: placeholderSvg(400, 300, '#10B981', 'Image 2'), alt: 'Gallery image 2' },
        { id: 'img3', src: placeholderSvg(400, 300, '#F59E0B', 'Image 3'), alt: 'Gallery image 3' },
        { id: 'img4', src: placeholderSvg(400, 300, '#EF4444', 'Image 4'), alt: 'Gallery image 4' },
        { id: 'img5', src: placeholderSvg(400, 300, '#8B5CF6', 'Image 5'), alt: 'Gallery image 5' },
        { id: 'img6', src: placeholderSvg(400, 300, '#EC4899', 'Image 6'), alt: 'Gallery image 6' },
      ],
      columns: 3,
      lightbox: true,
      gap: 16,
      layout: 'grid',
      imageSize: 'medium',
      aspectRatio: 'auto',
    },
    defaultStyle: {
      width: '100%',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // Map Component
  {
    type: 'map',
    name: 'Map',
    icon: 'MapPin',
    category: 'media',
    description: 'Embedded map (Google Maps, OpenStreetMap)',
    defaultProps: {
      mapType: 'embed',
      embedUrl: '',
      latitude: '14.5995',
      longitude: '120.9842',
      zoom: 14,
      markerTitle: 'Our Location',
    },
    defaultStyle: {
      width: '100%',
      height: '400px',
      borderRadius: '8px',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // Table Component
  {
    type: 'table',
    name: 'Table',
    icon: 'Table',
    category: 'content',
    description: 'Data table with headers and rows',
    defaultProps: {
      headers: ['Feature', 'Basic', 'Pro', 'Enterprise'],
      rows: [
        ['Users', '1', '10', 'Unlimited'],
        ['Storage', '1 GB', '10 GB', '100 GB'],
        ['Support', 'Email', 'Priority', '24/7 Phone'],
        ['API Access', 'No', 'Yes', 'Yes'],
      ],
      striped: true,
      bordered: true,
    },
    defaultStyle: {
      width: '100%',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // Alert Component
  {
    type: 'alert',
    name: 'Alert',
    icon: 'AlertCircle',
    category: 'utility',
    description: 'Info, success, warning, or error alert',
    defaultProps: {
      content: 'This is an important message for your visitors.',
      alertType: 'info',
      dismissible: true,
    },
    defaultStyle: {
      width: '100%',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // Anchor Component
  {
    type: 'anchor',
    name: 'Anchor',
    icon: 'Link2',
    category: 'utility',
    description: 'Invisible anchor point for jump links',
    defaultProps: {
      anchorId: 'section-1',
    },
    defaultStyle: {
      display: 'block',
      height: '0',
      visibility: 'hidden',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // Posts Grid Component
  {
    type: 'posts_grid',
    name: 'Posts Grid',
    icon: 'LayoutGrid',
    category: 'content',
    description: 'Dynamic grid of blog posts with configurable options',
    defaultProps: {
      postCount: 3,
      sourceMode: 'latest',
      postIds: [],
      categoryIds: [],
      showDate: true,
      showExcerpt: true,
      excerptLength: 120,
      showFeaturedImage: true,
      showAuthor: false,
      showReadMore: true,
      readMoreText: 'Read More',
      gridColumns: 3,
      postType: 'post',
      orderBy: 'date',
      order: 'desc',
    },
    defaultStyle: {
      display: 'grid',
      gridTemplateColumns: 'repeat(3, 1fr)',
      gap: '24px',
      width: '100%',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // Products Grid Component
  {
    type: 'products_grid',
    name: 'Products Grid',
    icon: 'ShoppingBag',
    category: 'content',
    description: 'Dynamic grid of products with pricing and filtering',
    defaultProps: {
      itemCount: 6, // Works out of the box
      categoryIds: [], // Empty = show all (intuitive)
      showImage: true, // Visual appeal
      showTitle: true,
      showExcerpt: true,
      excerptLength: 120,
      showMeta: true, // Shows price
      showAction: true, // Add to cart/view button
      actionText: 'View Product',
      gridColumns: 3,
      orderBy: 'date',
      order: 'desc',
      emptyMessage: 'No products found.',
    },
    defaultStyle: {
      display: 'grid',
      gridTemplateColumns: 'repeat(3, 1fr)',
      gap: '24px',
      width: '100%',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // Team Grid Component
  {
    type: 'team_grid',
    name: 'Team Grid',
    icon: 'Users',
    category: 'content',
    description: 'Dynamic grid of team members with roles and departments',
    defaultProps: {
      itemCount: 4, // Works out of the box
      teamType: '', // Auto-detect common team content types when left empty
      departmentIds: [], // Empty = show all (intuitive)
      showImage: true, // Visual appeal
      showTitle: true,
      showExcerpt: true, // Shows role
      excerptLength: 100,
      showMeta: false, // No date/author for team
      showAction: true, // View profile button
      gridColumns: 4,
      orderBy: 'name',
      order: 'asc',
      emptyMessage: 'No team members found.',
    },
    defaultStyle: {
      display: 'grid',
      gridTemplateColumns: 'repeat(4, 1fr)',
      gap: '24px',
      width: '100%',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },
  {
    type: 'entity_view',
    name: 'Current Entity View',
    icon: 'FileText',
    category: 'content',
    description: 'Render the current page entity using the public theme view contract',
    defaultProps: {
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
    },
    defaultStyle: {
      width: '100%',
      display: 'block',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },
  {
    type: 'entity_list',
    name: 'Entity List',
    icon: 'LayoutGrid',
    category: 'content',
    description: 'Render a themed list of entities from a content type',
    defaultProps: {
      entityType: 'post',
      _governed: true,
      source: 'cms.post.recent',
      view: 'card_grid',
      itemCount: 6,
      layout: 'grid',
      gridColumns: 3,
      showFeaturedImage: true,
      showTitle: true,
      showExcerpt: true,
      excerptLength: 120,
      showPricing: true,
      showInventory: true,
      showProgress: false,
      showActions: false,
      emptyMessage: 'No items found.',
      orderBy: 'date',
      order: 'desc',
    },
    defaultStyle: {
      display: 'grid',
      gridTemplateColumns: 'repeat(3, 1fr)',
      gap: '24px',
      width: '100%',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // =============================================================================
  // New Components (Jan 2026) - Elementor-Level Features
  // =============================================================================

  // Pricing Table Component
  {
    type: 'pricing_table',
    name: 'Pricing Table',
    icon: 'CreditCard',
    category: 'content',
    description: 'Pricing plan card with features list',
    defaultProps: {
      planName: 'Professional',
      price: '49',
      currency: '$',
      period: '/month',
      features: [
        { text: '10 Projects', included: true },
        { text: 'Unlimited Users', included: true },
        { text: 'Priority Support', included: true },
        { text: 'Custom Domain', included: false },
        { text: 'API Access', included: false },
      ],
      buttonText: 'Get Started',
      buttonUrl: '#',
      highlighted: false,
      ribbon: '',
    },
    defaultStyle: {
      padding: '32px',
      backgroundColor: '#ffffff',
      borderRadius: '16px',
      boxShadow: '0 4px 20px rgba(0,0,0,0.08)',
      textAlign: 'center',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // Countdown Timer Component
  {
    type: 'countdown',
    name: 'Countdown',
    icon: 'Timer',
    category: 'interactive',
    description: 'Countdown timer to a specific date',
    defaultProps: {
      targetDate: new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString(), // 7 days from now
      showDays: true,
      showHours: true,
      showMinutes: true,
      showSeconds: true,
      labels: { days: 'Days', hours: 'Hours', minutes: 'Minutes', seconds: 'Seconds' },
      expiredMessage: 'Event has ended!',
      style: 'boxes', // boxes, inline, flip
    },
    defaultStyle: {
      display: 'flex',
      justifyContent: 'center',
      gap: '16px',
      fontSize: '24px',
      fontWeight: '700',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // Star Rating Component
  {
    type: 'star_rating',
    name: 'Star Rating',
    icon: 'Star',
    category: 'content',
    description: 'Display star ratings for reviews',
    defaultProps: {
      rating: 4.5,
      maxRating: 5,
      showNumber: true,
      size: 'medium',
      color: '#fbbf24',
      emptyColor: '#e5e7eb',
    },
    defaultStyle: {
      display: 'inline-flex',
      alignItems: 'center',
      gap: '4px',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // Call to Action Component
  {
    type: 'call_to_action',
    name: 'Call to Action',
    icon: 'Megaphone',
    category: 'content',
    description: 'Eye-catching CTA section with title, text, and button',
    keywords: ['cta', 'banner', 'signup', 'promotion'],
    defaultProps: {
      title: 'Ready to Get Started?',
      description: 'Join thousands of satisfied customers and transform your business today.',
      buttonText: 'Start Free Trial',
      buttonUrl: '#',
      secondaryButtonText: '',
      secondaryButtonUrl: '',
      layout: 'horizontal', // horizontal, vertical, split
    },
    defaultStyle: {
      padding: '48px',
      backgroundColor: '#3b82f6',
      borderRadius: '16px',
      color: '#ffffff',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // Flip Box Component
  {
    type: 'flip_box',
    name: 'Flip Box',
    icon: 'RotateCcw',
    category: 'interactive',
    description: 'Card that flips on hover to reveal content',
    defaultProps: {
      frontIcon: 'Zap',
      frontTitle: 'Front Title',
      frontDescription: 'Hover to see more',
      backTitle: 'Back Title',
      backDescription: 'This is the back content with more details.',
      backButtonText: 'Learn More',
      backButtonUrl: '#',
      flipDirection: 'horizontal', // horizontal, vertical
    },
    defaultStyle: {
      width: '300px',
      height: '300px',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // Image Box Component
  {
    type: 'image_box',
    name: 'Image Box',
    icon: 'ImagePlus',
    category: 'content',
    description: 'Image with title and description overlay',
    keywords: ['feature', 'card', 'promo'],
    defaultProps: {
      src: '',
      alt: 'Image',
      title: 'Image Title',
      description: 'A brief description of the image.',
      titlePosition: 'below', // below, overlay, above
      linkUrl: '',
    },
    defaultStyle: {
      textAlign: 'center',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // Logo Grid Component
  {
    type: 'logo_grid',
    name: 'Logo Grid',
    icon: 'Grid3X3',
    category: 'content',
    description: 'Grid of client/partner logos',
    defaultProps: {
      logos: [
        { id: '1', src: '', alt: 'Logo 1', url: '' },
        { id: '2', src: '', alt: 'Logo 2', url: '' },
        { id: '3', src: '', alt: 'Logo 3', url: '' },
        { id: '4', src: '', alt: 'Logo 4', url: '' },
      ],
      columns: 4,
      grayscale: true,
      hoverEffect: 'color', // none, color, scale
    },
    defaultStyle: {
      display: 'grid',
      gridTemplateColumns: 'repeat(4, 1fr)',
      gap: '32px',
      alignItems: 'center',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // Blockquote Component
  {
    type: 'blockquote',
    name: 'Blockquote',
    icon: 'Quote',
    category: 'content',
    description: 'Styled quote block with citation',
    defaultProps: {
      content: 'The only way to do great work is to love what you do.',
      author: 'Steve Jobs',
      authorTitle: 'Co-founder, Apple Inc.',
      style: 'modern', // modern, classic, minimal
    },
    defaultStyle: {
      padding: '32px',
      borderLeft: '4px solid #3b82f6',
      backgroundColor: '#f8fafc',
      fontStyle: 'italic',
      fontSize: '20px',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // Toggle Component
  {
    type: 'toggle',
    name: 'Toggle',
    icon: 'ToggleLeft',
    category: 'interactive',
    description: 'Single collapsible toggle item',
    defaultProps: {
      title: 'Click to expand',
      content: 'This is the hidden content that appears when you click the toggle.',
      isOpen: false,
      icon: 'ChevronDown',
    },
    defaultStyle: {
      width: '100%',
      borderRadius: '8px',
      border: '1px solid #e5e7eb',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // Search Box Component
  {
    type: 'search_box',
    name: 'Search Box',
    icon: 'Search',
    category: 'content',
    description: 'Search input with customizable styling',
    keywords: ['finder', 'query', 'lookup', 'site search'],
    defaultProps: {
      placeholder: 'Search...',
      buttonText: 'Search',
      showButton: true,
      searchUrl: '/cms/search',
      style: 'rounded', // rounded, square, pill
    },
    defaultStyle: {
      maxWidth: '500px',
      width: '100%',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // Theme-style widget components
  {
    type: 'nav_menu',
    name: 'Navigation Menu',
    icon: 'Navigation',
    category: 'content',
    description: 'Widget-style navigation menu rendered from a CMS menu ID',
    keywords: ['widget', 'menu', 'navigation', 'footer', 'sidebar', 'links'],
    defaultProps: {
      title: 'Browse',
      menuId: 0,
    },
    defaultStyle: {
      ...themeWidgetCardStyle,
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },
  {
    type: 'recent_posts',
    name: 'Recent Posts',
    icon: 'FileText',
    category: 'content',
    description: 'Compact latest-posts widget for content rails and footers',
    keywords: ['widget', 'posts', 'blog', 'recent', 'sidebar', 'footer'],
    defaultProps: {
      title: 'Latest Posts',
      count: 5,
      sourceMode: 'latest',
      postIds: [],
      categoryIds: [],
      orderBy: 'date',
      showDate: true,
      showThumbnail: false,
      showExcerpt: false,
    },
    defaultStyle: {
      ...themeWidgetCardStyle,
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },
  {
    type: 'social_links',
    name: 'Site Social Links',
    icon: 'Share2',
    category: 'content',
    description: 'Auto-render social links from CMS site settings',
    keywords: ['widget', 'social', 'footer', 'header', 'sidebar', 'icons'],
    defaultProps: {
      title: 'Follow Us',
      displayStyle: 'icons',
    },
    defaultStyle: {
      ...themeWidgetCardStyle,
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },
  {
    type: 'contact_info',
    name: 'Contact Info',
    icon: 'MapPin',
    category: 'content',
    description: 'Widget-style contact details block for address, phone, and email',
    keywords: ['widget', 'contact', 'address', 'phone', 'email', 'footer', 'sidebar'],
    defaultProps: {
      title: 'Contact Info',
      address: '123 Market Street, Manila',
      phone: '+63 900 000 0000',
      email: 'hello@example.com',
    },
    defaultStyle: {
      ...themeWidgetCardStyle,
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },
  {
    type: 'categories',
    name: 'Categories',
    icon: 'List',
    category: 'content',
    description: 'Category list widget — supports blog posts or products',
    keywords: ['widget', 'categories', 'blog', 'taxonomy', 'sidebar', 'products', 'ecommerce'],
    defaultProps: {
      title: 'Categories',
      module: 'post',
      count: 8,
      showCount: true,
      orderBy: 'name',
      showEmpty: false,
    },
    defaultStyle: {
      ...themeWidgetCardStyle,
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },
  {
    type: 'tag_cloud',
    name: 'Tag Cloud',
    icon: 'Hash',
    category: 'content',
    description: 'Popular tags widget rendered as a compact cloud of labels',
    keywords: ['widget', 'tags', 'tag cloud', 'blog', 'sidebar'],
    defaultProps: {
      title: 'Popular Tags',
      count: 16,
      orderBy: 'count',
    },
    defaultStyle: {
      ...themeWidgetCardStyle,
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },
  {
    type: 'archives',
    name: 'Archives',
    icon: 'Clock',
    category: 'content',
    description: 'Archive links by month and year with optional counts',
    keywords: ['widget', 'archives', 'blog', 'months', 'sidebar'],
    defaultProps: {
      title: 'Archives',
      count: 6,
      showCount: true,
      orderBy: 'date_desc',
    },
    defaultStyle: {
      ...themeWidgetCardStyle,
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },
  {
    type: 'opening_hours',
    name: 'Opening Hours',
    icon: 'Clock',
    category: 'content',
    description: 'Compact business-hours widget for top bars, footers, and contact areas',
    keywords: ['widget', 'hours', 'business', 'footer', 'header', 'sidebar'],
    defaultProps: {
      title: 'Opening Hours',
      text: 'Mon-Fri, 9:00 AM - 6:00 PM',
      showIcon: true,
    },
    defaultStyle: {
      ...themeWidgetCardStyle,
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // Badge Component
  {
    type: 'badge',
    name: 'Badge',
    icon: 'Hash',
    category: 'utility',
    description: 'Small pill label for status, promos, and highlights',
    keywords: ['label', 'tag', 'pill', 'status', 'highlight'],
    defaultProps: {
      text: 'Featured',
      variant: 'primary',
      size: 'md',
    },
    defaultStyle: {
      display: 'inline-flex',
      alignItems: 'center',
      justifyContent: 'center',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // Stat Card Component
  {
    type: 'stat_card',
    name: 'Stat Card',
    icon: 'BarChart3',
    category: 'content',
    description: 'Single KPI or stat highlight card',
    keywords: ['stats', 'metric', 'numbers', 'kpi', 'counter'],
    defaultProps: {
      value: '128',
      label: 'Happy Customers',
      description: 'A quick metric you want visitors to notice immediately.',
      accentColor: '#0f172a',
    },
    defaultStyle: {
      padding: '24px',
      border: '1px solid #e5e7eb',
      borderRadius: '16px',
      backgroundColor: '#ffffff',
      width: '100%',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // Contact Card Component
  {
    type: 'contact_card',
    name: 'Contact Card',
    icon: 'MapPin',
    category: 'content',
    description: 'Compact business contact card with CTA',
    keywords: ['contact', 'phone', 'email', 'address', 'location'],
    defaultProps: {
      title: 'Let\'s Talk',
      description: 'Share your project, request a quote, or visit our studio.',
      phone: '+63 900 000 0000',
      email: 'hello@example.com',
      address: '123 Market Street, Manila',
      buttonText: 'Contact Us',
      buttonUrl: '/cms/contact',
    },
    defaultStyle: {
      padding: '24px',
      border: '1px solid #e5e7eb',
      borderRadius: '16px',
      backgroundColor: '#ffffff',
      width: '100%',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // Breadcrumbs Component
  {
    type: 'breadcrumbs',
    name: 'Breadcrumbs',
    icon: 'ChevronRight',
    category: 'utility',
    description: 'Navigation breadcrumb trail',
    defaultProps: {
      items: [
        { label: 'Home', url: '/' },
        { label: 'Products', url: '/products' },
        { label: 'Current Page', url: '' },
      ],
      separator: '/',
      showHome: true,
      homeIcon: 'Home',
    },
    defaultStyle: {
      fontSize: '14px',
      color: '#6b7280',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // Code Block Component
  {
    type: 'code_block',
    name: 'Code Block',
    icon: 'Code',
    category: 'content',
    description: 'Syntax-highlighted code snippet',
    defaultProps: {
      code: 'const greeting = "Hello, World!";\nconsole.log(greeting);',
      language: 'javascript',
      showLineNumbers: true,
      showCopyButton: true,
      theme: 'dark', // dark, light
    },
    defaultStyle: {
      borderRadius: '8px',
      overflow: 'hidden',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // Audio Component
  {
    type: 'audio',
    name: 'Audio Player',
    icon: 'Volume2',
    category: 'media',
    description: 'Audio player with controls',
    defaultProps: {
      src: '',
      title: '',
      artist: '',
      autoplay: false,
      loop: false,
      controls: true,
    },
    defaultStyle: {
      width: '100%',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },

  // HTML Embed Component
  {
    type: 'html_embed',
    name: 'HTML Embed',
    icon: 'Code2',
    category: 'utility',
    description: 'Embed raw HTML or iframe code',
    defaultProps: {
      html: '',
    },
    defaultStyle: {
      width: '100%',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },
  {
    type: 'ai_block',
    name: 'AI Text Block',
    icon: 'Sparkles',
    category: 'content',
    description: 'AI-generated text block. Compose a prompt, click Generate, and the result is captured into the block. Re-generate any time.',
    defaultProps: {
      // Author-time prompt (composed in PropertiesPanel).
      prompt: 'Write a short marketing intro paragraph for our homepage hero. Keep it under 60 words.',
      // Provider tier preference (passed to ai.text.generate@1).
      preferred_tier: 'free',
      max_tokens: 320,
      temperature: 0.5,
      // Frozen output captured at author-time. This is what gets rendered publicly.
      // Empty by default; populated by the Generate button.
      content: '',
      // Hash of the prompt at the time content was generated. Lets the panel
      // warn the author if the prompt has been edited but not re-generated.
      generated_prompt_hash: '',
      // ISO timestamp of last successful generation (for the editor indicator).
      generated_at: '',
    },
    defaultStyle: {
      width: '100%',
      padding: '16px 0',
      lineHeight: '1.6',
      color: '#1f2937',
    },
    allowedChildren: [],
    allowedParents: null,
    isLeaf: true,
  },
];

// =============================================================================
// Component Registry
// =============================================================================

export const COMPONENT_REGISTRY: Record<ComponentType, ComponentDefinition> =
  CMS_COMPONENTS.reduce((acc, comp) => {
    acc[comp.type] = comp;
    return acc;
  }, {} as Record<ComponentType, ComponentDefinition>);

// =============================================================================
// Component Categories
// =============================================================================

/**
 * Components hidden from the component drawer
 * - document: Root wrapper, auto-created
 * - row/column: Legacy components, replaced by flex presets
 */
const HIDDEN_COMPONENTS: ComponentType[] = ['document', 'row', 'column'];

/**
 * Filter function to get user-visible components
 */
function getVisibleComponents(components: ComponentDefinition[]): ComponentDefinition[] {
  return components.filter(c => !HIDDEN_COMPONENTS.includes(c.type));
}

export const COMPONENT_CATEGORIES = [
  {
    id: 'layout',
    name: 'Layout',
    icon: 'LayoutGrid',
    // Hide document, row, column from user - they use presets instead
    components: getVisibleComponents(CMS_COMPONENTS.filter(c => c.category === 'layout')),
  },
  {
    id: 'content',
    name: 'Content',
    icon: 'Type',
    components: CMS_COMPONENTS.filter(c => c.category === 'content'),
  },
  {
    id: 'media',
    name: 'Media',
    icon: 'Image',
    components: CMS_COMPONENTS.filter(c => c.category === 'media'),
  },
  {
    id: 'interactive',
    name: 'Interactive',
    icon: 'MousePointerClick',
    components: CMS_COMPONENTS.filter(c => c.category === 'interactive'),
  },
  {
    id: 'utility',
    name: 'Utility',
    icon: 'Wrench',
    components: CMS_COMPONENTS.filter(c => c.category === 'utility'),
  },
];

// =============================================================================
// Helper Functions
// =============================================================================

export function getComponentDefinition(type: ComponentType): ComponentDefinition | undefined {
  return COMPONENT_REGISTRY[type];
}

let cachedGovernedNesting: Record<string, BuilderNestingRule> | null | undefined;

function governedNestingConstraints(): Record<string, BuilderNestingRule> | null {
  if (cachedGovernedNesting !== undefined) {
    return cachedGovernedNesting;
  }
  cachedGovernedNesting = getBootData().builderConstraints?.nesting ?? null;
  return cachedGovernedNesting;
}

function governedNestingRule(type: ComponentType): BuilderNestingRule | null {
  const constraints = governedNestingConstraints();
  if (!constraints) {
    return null;
  }
  return constraints[type] ?? null;
}

export function canHaveChildren(type: ComponentType): boolean {
  const def = getComponentDefinition(type);
  return def ? !def.isLeaf : false;
}

export function canAcceptChild(parentType: ComponentType, childType: ComponentType): boolean {
  const governedParent = governedNestingRule(parentType);
  if (Array.isArray(governedParent?.allowed_children)) {
    return governedParent.allowed_children.includes(childType);
  }
  const parentDef = getComponentDefinition(parentType);
  if (!parentDef) return false;
  if (parentDef.isLeaf) return false;
  if (parentDef.allowedChildren === null) return true;
  return parentDef.allowedChildren?.includes(childType) ?? false;
}

export function canBeChildOf(childType: ComponentType, parentType: ComponentType): boolean {
  const governedChild = governedNestingRule(childType);
  if (Array.isArray(governedChild?.allowed_parents)) {
    return governedChild.allowed_parents.includes(parentType);
  }
  const childDef = getComponentDefinition(childType);
  if (!childDef) return false;
  if (childDef.allowedParents === null) return true;
  return childDef.allowedParents?.includes(parentType) ?? false;
}
