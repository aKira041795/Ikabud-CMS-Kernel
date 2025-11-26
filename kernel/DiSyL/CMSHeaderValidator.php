<?php
/**
 * DiSyL CMS Header Validator v0.7.0
 * 
 * Validates {ikb_cms} header declarations
 * Integrates with Grammar v1.2.0 for validation
 * 
 * @version 0.7.0
 */

namespace IkabudKernel\Core\DiSyL;

class CMSHeaderValidator
{
    /**
     * Validate CMS header declaration
     * 
     * @param array|null $cmsHeader CMS header from AST
     * @param array $ast Full AST for position validation
     * @return array Array of validation errors (empty if valid)
     */
    public static function validate(?array $cmsHeader, array $ast): array
    {
        $errors = [];
        
        // If no header, that's valid (optional)
        if ($cmsHeader === null) {
            return $errors;
        }
        
        // Use Grammar's CMS declaration validation
        $grammar = new Grammar();
        $grammarErrors = $grammar->validateCMSDeclaration([
            'type' => $cmsHeader['type'] ?? null,
            'set' => isset($cmsHeader['sets']) ? implode(',', $cmsHeader['sets']) : null,
        ]);
        
        $errors = array_merge($errors, $grammarErrors);
        
        // Also validate with CMSLoader for backward compatibility
        if (!isset($cmsHeader['type']) || empty($cmsHeader['type'])) {
            // Already handled by Grammar
        } elseif (!CMSLoader::isValidCMSType($cmsHeader['type'])) {
            $errors[] = sprintf(
                'Invalid CMS type "%s". Valid types: %s',
                $cmsHeader['type'],
                implode(', ', CMSLoader::getValidCMSTypes())
            );
        }
        
        // Validate 'set' attribute if present
        if (isset($cmsHeader['sets']) && is_array($cmsHeader['sets'])) {
            foreach ($cmsHeader['sets'] as $set) {
                if (!CMSLoader::isValidSet($set)) {
                    $errors[] = sprintf(
                        'Invalid set "%s". Valid sets: %s',
                        $set,
                        implode(', ', CMSLoader::getValidSets())
                    );
                }
            }
        }
        
        // Validate position: must be first non-comment, non-whitespace node
        $errors = array_merge($errors, self::validatePosition($ast));
        
        // Remove duplicates
        return array_unique($errors);
    }
    
    /**
     * Validate that CMS header is at the beginning of the document
     * 
     * @param array $ast Full AST
     * @return array Validation errors
     */
    private static function validatePosition(array $ast): array
    {
        $errors = [];
        
        if (!isset($ast['children']) || empty($ast['children'])) {
            return $errors;
        }
        
        $foundHeader = false;
        $foundContent = false;
        
        foreach ($ast['children'] as $child) {
            // Skip whitespace text nodes
            if ($child['type'] === 'text' && trim($child['value']) === '') {
                continue;
            }
            
            // Skip comments
            if ($child['type'] === 'comment') {
                continue;
            }
            
            // Check if this is the CMS header tag
            if ($child['type'] === 'tag' && ($child['name'] ?? '') === 'ikb_cms') {
                if ($foundContent) {
                    $errors[] = 'CMS header declaration must appear at the beginning of the file (found after other content)';
                }
                $foundHeader = true;
                continue;
            }
            
            // Any other content
            $foundContent = true;
            
            // If we found content before header, that's an error
            if (!$foundHeader && $foundContent) {
                // This is handled by the parser, but double-check here
                break;
            }
        }
        
        return $errors;
    }
    
    /**
     * Validate CMS type string
     * 
     * @param string $cmsType CMS type to validate
     * @return bool True if valid
     */
    public static function isValidCMSType(string $cmsType): bool
    {
        return CMSLoader::isValidCMSType($cmsType);
    }
    
    /**
     * Validate set name
     * 
     * @param string $set Set name to validate
     * @return bool True if valid
     */
    public static function isValidSet(string $set): bool
    {
        return CMSLoader::isValidSet($set);
    }
    
    /**
     * Get validation summary
     * 
     * @param array|null $cmsHeader CMS header
     * @param array $ast Full AST
     * @return array Summary with 'valid' boolean and 'errors' array
     */
    public static function getSummary(?array $cmsHeader, array $ast): array
    {
        $errors = self::validate($cmsHeader, $ast);
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'has_header' => $cmsHeader !== null,
            'cms_type' => $cmsHeader['type'] ?? null,
            'sets' => $cmsHeader['sets'] ?? []
        ];
    }
}
