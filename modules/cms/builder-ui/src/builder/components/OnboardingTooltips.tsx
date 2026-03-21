/**
 * Onboarding Tooltips
 * First-time user guidance for the Page Builder
 */

import React, { useState, useEffect, useCallback } from 'react';
import { X, ChevronRight, ChevronLeft, Sparkles } from 'lucide-react';

interface TooltipStep {
  id: string;
  target: string; // CSS selector or element ID
  title: string;
  content: string;
  position: 'top' | 'bottom' | 'left' | 'right';
}

const ONBOARDING_STEPS: TooltipStep[] = [
  {
    id: 'welcome',
    target: '.canvas-area',
    title: 'Welcome to the Page Builder! 🎉',
    content: 'Build beautiful pages by dragging elements from the left panel onto the canvas.',
    position: 'bottom',
  },
  {
    id: 'components',
    target: '[data-tab="components"]',
    title: 'Component Library',
    content: 'Drag elements like headings, text, images, and buttons to build your page.',
    position: 'right',
  },
  {
    id: 'templates',
    target: '[data-tab="templates"]',
    title: 'Pre-built Templates',
    content: 'Start faster with ready-made sections like heroes, features, and CTAs.',
    position: 'right',
  },
  {
    id: 'layers',
    target: '[data-tab="layers"]',
    title: 'Layers Panel',
    content: 'See your page structure and easily select nested elements.',
    position: 'right',
  },
  {
    id: 'properties',
    target: '.properties-panel',
    title: 'Properties Panel',
    content: 'Customize selected elements - change content, styles, and advanced settings.',
    position: 'left',
  },
  {
    id: 'viewport',
    target: '.viewport-switcher',
    title: 'Responsive Preview',
    content: 'Preview your page on desktop, tablet, and mobile devices.',
    position: 'bottom',
  },
  {
    id: 'save',
    target: '.save-button',
    title: 'Save Your Work',
    content: 'Your changes auto-save every 30 seconds, but you can save manually anytime.',
    position: 'bottom',
  },
];

const STORAGE_KEY = 'ikabud_builder_onboarding_completed';

interface OnboardingTooltipsProps {
  enabled?: boolean;
  onComplete?: () => void;
}

