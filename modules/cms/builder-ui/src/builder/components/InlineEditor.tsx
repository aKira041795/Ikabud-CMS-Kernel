/**
 * Ikabud Page Builder - Inline Rich Text Editor
 * TinyMCE-based editor with enhanced formatting:
 * - Bold, Italic
 * - Paragraph/Heading blocks (H1-H6)
 * - Text alignment (left, center, right)
 * - Lists (numbered, bullets)
 * - Links
 * - Code view (HTML source)
 */

import React, { memo, useCallback, useRef, useEffect } from 'react';
import { Editor } from '@tinymce/tinymce-react';

// =============================================================================
// Types
// =============================================================================

interface InlineEditorProps {
  content: string;
  onSave: (content: string) => void;
  onCancel: () => void;
  placeholder?: string;
  style?: React.CSSProperties;
}

// =============================================================================
// Inline Editor Component
// =============================================================================

const InlineEditor: React.FC<InlineEditorProps> = memo(({
  content,
  onSave,
  onCancel,
  placeholder = 'Start typing...',
  style = {},
}) => {
  const editorRef = useRef<any>(null);
  const containerRef = useRef<HTMLDivElement>(null);

  // Handle save on blur
  const handleBlur = useCallback(() => {
    if (editorRef.current) {
      const newContent = editorRef.current.getContent();
      onSave(newContent);
    }
  }, [onSave]);

  // Handle keyboard shortcuts
  const handleKeyDown = useCallback((e: KeyboardEvent) => {
    if (e.key === 'Escape') {
      e.preventDefault();
      onCancel();
    }
  }, [onCancel]);

  // Focus editor on mount
  useEffect(() => {
    const timer = setTimeout(() => {
      if (editorRef.current) {
        editorRef.current.focus();
      }
    }, 100);
    return () => clearTimeout(timer);
  }, []);

  return (
    <div 
      ref={containerRef}
      style={{ ...style, minHeight: '1em' }}
      onClick={(e) => e.stopPropagation()}
      onKeyDown={(e) => {
        // Stop propagation for all keyboard events to prevent parent handlers
        // from intercepting backspace, delete, and other editing keys
        e.stopPropagation();
        handleKeyDown(e.nativeEvent);
      }}
    >
      <Editor
        tinymceScriptSrc="/assets/cms/tinymce/tinymce.min.js"
        licenseKey="gpl"
        onInit={(_evt, editor) => {
          editorRef.current = editor;
        }}
        initialValue={content || ''}
        init={{
          inline: true,
          menubar: false,
          statusbar: false,
          placeholder: placeholder,
          
          // Enhanced toolbar with lists, alignment, paragraph, and code view
          toolbar: 'blocks | bold italic | alignleft aligncenter alignright | bullist numlist | link | code',
          
          // Required plugins for enhanced features
          plugins: ['link', 'lists', 'code'],
          
          // Block formats for paragraph styling
          block_formats: 'Paragraph=p; Heading 1=h1; Heading 2=h2; Heading 3=h3; Heading 4=h4; Heading 5=h5; Heading 6=h6',
          
          // Valid elements - include lists and alignment
          valid_elements: 'p[style],h1[style],h2[style],h3[style],h4[style],h5[style],h6[style],br,strong/b,em/i,a[href|target|rel],ul,ol,li,span[style]',
          
          // Valid styles for alignment
          valid_styles: {
            '*': 'text-align'
          },
          
          // Force paste as plain text
          paste_as_text: false,
          
          // Minimal UI
          toolbar_mode: 'floating',
          toolbar_location: 'top',
          
          // Auto-focus
          auto_focus: true,
          
          // Content styling
          content_style: `
            body {
              font-family: inherit;
              font-size: inherit;
              line-height: inherit;
              color: inherit;
              margin: 0;
              padding: 0;
            }
            p { margin: 0 0 0.5em 0; }
            ul, ol { margin: 0.5em 0; padding-left: 1.5em; }
            li { margin: 0.25em 0; }
            h1, h2, h3, h4, h5, h6 { margin: 0 0 0.5em 0; }
          `,
          
          // Handle blur
          setup: (editor) => {
            editor.on('blur', () => {
              // Delay blur to allow toolbar/dialog clicks
              setTimeout(() => {
                // Check if focus is still within TinyMCE UI
                const activeElement = document.activeElement;
                if (activeElement && (
                  activeElement.closest('.tox-toolbar') || 
                  activeElement.closest('.tox-dialog') ||
                  activeElement.closest('.tox-tinymce-inline')
                )) {
                  return;
                }
                handleBlur();
              }, 100);
            });
            editor.on('keydown', (e) => {
              if (e.key === 'Escape') {
                e.preventDefault();
                onCancel();
              }
            });
          },
        }}
      />
    </div>
  );
});

InlineEditor.displayName = 'InlineEditor';

export default InlineEditor;
