/**
 * Ikabud Page Builder - Animation Definitions
 * CSS keyframes and animation classes for entrance and hover effects
 */

// Entrance Animation Keyframes
export const entranceAnimations = {
  fadeIn: `
    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }
  `,
  fadeInUp: `
    @keyframes fadeInUp {
      from { opacity: 0; transform: translateY(30px); }
      to { opacity: 1; transform: translateY(0); }
    }
  `,
  fadeInDown: `
    @keyframes fadeInDown {
      from { opacity: 0; transform: translateY(-30px); }
      to { opacity: 1; transform: translateY(0); }
    }
  `,
  fadeInLeft: `
    @keyframes fadeInLeft {
      from { opacity: 0; transform: translateX(-30px); }
      to { opacity: 1; transform: translateX(0); }
    }
  `,
  fadeInRight: `
    @keyframes fadeInRight {
      from { opacity: 0; transform: translateX(30px); }
      to { opacity: 1; transform: translateX(0); }
    }
  `,
  zoomIn: `
    @keyframes zoomIn {
      from { opacity: 0; transform: scale(0.8); }
      to { opacity: 1; transform: scale(1); }
    }
  `,
  slideInUp: `
    @keyframes slideInUp {
      from { transform: translateY(100%); }
      to { transform: translateY(0); }
    }
  `,
  slideInDown: `
    @keyframes slideInDown {
      from { transform: translateY(-100%); }
      to { transform: translateY(0); }
    }
  `,
  slideInLeft: `
    @keyframes slideInLeft {
      from { transform: translateX(-100%); }
      to { transform: translateX(0); }
    }
  `,
  slideInRight: `
    @keyframes slideInRight {
      from { transform: translateX(100%); }
      to { transform: translateX(0); }
    }
  `,
  bounceIn: `
    @keyframes bounceIn {
      0% { opacity: 0; transform: scale(0.3); }
      50% { opacity: 1; transform: scale(1.05); }
      70% { transform: scale(0.9); }
      100% { transform: scale(1); }
    }
  `,
  flipInX: `
    @keyframes flipInX {
      from { opacity: 0; transform: perspective(400px) rotateX(90deg); }
      to { opacity: 1; transform: perspective(400px) rotateX(0); }
    }
  `,
  flipInY: `
    @keyframes flipInY {
      from { opacity: 0; transform: perspective(400px) rotateY(90deg); }
      to { opacity: 1; transform: perspective(400px) rotateY(0); }
    }
  `,
};

// Hover Animation Styles
export const hoverAnimations = {
  grow: `
    transition: transform 0.3s ease;
    &:hover { transform: scale(1.05); }
  `,
  shrink: `
    transition: transform 0.3s ease;
    &:hover { transform: scale(0.95); }
  `,
  lift: `
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    &:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1); }
  `,
  pulse: `
    &:hover {
      animation: pulse 1s infinite;
    }
    @keyframes pulse {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.05); }
    }
  `,
  float: `
    transition: transform 0.3s ease;
    &:hover { transform: translateY(-5px); }
  `,
  bob: `
    &:hover {
      animation: bob 0.5s ease-in-out infinite;
    }
    @keyframes bob {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-8px); }
    }
  `,
  shake: `
    &:hover {
      animation: shake 0.5s ease-in-out;
    }
    @keyframes shake {
      0%, 100% { transform: translateX(0); }
      25% { transform: translateX(-5px); }
      75% { transform: translateX(5px); }
    }
  `,
  glow: `
    transition: box-shadow 0.3s ease;
    &:hover { box-shadow: 0 0 20px rgba(59, 130, 246, 0.5); }
  `,
  shadowGrow: `
    transition: box-shadow 0.3s ease, transform 0.3s ease;
    &:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
    }
  `,
};

// Generate CSS for a node's animations
export function generateAnimationCSS(
  nodeId: string,
  entranceAnimation?: string,
  animationDuration?: string,
  animationDelay?: string,
  hoverAnimation?: string
): string {
  let css = '';
  
  // Entrance animation
  if (entranceAnimation && entranceAnimations[entranceAnimation as keyof typeof entranceAnimations]) {
    css += entranceAnimations[entranceAnimation as keyof typeof entranceAnimations];
    css += `
      [data-node-id="${nodeId}"] {
        animation: ${entranceAnimation} ${animationDuration || '0.6s'} ease-out ${animationDelay || '0s'} forwards;
      }
    `;
  }
  
  // Hover animation (simplified for inline styles)
  if (hoverAnimation) {
    const hoverStyles: Record<string, { transform?: string; boxShadow?: string; animation?: string }> = {
      grow: { transform: 'scale(1.05)' },
      shrink: { transform: 'scale(0.95)' },
      lift: { transform: 'translateY(-5px)', boxShadow: '0 10px 20px rgba(0, 0, 0, 0.1)' },
      float: { transform: 'translateY(-10px)' },
      pulse: { animation: 'pulse 0.5s ease-in-out' },
      bob: { animation: 'bob 1s ease-in-out infinite' },
      shake: { animation: 'shake 0.5s ease-in-out' },
      glow: { boxShadow: '0 0 20px rgba(0, 120, 212, 0.6), 0 0 40px rgba(0, 120, 212, 0.4)' },
      shadowGrow: { transform: 'translateY(-2px)', boxShadow: '0 15px 30px rgba(0, 0, 0, 0.3)' },
    };
    
    if (hoverStyles[hoverAnimation]) {
      const styles = hoverStyles[hoverAnimation];
      css += `
        [data-node-id="${nodeId}"] {
          transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        [data-node-id="${nodeId}"]:hover {
          ${styles.transform ? `transform: ${styles.transform};` : ''}
          ${styles.boxShadow ? `box-shadow: ${styles.boxShadow};` : ''}
          ${styles.animation ? `animation: ${styles.animation};` : ''}
        }
      `;
    }
  }
  
  return css;
}

// Generate all animation CSS for a document
export function generateDocumentAnimationCSS(document: { 
  id: string; 
  props: Record<string, unknown>; 
  children: Array<{ id: string; props: Record<string, unknown>; children: unknown[] }> 
}): string {
  let css = '';
  
  function processNode(node: { id: string; props: Record<string, unknown>; children: unknown[] }) {
    const { entranceAnimation, animationDuration, animationDelay, hoverAnimation } = node.props;
    
    if (entranceAnimation || hoverAnimation) {
      css += generateAnimationCSS(
        node.id,
        entranceAnimation as string,
        animationDuration as string,
        animationDelay as string,
        hoverAnimation as string
      );
    }
    
    // Process children recursively
    if (node.children && Array.isArray(node.children)) {
      node.children.forEach((child) => processNode(child as { id: string; props: Record<string, unknown>; children: unknown[] }));
    }
  }
  
  processNode(document as { id: string; props: Record<string, unknown>; children: unknown[] });
  
  return css;
}

export default {
  entranceAnimations,
  hoverAnimations,
  generateAnimationCSS,
  generateDocumentAnimationCSS,
};