const OnboardingTooltips: React.FC<OnboardingTooltipsProps> = ({
  enabled = true,
  onComplete,
}) => {
  const [currentStep, setCurrentStep] = useState(0);
  const [isVisible, setIsVisible] = useState(false);
  const [position, setPosition] = useState({ top: 0, left: 0 });

  // Check if onboarding was already completed
  useEffect(() => {
    if (!enabled) return;
    
    const completed = localStorage.getItem(STORAGE_KEY);
    if (!completed) {
      // Delay showing to let the UI render
      const timer = setTimeout(() => setIsVisible(true), 1000);
      return () => clearTimeout(timer);
    }
  }, [enabled]);

  // Update tooltip position based on target element
  useEffect(() => {
    if (!isVisible) return;

    const step = ONBOARDING_STEPS[currentStep];
    const targetEl = document.querySelector(step.target);
    
    if (targetEl) {
      const rect = targetEl.getBoundingClientRect();
      const tooltipWidth = 320;
      const tooltipHeight = 150;
      const padding = 12;

      let top = 0;
      let left = 0;

      switch (step.position) {
        case 'top':
          top = rect.top - tooltipHeight - padding;
          left = rect.left + rect.width / 2 - tooltipWidth / 2;
          break;
        case 'bottom':
          top = rect.bottom + padding;
          left = rect.left + rect.width / 2 - tooltipWidth / 2;
          break;
        case 'left':
          top = rect.top + rect.height / 2 - tooltipHeight / 2;
          left = rect.left - tooltipWidth - padding;
          break;
        case 'right':
          top = rect.top + rect.height / 2 - tooltipHeight / 2;
          left = rect.right + padding;
          break;
      }

      // Keep within viewport
      top = Math.max(10, Math.min(top, window.innerHeight - tooltipHeight - 10));
      left = Math.max(10, Math.min(left, window.innerWidth - tooltipWidth - 10));

      setPosition({ top, left });
    }
  }, [currentStep, isVisible]);

  const handleNext = useCallback(() => {
    if (currentStep < ONBOARDING_STEPS.length - 1) {
      setCurrentStep(prev => prev + 1);
    } else {
      handleComplete();
    }
  }, [currentStep]);

  const handlePrev = useCallback(() => {
    if (currentStep > 0) {
      setCurrentStep(prev => prev - 1);
    }
  }, [currentStep]);

  const handleComplete = useCallback(() => {
    localStorage.setItem(STORAGE_KEY, 'true');
    setIsVisible(false);
    onComplete?.();
  }, [onComplete]);

  const handleSkip = useCallback(() => {
    handleComplete();
  }, [handleComplete]);

  if (!isVisible || !enabled) return null;

  const step = ONBOARDING_STEPS[currentStep];
  const isLastStep = currentStep === ONBOARDING_STEPS.length - 1;
  const isFirstStep = currentStep === 0;

  return (
    <>
      {/* Backdrop */}
      <div 
        className="fixed inset-0 bg-black/40 z-[9998]"
        onClick={handleSkip}
      />
      
      {/* Tooltip */}
      <div
        className="fixed z-[9999] w-80 bg-[#252526] border border-[#3c3c3c] shadow-2xl animate-in fade-in slide-in-from-bottom-2 duration-200"
        style={{ top: position.top, left: position.left }}
      >
        {/* Header */}
        <div className="flex items-center justify-between px-4 py-3 border-b border-[#3c3c3c]">
          <div className="flex items-center gap-2">
            <Sparkles className="w-4 h-4 text-amber-400" />
            <span className="text-xs text-white/50">
              Step {currentStep + 1} of {ONBOARDING_STEPS.length}
            </span>
          </div>
          <button
            onClick={handleSkip}
            className="p-1 hover:bg-white/10 transition-colors"
            title="Skip tour"
          >
            <X className="w-4 h-4 text-white/50" />
          </button>
        </div>

        {/* Content */}
        <div className="px-4 py-4">
          <h3 className="text-sm font-medium text-white mb-2">{step.title}</h3>
          <p className="text-sm text-white/70 leading-relaxed">{step.content}</p>
        </div>

        {/* Footer */}
        <div className="flex items-center justify-between px-4 py-3 border-t border-[#3c3c3c]">
          <button
            onClick={handleSkip}
            className="text-xs text-white/50 hover:text-white/70 transition-colors"
          >
            Skip tour
          </button>
          <div className="flex items-center gap-2">
            {!isFirstStep && (
              <button
                onClick={handlePrev}
                className="flex items-center gap-1 px-3 py-1.5 text-sm text-white/70 hover:text-white hover:bg-white/10 transition-colors"
              >
                <ChevronLeft className="w-4 h-4" />
                Back
              </button>
            )}
            <button
              onClick={handleNext}
              className="flex items-center gap-1 px-3 py-1.5 bg-[#0078d4] text-white text-sm hover:bg-[#006cbd] transition-colors"
            >
              {isLastStep ? 'Get Started' : 'Next'}
              {!isLastStep && <ChevronRight className="w-4 h-4" />}
            </button>
          </div>
        </div>

        {/* Progress dots */}
        <div className="flex items-center justify-center gap-1.5 pb-3">
          {ONBOARDING_STEPS.map((_, index) => (
            <div
              key={index}
              className={`w-1.5 h-1.5 rounded-full transition-colors ${
                index === currentStep ? 'bg-[#0078d4]' : 'bg-white/20'
              }`}
            />
          ))}
        </div>
      </div>
    </>
  );
};

// Helper to reset onboarding (for testing)
export const resetOnboarding = () => {
  localStorage.removeItem(STORAGE_KEY);
};

export default OnboardingTooltips;
